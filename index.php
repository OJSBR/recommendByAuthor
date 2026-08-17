<?php

/**
 * @defgroup plugins_generic_recommendByAuthor Recommend By Author Plugin
 */

/**
 * @file plugins/generic/recommendByAuthor/index.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_recommendByAuthor
 * @brief Wrapper for the "recommend by author" plugin.
 *
 * 3.3 loads plugins through this file; the 3.5 branch has none because that
 * release finds the class through the namespace instead.
 */

require_once('RecommendByAuthorPlugin.inc.php');

return new RecommendByAuthorPlugin();
