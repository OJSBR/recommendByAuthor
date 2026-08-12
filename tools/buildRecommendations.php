<?php

/**
 * @file plugins/generic/recommendByAuthor/tools/buildRecommendations.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Builds the recommendations of a whole journal in one controlled pass.
 *
 * The scheduled task fills the cache a slice at a time, which is the right
 * behaviour for a live site but means a large journal takes hours to be fully
 * covered. This script does the same work in one go, so that it can be run
 * deliberately -- at night, or before the plugin is switched on -- instead of
 * being spread over the day.
 *
 * Usage (run as the account that owns the files, never as root):
 *
 *   php plugins/generic/recommendByAuthor/tools/buildRecommendations.php
 *   ... --context=1        only one journal (default: every journal)
 *   ... --limit=500        stop after this many submissions, for a first test
 *   ... --batch=50         submissions per transaction (default 50)
 *   ... --pause=200        milliseconds to wait between batches, to leave the
 *                          database room to serve readers (default 0)
 *   ... --stale-only       only submissions that are queued or out of date
 *   ... --status           print coverage and exit
 */

use APP\core\Application;
use APP\core\PageRouter;
use PKP\context\Context;
use PKP\core\PKPRequest;
use APP\plugins\generic\recommendByAuthor\classes\AuthorIndex;
use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\plugins\generic\recommendByAuthor\RecommendByAuthorPlugin;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

$options = getopt('', ['context::', 'limit::', 'batch::', 'pause::', 'stale-only', 'status', 'help']);
if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1800), "\n";
    exit(0);
}

// On the web a journal comes from the URL. On the command line there is no URL,
// so the router has no context, the plugin registry cannot tell which journal to
// read settings for, and the plugin comes back as if it were not installed. A
// router with a fixed context is what the rest of OJS assumes it has.
class RecommendByAuthorCliRouter extends PageRouter
{
    private ?Context $context = null;

    public function pinContext(?Context $context): void
    {
        $this->context = $context;
    }

    public function getContext(PKPRequest $request, bool $forceReload = false): ?Context
    {
        return $this->context;
    }
}

$request = Application::get()->getRequest();
$router = new RecommendByAuthorCliRouter();
$router->setApplication(Application::get());
$contextDao = Application::getContextDAO();
$router->pinContext(
    isset($options['context'])
        ? $contextDao->getById((int) $options['context'])
        : $contextDao->getAll(true)->toArray()[0] ?? null
);
$request->setRouter($router);

// Every plugin, not only the enabled ones: the cache can legitimately be built
// before the plugin is switched on, which is the safe order on a busy site.
PluginRegistry::loadCategory('generic', false);
/** @var RecommendByAuthorPlugin $plugin */
$plugin = PluginRegistry::getPlugin('generic', 'recommendbyauthorplugin');
if (!$plugin) {
    fwrite(STDERR, "The recommendByAuthor plugin is not installed.\n");
    exit(1);
}
if (!$plugin->getEnabled()) {
    echo "Note: the plugin is not enabled yet; building the cache anyway.\n";
}

$store = new RecommendationStore();
$index = new AuthorIndex((bool) $plugin->getPluginSetting('matchByOrcid'));

$status = function () use ($store) {
    $total = DB::table('recommend_author_state')->count();
    $pending = DB::table('recommend_author_state')->whereNull('computed_at')->count();
    printf("enrolled: %d   computed: %d   queued: %d   cached rows: %d\n",
        $total, $total - $pending, $pending, DB::table('recommend_author_cache')->count());
};

if (isset($options['status'])) {
    $status();
    exit(0);
}

$contextId = isset($options['context']) ? (int) $options['context'] : null;
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$batchSize = max(1, (int) ($options['batch'] ?? 50));
$pause = max(0, (int) ($options['pause'] ?? 0)) * 1000;
$staleOnly = isset($options['stale-only']);

echo "Enrolling published submissions...\n";
$contextIds = $plugin->enabledContextIds()
    ?: array_map(fn ($c) => (int) $c->getId(), $contextDao->getAll(true)->toArray());
if (isset($options['context'])) {
    $contextIds = array_intersect($contextIds, [(int) $options['context']]) ?: [(int) $options['context']];
}
$enrolled = 0;
while ($added = $store->enqueueNew($contextIds, 5000, (int) $plugin->getPluginSetting('queueLimit'))) {
    $enrolled += $added;
}
echo "  {$enrolled} newly enrolled.\n";

// The index has to be complete before anything is computed from it, or the
// lists come out missing the articles that had not been read yet.
echo "Indexing authors...\n";
$t = microtime(true);
$indexed = 0;
while ($n = $store->indexPending($index, 5000)) {
    $indexed += $n;
    printf("\r  %d indexed   ", $indexed);
}
printf("\r  %d indexed in %.1f s.\n", $indexed, microtime(true) - $t);

$query = DB::table('recommend_author_state');
if ($contextId) {
    $query->where('context_id', $contextId);
}
if ($staleOnly) {
    $maxAge = (int) $plugin->getPluginSetting('maxAgeDays');
    $query->where(fn ($q) => $q->whereNull('computed_at')->orWhere('computed_at', '<', now()->subDays($maxAge)));
}
$targets = $query->orderByRaw('(computed_at IS NULL) DESC')->orderBy('computed_at')
    ->select('submission_id', 'context_id')->get();
if ($limit > 0) {
    $targets = $targets->take($limit);
}

$byContext = [];
foreach ($targets as $row) {
    $byContext[(int) $row->context_id][] = (int) $row->submission_id;
}

$max = (int) $plugin->getPluginSetting('maxRecommendations');
$orderBy = (string) $plugin->getPluginSetting('orderBy');
$done = 0;
$started = microtime(true);
foreach ($byContext as $context => $submissionIds) {
    printf("Journal %d: %d submission(s)\n", $context, count($submissionIds));
    $ranking = $store->ranking($context, $orderBy, (int) $plugin->getPluginSetting('rankingTtlHours'));

    foreach (array_chunk($submissionIds, $batchSize) as $chunk) {
        $store->refresh($chunk, $index, $ranking, $max);
        $done += count($chunk);
        printf("\r  %d/%d (%.1f/s)   ", $done, count($targets), $done / max(0.001, microtime(true) - $started));
        if ($pause) {
            usleep($pause);
        }
    }
    echo "\n";
}

printf("Done: %d submission(s) in %.1f s.\n", $done, microtime(true) - $started);
$status();
