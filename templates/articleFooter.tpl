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
 *
 * Ported from the 3.5 branch: 3.3 has no frontend/components/pagination.tpl,
 * so the two links the plugin already computed are written out here; the
 * issue comes from the plugin rather than from a collection; and {url} takes
 * neither a router nor urlLocaleForPage in this release.
 *}
{if $articlesBySameAuthor->submissions}
	<section id="articlesBySameAuthorList">
		<h2 class="label" id="articlesBySameAuthor">
			{translate key="plugins.generic.recommendByAuthor.heading"}
		</h2>
		<ul>
			{foreach from=$articlesBySameAuthor->submissions item=submission}
				{assign var=publication value=$submission->getCurrentPublication()}
				{assign var=issue value=$articlesBySameAuthor->plugin->getIssue((int) $publication->getData('issueId'))}
				<li>
					{foreach from=$publication->getData('authors') item=author}
						{$author->getFullName()|escape},
					{/foreach}
					<a href="{url journal=$currentContext->getPath() page="article" op="view" path=$submission->getBestId()}">
						{$publication->getLocalizedFullTitle()|strip_unsafe_html}
					</a>
					{if $issue},
					<a href="{url journal=$currentContext->getPath() page="issue" op="view" path=$issue->getBestIssueId()}">
						{$currentContext->getLocalizedName()|escape}: {$issue->getIssueIdentification()|escape}
					</a>
					{/if}
				</li>
			{/foreach}
		</ul>
		{if $articlesBySameAuthor->previousUrl || $articlesBySameAuthor->nextUrl}
			<div id="articlesBySameAuthorPages">
				{if $articlesBySameAuthor->previousUrl}
					<a href="{$articlesBySameAuthor->previousUrl|escape}#articlesBySameAuthor" class="prev">
						{translate key="plugins.generic.recommendByAuthor.previous"}
					</a>
				{/if}
				<span class="current">
					{translate key="plugins.generic.recommendByAuthor.showing" start=$articlesBySameAuthor->start end=$articlesBySameAuthor->end total=$articlesBySameAuthor->total}
				</span>
				{if $articlesBySameAuthor->nextUrl}
					<a href="{$articlesBySameAuthor->nextUrl|escape}#articlesBySameAuthor" class="next">
						{translate key="plugins.generic.recommendByAuthor.next"}
					</a>
				{/if}
			</div>
		{/if}
	</section>
{/if}
