<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use WikiMirror\Mirror\LazyMirror;
use WikiMirror\Mirror\Mirror;

return [
	'LazyMirror' => static function ( MediaWikiServices $services ): LazyMirror {
		return new LazyMirror( static fn () => $services->getService( 'Mirror' ) );
	},
	'Mirror' => static function ( MediaWikiServices $services ): Mirror {
		return new Mirror(
			$services->getInterwikiLookup(),
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache(),
			$services->getDBLoadBalancer(),
			$services->getRedirectLookup(),
			$services->getGlobalIdGenerator(),
			$services->getLanguageFactory(),
			$services->getTitleFormatter(),
			new ServiceOptions(
				Mirror::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	}
];
