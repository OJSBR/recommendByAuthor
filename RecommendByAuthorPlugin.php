<?php

/**
 * @file plugins/generic/recommendByAuthor/RecommendByAuthorPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendByAuthorPlugin
 *
 * @brief Plugin to recommend articles from the same author.
 *
 * Same feature as the original plugin, computed somewhere else. The article
 * page reads a list that is already written down; a scheduled task keeps that
 * list current, a slice per run. See classes/AuthorIndex.php for why the
 * original is expensive and classes/tasks/RefreshRecommendations.php for why
 * the refresh is never a stampede.
 */

namespace APP\plugins\generic\recommendByAuthor;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\recommendByAuthor\classes\AuthorIndex;
use APP\plugins\generic\recommendByAuthor\classes\migration\install\SchemaMigration;
use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\plugins\generic\recommendByAuthor\classes\tasks\RefreshRecommendations;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\NotificationManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class RecommendByAuthorPlugin extends GenericPlugin implements HasTaskScheduler
{
    /**
     * Kept for the journals that read it out of the old plugin; the number of
     * recommendations per page is a setting now.
     */
    public const RECOMMEND_BY_AUTHOR_PLUGIN_COUNT = 10;

    /**
     * The defaults are the safe ones: a submission that has not been computed
     * yet shows nothing at all rather than computing itself while a reader
     * waits (computeOnDemand), and each run of the task does a bounded amount
     * of work (batchSize) whatever the size of the journal.
     */
    public const DEFAULTS = [
        'recommendationCount' => 10,
        'maxRecommendations' => 50,
        'batchSize' => 250,
        'queueLimit' => 0,
        'maxAgeDays' => 7,
        'orderBy' => RecommendationStore::ORDER_BY_METRIC,
        'rankingTtlHours' => 12,
        'matchByOrcid' => 1,
        'computeOnDemand' => 0,
        'htmlCacheHours' => 168,
        // Bumped whenever the settings are saved. It is part of the key of the
        // rendered HTML, so that changing "recommendations per page" shows up
        // on the next page view instead of whenever the cache happens to
        // expire -- there is no other way to reach those keys, one per
        // submission, locale and page.
        'cacheStamp' => 1,
    ];

    //
    // Implement template methods from Plugin.
    //
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }

        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('Templates::Article::Footer::PageFooter', $this->callbackTemplateArticlePageFooter(...));

            // What is published, withdrawn or deleted changes the recommendations
            // of everyone who shares an author with it, so those are queued for
            // the next run instead of waiting for their turn to come round.
            Hook::add('Publication::publish', $this->invalidateFromPublication(...));
            Hook::add('Publication::unpublish', $this->invalidateFromPublication(...));
            Hook::add('Publication::delete', $this->invalidateFromPublication(...));
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration(): SchemaMigration
    {
        return new SchemaMigration();
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        if (!$this->enabledContextIds()) {
            return;
        }

        $scheduler
            ->addSchedule(new RefreshRecommendations([]))
            ->everyFifteenMinutes()
            ->name(RefreshRecommendations::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.recommendByAuthor.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.recommendByAuthor.description');
    }

    /**
     * A setting of this plugin, or its default.
     *
     * The plugin is site-wide, as it always was, so the settings live with the
     * site rather than with each journal.
     *
     * @return mixed
     */
    public function getPluginSetting(string $name)
    {
        $value = $this->getSetting($this->settingsContextId(), $name);

        return $value === null || $value === '' ? self::DEFAULTS[$name] : $value;
    }

    /**
     * Application::SITE_CONTEXT_ID is null rather than zero, so this is
     * nullable: passing it on as an int would store the settings of the site
     * under journal 0, which no journal ever reads.
     */
    public function settingsContextId(): ?int
    {
        return $this->isSitePlugin() ? Application::SITE_CONTEXT_ID : $this->getCurrentContextId();
    }

    /**
     * The journals this plugin is enabled for.
     *
     * The scheduled task runs with no journal in the request -- there is no URL
     * to derive one from -- so getEnabled() has nothing to answer about and the
     * task would either skip every journal or work on journals that never
     * asked for it. Reading the settings directly is the only thing that is
     * true regardless of who is asking.
     *
     * @return int[]
     */
    public function enabledContextIds(): array
    {
        $enabled = DB::table('plugin_settings')
            ->where('plugin_name', $this->getName())
            ->where('setting_name', 'enabled')
            ->whereIn('setting_value', ['1', 'true'])
            ->pluck('context_id');

        // A row with no journal means the plugin was enabled for the whole
        // site, which stands for every journal rather than for journal zero.
        if ($enabled->contains(null)) {
            return Application::getContextDAO()->getAll(true)
                ->map(fn ($context) => (int) $context->getId())
                ->toArray();
        }

        return $enabled->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new RecommendByAuthorSettingsForm($this);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    //
    // View level hook implementations.
    //
    /**
     * Add content to the article footer.
     */
    public function callbackTemplateArticlePageFooter($hookName, $params): bool
    {
        $smarty = & $params[1];
        $output = & $params[2];

        $submission = $smarty->getTemplateVars('article');
        if (!$submission instanceof Submission) {
            return Hook::CONTINUE;
        }

        $submissionId = $submission->getId();
        $store = new RecommendationStore();
        $state = $store->stateOf($submissionId);

        if (!$state || $state->computed_at === null) {
            // Not computed yet. Either the journal accepts the cost of doing it
            // while the reader waits, or -- the default -- the section is left
            // out until the scheduled task gets to this submission.
            if (!$this->getPluginSetting('computeOnDemand')) {
                return Hook::CONTINUE;
            }
            $state = $this->computeNow($store, $submissionId, $submission->getData('contextId'));
        }

        $request = Application::get()->getRequest();
        $rangeInfo = Handler::getRangeInfo($request, 'articlesBySameAuthor');
        $page = $rangeInfo && $rangeInfo->isValid() ? $rangeInfo->getPage() : 1;

        $key = implode(':', [
            'recommendByAuthor', 'html', $submissionId, $state->version,
            $this->getPluginSetting('cacheStamp'), Locale::getLocale(), $page,
        ]);
        $html = Cache::remember(
            $key,
            now()->addHours((int) $this->getPluginSetting('htmlCacheHours')),
            fn () => $this->render($store, $submissionId, $page)
        );

        $output .= $html;

        return Hook::CONTINUE;
    }

    /**
     * Queues the changed submission and everything that shares an author with
     * it. Marking is all this does -- the recomputation is the task's job, so
     * publishing an article never turns into a long wait for the editor.
     */
    public function invalidateFromPublication(string $hookName, array $args): bool
    {
        $publication = $args[0];
        $submissionId = (int) $publication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        $index = new AuthorIndex((bool) $this->getPluginSetting('matchByOrcid'));
        $store = new RecommendationStore();

        // Its own authors have to be read again; its neighbours only have to be
        // computed again, from an index that already holds them.
        $store->markForReindex([$submissionId]);
        $store->invalidate(array_unique(array_merge([$submissionId], $index->neighboursOf([$submissionId]))));

        return Hook::CONTINUE;
    }

    /**
     * Computes one submission on the spot, for the journals that turned
     * computeOnDemand on.
     */
    private function computeNow(RecommendationStore $store, int $submissionId, int $contextId): object
    {
        $store->enqueueOne($submissionId, $contextId);
        $store->indexPending(new AuthorIndex((bool) $this->getPluginSetting('matchByOrcid')), 500);
        $store->refresh(
            [$submissionId],
            new AuthorIndex((bool) $this->getPluginSetting('matchByOrcid')),
            $store->ranking($contextId, (string) $this->getPluginSetting('orderBy'), (int) $this->getPluginSetting('rankingTtlHours')),
            (int) $this->getPluginSetting('maxRecommendations')
        );

        return $store->stateOf($submissionId);
    }

    /**
     * The section as the reader sees it, built from the stored list.
     *
     * The hook hands over a Smarty_Internal_Template, not the manager, so the
     * variables are assigned through the manager itself -- the same thing the
     * recommendBySimilarity plugin does.
     */
    private function render(RecommendationStore $store, int $submissionId, int $page): string
    {
        $perPage = max(1, (int) $this->getPluginSetting('recommendationCount'));
        $offset = ($page - 1) * $perPage;

        $recommendedIds = $store->read($submissionId, $offset, $perPage);
        if (!$recommendedIds) {
            return '';
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        // The collector refuses to run without a context, and its result is a
        // lazy collection: walking it more than once would run the query again,
        // so it is read into an array here, once.
        $byId = [];
        foreach (
            Repo::submission()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterBySubmissionIds($recommendedIds)
                ->getMany() as $recommended
        ) {
            $byId[$recommended->getId()] = $recommended;
        }

        // The stored order is the display order; the collector's is not.
        $ordered = [];
        foreach ($recommendedIds as $recommendedId) {
            if (isset($byId[$recommendedId])) {
                $ordered[] = $byId[$recommendedId];
            }
        }
        if (!$ordered) {
            return '';
        }

        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByIssueIds(array_values(array_unique(array_filter(array_map(
                fn (Submission $submission) => $submission->getCurrentPublication()?->getData('issueId'),
                $ordered
            )))))
            ->getMany();

        $total = $store->total($submissionId);
        $templateManager = TemplateManager::getManager($request);
        $templateManager->assign('articlesBySameAuthor', (object) [
            'submissions' => $ordered,
            'issues' => $issues,
            'start' => $offset + 1,
            'end' => $offset + count($ordered),
            'total' => $total,
            'previousUrl' => $page > 1 ? $this->pageUrl($request, $submissionId, $page - 1) : null,
            'nextUrl' => $offset + $perPage < $total ? $this->pageUrl($request, $submissionId, $page + 1) : null,
        ]);

        return $templateManager->fetch($this->getTemplateResource('articleFooter.tpl'));
    }

    private function pageUrl($request, int $submissionId, int $page): string
    {
        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            // Without the journal the dispatcher falls back to the site index,
            // and the paging links land outside the journal altogether.
            newContext: $request->getContext()?->getPath(),
            handler: 'article',
            op: 'view',
            path: [$submissionId],
            params: ['articlesBySameAuthorPage' => $page],
            urlLocaleForPage: ''
        );
    }
}
