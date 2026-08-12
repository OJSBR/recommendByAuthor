{**
 * plugins/generic/recommendByAuthor/templates/articleFooter.tpl
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A template to be included via Templates::Article::Footer::PageFooter hook.
 *
 * The element ids are the ones the original plugin used, so themes that style
 * this section keep working.
 *}
{if $articlesBySameAuthor->submissions}
	<section id="articlesBySameAuthorList">
		<h2 class="label" id="articlesBySameAuthor">
			{translate key="plugins.generic.recommendByAuthor.heading"}
		</h2>
		<ul>
			{foreach from=$articlesBySameAuthor->submissions item=submission}
				{assign var=publication value=$submission->getCurrentPublication()}
				{assign var=issue value=$articlesBySameAuthor->issues->get($publication->getData('issueId'))}
				<li>
					{foreach from=$publication->getData('authors') item=author}
						{$author->getFullName()|escape},
					{/foreach}
					<a href="{url router=PKP\core\PKPApplication::ROUTE_PAGE journal=$currentContext->getPath() page="article" op="view" path=$submission->getBestId() urlLocaleForPage=""}">
						{$publication->getLocalizedFullTitle(null, 'html')|strip_unsafe_html}
					</a>
					{if $issue},
					<a href="{url router=PKP\core\PKPApplication::ROUTE_PAGE journal=$currentContext->getPath() page="issue" op="view" path=$issue->getBestIssueId() urlLocaleForPage=""}">
						{$currentContext->getLocalizedName()|escape}: {$issue->getIssueIdentification()|escape}
					</a>
					{/if}
				</li>
			{/foreach}
		</ul>
		<div id="articlesBySameAuthorPages">
			{include
				file="frontend/components/pagination.tpl"
				prevUrl=$articlesBySameAuthor->previousUrl
				nextUrl=$articlesBySameAuthor->nextUrl
				showingStart=$articlesBySameAuthor->start
				showingEnd=$articlesBySameAuthor->end
				total=$articlesBySameAuthor->total
			}
		</div>
	</section>
{/if}
