{**
 * plugins/generic/recommendByAuthor/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * How many recommendations are shown, and how much work the refresh may do.
 *}
<script>
	$(function() {ldelim}
		$('#recommendByAuthorSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<style>
	.rbaStatus {ldelim}
		margin:1em 0; padding:.9em 1.1em; border:1px solid #b9d3e6; border-left:4px solid #3a6ea5;
		background:#f2f7fb; border-radius:6px; line-height:1.5;
	{rdelim}
	.rbaStatus strong {ldelim} color:#20486e; {rdelim}
	.rbaHeading {ldelim} margin:1.4em 0 .3em; font-size:1.05em; font-weight:700; color:#16232f; {rdelim}
	.rbaHint {ldelim} color:#61707e; margin:.4em 0 .8em; font-size:.93em; line-height:1.5; {rdelim}
</style>

<form
	class="pkp_form"
	id="recommendByAuthorSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="recommendByAuthorSettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.recommendByAuthor.settings.description"}</div>

	<div class="rbaStatus">
		<strong>{translate key="plugins.generic.recommendByAuthor.settings.status.title"}</strong><br />
		{translate key="plugins.generic.recommendByAuthor.settings.status.body" computed=$queueStatus.computed pending=$queueStatus.pending total=$queueStatus.total}
	</div>

	{fbvFormArea id="recommendByAuthorDisplayArea"}
		<p class="rbaHeading">{translate key="plugins.generic.recommendByAuthor.settings.display.title"}</p>

		{fbvFormSection}
			{fbvElement type="text" id="recommendationCount" value=$recommendationCount label="plugins.generic.recommendByAuthor.settings.recommendationCount" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxRecommendations" value=$maxRecommendations label="plugins.generic.recommendByAuthor.settings.maxRecommendations" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.recommendByAuthor.settings.orderBy"}
			{foreach from=$orderByOptions key=optionValue item=optionLabel}
				<label style="display:block; margin:.2em 0;">
					<input type="radio" name="orderBy" value="{$optionValue|escape}" {if $orderBy == $optionValue}checked="checked"{/if} />
					{translate key=$optionLabel}
				</label>
			{/foreach}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="matchByOrcid" label="plugins.generic.recommendByAuthor.settings.matchByOrcid" checked=$matchByOrcid}
		{/fbvFormSection}
		<p class="rbaHint">{translate key="plugins.generic.recommendByAuthor.settings.matchByOrcid.hint"}</p>
	{/fbvFormArea}

	{fbvFormArea id="recommendByAuthorRefreshArea"}
		<p class="rbaHeading">{translate key="plugins.generic.recommendByAuthor.settings.refresh.title"}</p>
		<p class="rbaHint">{translate key="plugins.generic.recommendByAuthor.settings.refresh.hint"}</p>

		{fbvFormSection}
			{fbvElement type="text" id="batchSize" value=$batchSize label="plugins.generic.recommendByAuthor.settings.batchSize" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxAgeDays" value=$maxAgeDays label="plugins.generic.recommendByAuthor.settings.maxAgeDays" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="queueLimit" value=$queueLimit label="plugins.generic.recommendByAuthor.settings.queueLimit" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection}
			{fbvElement type="text" id="rankingTtlHours" value=$rankingTtlHours label="plugins.generic.recommendByAuthor.settings.rankingTtlHours" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="htmlCacheHours" value=$htmlCacheHours label="plugins.generic.recommendByAuthor.settings.htmlCacheHours" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="computeOnDemand" label="plugins.generic.recommendByAuthor.settings.computeOnDemand" checked=$computeOnDemand}
		{/fbvFormSection}
		<p class="rbaHint">{translate key="plugins.generic.recommendByAuthor.settings.computeOnDemand.hint"}</p>
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
