<?php

// use phan config shipped with mediawiki core
$cfg = require __DIR__ . '/../../../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// we suppress things for backwards compat reasons, so suppressions may not apply to all phan test runs
// as part of dropping support for old versions, old suppressions will be removed
$cfg['suppress_issue_types'][] = 'UnusedPluginSuppression';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/VisualEditor',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/VisualEditor',
	]
);

return $cfg;
