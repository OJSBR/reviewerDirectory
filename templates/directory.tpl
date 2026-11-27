{**
 * plugins/generic/reviewerDirectory/templates/directory.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The reviewer directory page: the component of js/reviewerDirectory.js, fed by the
 * state the handler sets.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<ojsbr-reviewer-directory :config="reviewerDirectory"></ojsbr-reviewer-directory>
{/block}
