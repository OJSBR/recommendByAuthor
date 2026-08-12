<?php

/**
 * @file plugins/generic/recommendByAuthor/RecommendByAuthorSettingsForm.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendByAuthorSettingsForm
 *
 * @brief How many recommendations are shown, and how much work the refresh is
 *        allowed to do.
 */

namespace APP\plugins\generic\recommendByAuthor;

use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class RecommendByAuthorSettingsForm extends Form
{
    /** Whole numbers, with the smallest value that still makes sense. */
    private const NUMERIC_FIELDS = [
        'recommendationCount' => 1,
        'maxRecommendations' => 1,
        'batchSize' => 1,
        'queueLimit' => 0,
        'maxAgeDays' => 1,
        'rankingTtlHours' => 1,
        'htmlCacheHours' => 1,
    ];

    private const FLAG_FIELDS = ['matchByOrcid', 'computeOnDemand'];

    public function __construct(private RecommendByAuthorPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        foreach (self::NUMERIC_FIELDS as $field => $minimum) {
            $this->addCheck(new FormValidatorCustom(
                $this,
                $field,
                'required',
                'plugins.generic.recommendByAuthor.settings.numberRequired',
                fn ($value) => is_numeric($value) && (int) $value >= $minimum
            ));
        }
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        foreach (array_diff(array_keys(RecommendByAuthorPlugin::DEFAULTS), ['cacheStamp']) as $field) {
            $this->setData($field, $this->plugin->getPluginSetting($field));
        }
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_diff(array_keys(RecommendByAuthorPlugin::DEFAULTS), ['cacheStamp']));
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'orderByOptions' => [
                RecommendationStore::ORDER_BY_METRIC => 'plugins.generic.recommendByAuthor.settings.orderBy.metric',
                RecommendationStore::ORDER_BY_DATE => 'plugins.generic.recommendByAuthor.settings.orderBy.date',
            ],
            'queueStatus' => $this->queueStatus(),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->plugin->settingsContextId();

        foreach (self::NUMERIC_FIELDS as $field => $minimum) {
            $this->plugin->updateSetting($contextId, $field, max($minimum, (int) $this->getData($field)), 'int');
        }
        foreach (self::FLAG_FIELDS as $field) {
            $this->plugin->updateSetting($contextId, $field, $this->getData($field) ? 1 : 0, 'int');
        }

        $orderBy = $this->getData('orderBy') === RecommendationStore::ORDER_BY_DATE
            ? RecommendationStore::ORDER_BY_DATE
            : RecommendationStore::ORDER_BY_METRIC;
        $this->plugin->updateSetting($contextId, 'orderBy', $orderBy, 'string');

        // Anything already rendered was rendered under the old settings.
        $this->plugin->updateSetting($contextId, 'cacheStamp', time(), 'int');

        parent::execute(...$functionArgs);
    }

    /**
     * How far the refresh has got, so that an editor who has just enabled the
     * plugin can see it filling up instead of wondering why articles show no
     * recommendations yet.
     */
    private function queueStatus(): array
    {
        $total = DB::table('recommend_author_state')->count();
        $pending = DB::table('recommend_author_state')->whereNull('computed_at')->count();

        return [
            'total' => $total,
            'computed' => $total - $pending,
            'pending' => $pending,
        ];
    }
}
