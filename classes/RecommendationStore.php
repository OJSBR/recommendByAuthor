<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/RecommendationStore.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendationStore
 *
 * @brief Reads and writes the materialised recommendations.
 *
 * Everything the article page needs is a primary-key read; everything that
 * costs a query over the whole journal happens in the scheduled task, in
 * batches, and never for all submissions at once.
 */

namespace APP\plugins\generic\recommendByAuthor\classes;

use APP\core\Application;
use APP\submission\Submission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecommendationStore
{
    public const ORDER_BY_METRIC = 'metric';
    public const ORDER_BY_DATE = 'date';

    /**
     * Enrols published submissions that have never been seen before.
     *
     * Bounded on purpose: a journal that has just installed the plugin fills
     * its queue over a few runs rather than in one statement.
     */
    public function enqueueNew(array $contextIds, int $limit = 5000, int $queueLimit = 0): int
    {
        if (!$contextIds) {
            return 0;
        }


        // A journal trying the plugin out can cap how many submissions are ever
        // enrolled, so the experiment stays the size it was meant to be.
        if ($queueLimit > 0) {
            $room = $queueLimit - DB::table('recommend_author_state')->count();
            if ($room <= 0) {
                return 0;
            }
            $limit = min($limit, $room);
        }

        $missing = DB::table('submissions as s')
            ->leftJoin('recommend_author_state as state', 'state.submission_id', '=', 's.submission_id')
            ->where('s.status', Submission::STATUS_PUBLISHED)
            ->whereIn('s.context_id', $contextIds)
            ->whereNull('state.submission_id')
            ->limit($limit)
            ->select('s.submission_id', 's.context_id')
            ->get();

        $rows = $missing->map(fn ($row) => [
            'submission_id' => $row->submission_id,
            'context_id' => $row->context_id,
            'indexed_at' => null,
            'computed_at' => null,
            'version' => 1,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('recommend_author_state')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /**
     * Enrols one submission, for the on-demand path.
     */
    public function enqueueOne(int $submissionId, int $contextId): void
    {
        DB::table('recommend_author_state')->insertOrIgnore([[
            'submission_id' => $submissionId,
            'context_id' => $contextId,
            'indexed_at' => null,
            'computed_at' => null,
            'version' => 1,
        ]]);
    }

    /**
     * Indexes the submissions whose authors have not been read yet.
     *
     * This has to finish before anything is computed: an article can only be
     * recommended once it is in the index, so a list computed while the index
     * is half built would be missing articles and would still be stored as
     * final. Indexing is the cheap half -- a few thousand submissions a second
     * -- so it does not need to be spread out the way computing does.
     *
     * @return int how many submissions were indexed
     */
    public function indexPending(AuthorIndex $index, int $limit = 5000): int
    {
        $submissionIds = DB::table('recommend_author_state')
            ->whereNull('indexed_at')
            ->limit($limit)
            ->pluck('submission_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$submissionIds) {
            return 0;
        }

        $index->index($submissionIds);
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            DB::table('recommend_author_state')->whereIn('submission_id', $chunk)->update(['indexed_at' => now()]);
        }

        return count($submissionIds);
    }

    /**
     * How many submissions are still waiting to be indexed. While this is
     * above zero, nothing may be computed.
     */
    public function pendingIndexCount(): int
    {
        return DB::table('recommend_author_state')->whereNull('indexed_at')->count();
    }

    /**
     * Marks submissions whose own authors may have changed, so that they are
     * read again before anything is computed from them.
     */
    public function markForReindex(array $submissionIds): void
    {
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            DB::table('recommend_author_state')->whereIn('submission_id', $chunk)->update(['indexed_at' => null]);
        }
    }

    /**
     * The next submissions to compute: never-computed ones first, then the
     * ones computed longest ago.
     *
     * This is what keeps a refresh from ever being a stampede -- there is no
     * moment at which everything expires, only a window that rolls forward.
     *
     * @return array context id => submission ids
     */
    public function due(array $contextIds, int $limit, int $maxAgeDays): array
    {
        if (!$contextIds) {
            return [];
        }

        $rows = DB::table('recommend_author_state')
            ->whereIn('context_id', $contextIds)
            ->where(function ($query) use ($maxAgeDays) {
                $query->whereNull('computed_at')
                    ->orWhere('computed_at', '<', now()->subDays($maxAgeDays));
            })
            ->orderByRaw('(computed_at IS NULL) DESC')
            ->orderBy('computed_at')
            ->limit($limit)
            ->select('submission_id', 'context_id')
            ->get();

        $byContext = [];
        foreach ($rows as $row) {
            $byContext[(int) $row->context_id][] = (int) $row->submission_id;
        }

        return $byContext;
    }

    /**
     * Recomputes the recommendations of the given submissions of one context.
     *
     * @param array $ranking submission id => sort value, highest shown first
     */
    public function refresh(array $submissionIds, AuthorIndex $index, array $ranking, int $max): void
    {
        // Re-read the authors of these submissions first: they are the ones
        // whose own metadata is most likely to have changed since last time.
        $index->index($submissionIds);
        $related = $index->relatedToMany($submissionIds);

        foreach (array_chunk($submissionIds, 50) as $chunk) {
            $rows = [];
            foreach ($chunk as $submissionId) {
                foreach ($this->rank($related[$submissionId] ?? [], $ranking, $max) as $seq => $recommendedId) {
                    $rows[] = [
                        'submission_id' => $submissionId,
                        'seq' => $seq,
                        'recommended_submission_id' => $recommendedId,
                    ];
                }
            }

            DB::transaction(function () use ($chunk, $rows) {
                DB::table('recommend_author_cache')->whereIn('submission_id', $chunk)->delete();
                foreach (array_chunk($rows, 500) as $insert) {
                    DB::table('recommend_author_cache')->insert($insert);
                }
                DB::table('recommend_author_state')
                    ->whereIn('submission_id', $chunk)
                    ->update(['indexed_at' => now(), 'computed_at' => now(), 'version' => DB::raw('version + 1')]);
            });
        }
    }

    /**
     * Orders the candidates the way the reader sees them: by the journal's
     * chosen measure, then by how many authors the two articles share, then by
     * id so that paging is stable for articles with no measure at all.
     *
     * @return array seq => submission id
     */
    private function rank(array $candidates, array $ranking, int $max): array
    {
        $ids = array_keys($candidates);
        usort($ids, function ($a, $b) use ($candidates, $ranking) {
            return [$ranking[$b] ?? 0, $candidates[$b], $a] <=> [$ranking[$a] ?? 0, $candidates[$a], $b];
        });

        return array_slice($ids, 0, $max);
    }

    /**
     * The sort value of every submission of a context, in one query.
     *
     * The original plugin asks the statistics service for the same numbers on
     * every page view, filtered by the handful of submissions it just found --
     * about two seconds per view on a journal the size of RECIMA21. Asked once
     * for the whole journal and kept for a few hours, it costs a fraction of
     * that per day: the ranking moves slowly, and an article does not change
     * places because of the last hour of downloads.
     *
     * @return array submission id => sort value
     */
    public function ranking(int $contextId, string $orderBy, int $ttlHours = 12): array
    {
        if ($orderBy === self::ORDER_BY_DATE) {
            return $this->dateRanking($contextId);
        }

        return Cache::remember(
            "recommendByAuthor:ranking:{$contextId}",
            now()->addHours(max(1, $ttlHours)),
            fn () => $this->metricRanking($contextId)
        );
    }

    /**
     * Total views and downloads per submission -- the same measure, and the
     * same assoc types, the original plugin orders by.
     */
    private function metricRanking(int $contextId): array
    {
        $rows = DB::table('metrics_submission')
            ->where('context_id', $contextId)
            ->whereIn('assoc_type', [Application::ASSOC_TYPE_SUBMISSION, Application::ASSOC_TYPE_SUBMISSION_FILE])
            ->groupBy('submission_id')
            ->select('submission_id', DB::raw('SUM(metric) as value'))
            ->get();

        $ranking = [];
        foreach ($rows as $row) {
            $ranking[(int) $row->submission_id] = (int) $row->value;
        }

        return $ranking;
    }

    /**
     * Newest first. Free of the statistics tables altogether, for journals that
     * would rather not pay for the aggregate at all.
     */
    private function dateRanking(int $contextId): array
    {
        $rows = DB::table('submissions as s')
            ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->where('s.context_id', $contextId)
            ->where('s.status', Submission::STATUS_PUBLISHED)
            ->select('s.submission_id', 'p.date_published')
            ->get();

        $ranking = [];
        foreach ($rows as $row) {
            $ranking[(int) $row->submission_id] = $row->date_published ? strtotime($row->date_published) : 0;
        }

        return $ranking;
    }

    /**
     * @return array submission ids, in display order
     */
    public function read(int $submissionId, int $offset, int $limit): array
    {
        return DB::table('recommend_author_cache')
            ->where('submission_id', $submissionId)
            ->orderBy('seq')
            ->offset($offset)
            ->limit($limit)
            ->pluck('recommended_submission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function total(int $submissionId): int
    {
        return DB::table('recommend_author_cache')->where('submission_id', $submissionId)->count();
    }

    public function stateOf(int $submissionId): ?object
    {
        return DB::table('recommend_author_state')->where('submission_id', $submissionId)->first();
    }

    /**
     * Marks submissions for recomputation at the next run, and makes the
     * rendered HTML of the current version unreachable.
     */
    public function invalidate(array $submissionIds): void
    {
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            DB::table('recommend_author_state')
                ->whereIn('submission_id', $chunk)
                ->update(['computed_at' => null, 'version' => DB::raw('version + 1')]);
        }
    }
}
