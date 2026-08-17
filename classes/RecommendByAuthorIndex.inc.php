<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/RecommendByAuthorIndex.inc.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendByAuthorIndex
 *
 * @brief Maintains recommend_author_index: which author identities appear in
 *        which published submission.
 *
 * The original plugin asks the question the other way round, once per page
 * view: "which authors are named like this one?". That has no index to stand
 * on -- names live in author_settings, whose only keys are on author_id -- so
 * MySQL scans the whole settings table, twice per author of the article being
 * read. Writing the answer down once, keyed by author identity, turns the same
 * question into a primary-key lookup.
 */


use Illuminate\Database\Capsule\Manager as Capsule;

// STATUS_PUBLISHED is a define() inside Submission, not a class constant,
// and 3.3 loads it lazily: without this the scheduled task fails on the CLI,
// where nothing else has pulled the class in yet.
import('classes.submission.Submission');

class RecommendByAuthorIndex
{
    /** Author settings that can identify an author across submissions. */
    private const SETTINGS = ['givenName', 'familyName', 'orcid'];

    public function __construct(private bool $matchByOrcid = true)
    {
    }

    /**
     * Rewrites the index entries of the given submissions.
     *
     * Submissions that are no longer published simply end up with no entries,
     * which is what removes them from everybody else's recommendations.
     */
    public function index(array $submissionIds): void
    {
        if (!$submissionIds) {
            return;
        }

        $authors = $this->authorsOf($submissionIds);
        $settings = $this->settingsOf(array_keys($authors));

        $entries = [];
        foreach ($authors as $authorId => $author) {
            foreach ($this->keysOf($settings[$authorId] ?? []) as $key) {
                // The same key twice in one submission (an author listed twice,
                // or a name identical in two locales) is one entry.
                $entries[$key . "\0" . $author->submission_id] = [
                    'author_key' => $key,
                    'submission_id' => $author->submission_id,
                    'context_id' => $author->context_id,
                ];
            }
        }

        Capsule::connection()->transaction(function () use ($submissionIds, $entries) {
            foreach (array_chunk($submissionIds, 500) as $chunk) {
                Capsule::table('recommend_author_index')->whereIn('submission_id', $chunk)->delete();
            }
            foreach (array_chunk(array_values($entries), 500) as $chunk) {
                Capsule::table('recommend_author_index')->insert($chunk);
            }
        });
    }

    /**
     * The submissions that share an author with the given one, most shared
     * authors first, within the same context.
     *
     * @return array submission id => number of authors in common
     */
    public function relatedTo(int $submissionId): array
    {
        $rows = Capsule::table('recommend_author_index as source')
            ->join('recommend_author_index as related', function ($join) {
                $join->on('related.author_key', '=', 'source.author_key')
                    ->on('related.context_id', '=', 'source.context_id')
                    ->on('related.submission_id', '!=', 'source.submission_id');
            })
            ->where('source.submission_id', $submissionId)
            ->groupBy('related.submission_id')
            ->select('related.submission_id', Capsule::raw('COUNT(*) as shared'))
            ->get();

        $related = [];
        foreach ($rows as $row) {
            $related[(int) $row->submission_id] = (int) $row->shared;
        }

        return $related;
    }

    /**
     * The same question for a batch of submissions, in one query, so that the
     * scheduled task pays for one round trip per batch instead of one per
     * submission.
     *
     * @return array submission id => [related submission id => authors in common]
     */
    public function relatedToMany(array $submissionIds): array
    {
        $related = array_fill_keys($submissionIds, []);
        foreach (array_chunk($submissionIds, 50) as $chunk) {
            $rows = Capsule::table('recommend_author_index as source')
                ->join('recommend_author_index as related', function ($join) {
                    $join->on('related.author_key', '=', 'source.author_key')
                        ->on('related.context_id', '=', 'source.context_id')
                        ->on('related.submission_id', '!=', 'source.submission_id');
                })
                ->whereIn('source.submission_id', $chunk)
                ->groupBy('source.submission_id', 'related.submission_id')
                ->select('source.submission_id', 'related.submission_id as related_id', Capsule::raw('COUNT(*) as shared'))
                ->get();
            foreach ($rows as $row) {
                $related[(int) $row->submission_id][(int) $row->related_id] = (int) $row->shared;
            }
        }

        return $related;
    }

    /**
     * The submissions whose recommendations may have changed because the given
     * submissions changed: everyone who shares an author with them.
     */
    public function neighboursOf(array $submissionIds): array
    {
        if (!$submissionIds) {
            return [];
        }

        return Capsule::table('recommend_author_index as source')
            ->join('recommend_author_index as related', function ($join) {
                $join->on('related.author_key', '=', 'source.author_key')
                    ->on('related.context_id', '=', 'source.context_id');
            })
            ->whereIn('source.submission_id', $submissionIds)
            ->distinct()
            ->pluck('related.submission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The authors of the current publication of each published submission.
     *
     * @return array author id => row with submission_id and context_id
     */
    private function authorsOf(array $submissionIds): array
    {
        $authors = [];
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            $rows = Capsule::table('submissions as s')
                ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
                ->join('authors as a', 'a.publication_id', '=', 'p.publication_id')
                ->whereIn('s.submission_id', $chunk)
                ->where('s.status', STATUS_PUBLISHED)
                ->select('a.author_id', 's.submission_id', 's.context_id')
                ->get();
            foreach ($rows as $row) {
                $authors[(int) $row->author_id] = $row;
            }
        }

        return $authors;
    }

    /**
     * @return array author id => [setting name => [locale => value]]
     */
    private function settingsOf(array $authorIds): array
    {
        $settings = [];
        foreach (array_chunk($authorIds, 1000) as $chunk) {
            $rows = Capsule::table('author_settings')
                ->whereIn('author_id', $chunk)
                ->whereIn('setting_name', self::SETTINGS)
                ->select('author_id', 'locale', 'setting_name', 'setting_value')
                ->get();
            foreach ($rows as $row) {
                $settings[(int) $row->author_id][$row->setting_name][$row->locale] = (string) $row->setting_value;
            }
        }

        return $settings;
    }

    /**
     * Every key one author answers to: the name in each locale it was recorded
     * in, plus the ORCID when there is one.
     *
     * Names are keyed per locale rather than only in the submission language:
     * an author whose name is written "Muñoz" in Spanish and "Munoz" in English
     * is one person, and either spelling should find both articles. The ORCID
     * is an additional key, never a replacement, so switching the matching on
     * can only add articles -- it cannot hide the ones matched by name.
     */
    private function keysOf(array $settings): array
    {
        $keys = [];

        $given = $settings['givenName'] ?? [];
        $family = $settings['familyName'] ?? [];
        foreach (array_keys($given + $family) as $locale) {
            if ($key = RecommendByAuthorKey::fromName($given[$locale] ?? null, $family[$locale] ?? null)) {
                $keys[$key] = true;
            }
        }

        if ($this->matchByOrcid) {
            foreach ($settings['orcid'] ?? [] as $orcid) {
                if ($key = RecommendByAuthorKey::fromOrcid($orcid)) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
