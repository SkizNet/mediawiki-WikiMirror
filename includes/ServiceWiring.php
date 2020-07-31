<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use WikiMirror\Mirror\Mirror;

return [
	'Mirror' => function ( MediaWikiServices $services ) : Mirror {
		return new Mirror(
			$services->getInterwikiLookup(),
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache(),
			new ServiceOptions(
			Mirror::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	}
];
