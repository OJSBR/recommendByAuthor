<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/tasks/RefreshAuthorRecommendations.inc.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RefreshAuthorRecommendations
 * @brief Recomputes a slice of the recommendations on every run.
 *
 * The slice is what matters. Recommendations go stale as articles are
 * published, so they have to be refreshed; refreshing them all at once would
 * be the very stampede the cache exists to avoid. Each run takes the
 * submissions that were never computed, then the ones computed longest ago,
 * up to a batch size the journal sets. Nothing ever expires at the same
 * moment, and the work per run is bounded whatever the size of the journal.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('plugins.generic.recommendByAuthor.classes.RecommendByAuthorKey');
import('plugins.generic.recommendByAuthor.classes.RecommendByAuthorIndex');
import('plugins.generic.recommendByAuthor.classes.RecommendByAuthorStore');

class RefreshAuthorRecommendations extends ScheduledTask {
	/**
	 * How long a run may take on the command line, where it has a cron slot to
	 * itself.
	 */
	const TIME_BUDGET_CLI = 120;

	/**
	 * How long it may take when the task runs from a web request instead.
	 *
	 * In 3.3 that is the acron plugin, which runs pending tasks in a shutdown
	 * function at the end of a request. The reader already has the page by
	 * then, but the PHP-FPM worker is still busy -- and on this server each
	 * pool has only five. Refreshing a smaller slice more often is the right
	 * trade: the queue rolls forward either way, just in smaller steps.
	 */
	const TIME_BUDGET_WEB = 10;

	/** Submissions indexed per run. Indexing is cheap; this is a safety rail, not a pace. */
	const INDEX_BATCH = 5000;

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	function getName() {
		return __('plugins.generic.recommendByAuthor.task.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	protected function executeActions() {
		$plugin = PluginRegistry::getPlugin('generic', 'recommendbyauthorplugin');
		if (!$plugin) return true;
		$contextIds = $plugin->enabledContextIds();
		if (!$contextIds) return true;

		$store = new RecommendByAuthorStore();
		$index = new RecommendByAuthorIndex((bool) $plugin->getPluginSetting('matchByOrcid'));

		$enqueued = $store->enqueueNew($contextIds, 5000, (int) $plugin->getPluginSetting('queueLimit'));

		// Indexing first, and to completion. Computing recommendations from a
		// half-built index stores lists that are missing articles, and stores
		// them as if they were finished; the article would have to wait a full
		// refresh cycle to be corrected. Indexing is fast enough that this
		// costs at most a run or two even on a large journal.
		$indexed = $store->indexPending($index, self::INDEX_BATCH);
		if ($remaining = $store->pendingIndexCount()) {
			$this->addExecutionLogEntry(
				__('plugins.generic.recommendByAuthor.task.indexing', array('indexed' => $indexed, 'remaining' => $remaining)),
				SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
			);
			return true;
		}

		$due = $store->due($contextIds, (int) $plugin->getPluginSetting('batchSize'), (int) $plugin->getPluginSetting('maxAgeDays'));

		$deadline = time() + (PHP_SAPI === 'cli' ? self::TIME_BUDGET_CLI : self::TIME_BUDGET_WEB);
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
				if (time() >= $deadline) break 2;
			}
		}

		$this->addExecutionLogEntry(
			__('plugins.generic.recommendByAuthor.task.result', array('refreshed' => $refreshed, 'queued' => $enqueued)),
			SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
		);

		return true;
	}
}
