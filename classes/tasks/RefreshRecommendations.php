<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/tasks/RefreshRecommendations.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RefreshRecommendations
 *
 * @brief Recomputes a slice of the recommendations on every run.
 *
 * The slice is what matters. Recommendations go stale as articles are
 * published, so they have to be refreshed; refreshing them all at once would
 * be the very stampede the cache exists to avoid. Each run takes the
 * submissions that were never computed, then the ones computed longest ago,
 * up to a batch size the journal sets. Nothing ever expires at the same
 * moment, and the work per run is bounded whatever the size of the journal.
 */

namespace APP\plugins\generic\recommendByAuthor\classes\tasks;

use APP\plugins\generic\recommendByAuthor\classes\AuthorIndex;
use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\plugins\generic\recommendByAuthor\RecommendByAuthorPlugin;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class RefreshRecommendations extends ScheduledTask
{
    /** Stop taking on submissions after this long, so a run never outlives the cron slot. */
    private const TIME_BUDGET_SECONDS = 120;

    /** Submissions indexed per run. Indexing is cheap; this is a safety rail, not a pace. */
    private const INDEX_BATCH = 5000;

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.generic.recommendByAuthor.task.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    protected function executeActions(): bool
    {
        /** @var RecommendByAuthorPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'recommendbyauthorplugin');
        if (!$plugin || !($contextIds = $plugin->enabledContextIds())) {
            return true;
        }

        $store = new RecommendationStore();
        $index = new AuthorIndex((bool) $plugin->getPluginSetting('matchByOrcid'));

        $enqueued = $store->enqueueNew($contextIds, 5000, (int) $plugin->getPluginSetting('queueLimit'));

        // Indexing first, and to completion. Computing recommendations from a
        // half-built index stores lists that are missing articles, and stores
        // them as if they were finished; the article would have to wait a full
        // refresh cycle to be corrected. Indexing is fast enough that this
        // costs at most a run or two even on a large journal.
        $indexed = $store->indexPending($index, self::INDEX_BATCH);
        if ($remaining = $store->pendingIndexCount()) {
            $this->addExecutionLogEntry(
                __('plugins.generic.recommendByAuthor.task.indexing', ['indexed' => $indexed, 'remaining' => $remaining]),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
            );
            return true;
        }

        $due = $store->due($contextIds, (int) $plugin->getPluginSetting('batchSize'), (int) $plugin->getPluginSetting('maxAgeDays'));

        $deadline = time() + self::TIME_BUDGET_SECONDS;
        $refreshed = 0;
        foreach ($due as $contextId => $submissionIds) {
            $ranking = $store->ranking(
                $contextId,
                (string) $plugin->getPluginSetting('orderBy'),
                (int) $plugin->getPluginSetting('rankingTtlHours')
            );

            foreach (array_chunk($submissionIds, 50) as $chunk) {
                $store->refresh($chunk, $index, $ranking, (int) $plugin->getPluginSetting('maxRecommendations'));
                $refreshed += count($chunk);
                if (time() >= $deadline) {
                    break 2;
                }
            }
        }

        $this->addExecutionLogEntry(
            __('plugins.generic.recommendByAuthor.task.result', ['refreshed' => $refreshed, 'queued' => $enqueued]),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
        );

        return true;
    }
}
