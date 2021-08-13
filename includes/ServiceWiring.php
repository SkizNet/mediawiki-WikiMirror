<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use WikiMirror\Mirror\Mirror;

return [
	'Mirror' => static function ( MediaWikiServices $services ): Mirror {
		return new Mirror(
			$services->getInterwikiLookup(),
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache(),
			$services->getDBLoadBalancer(),
			new ServiceOptions(
				Mirror::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	}
];
