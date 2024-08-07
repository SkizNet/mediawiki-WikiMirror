<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Service;

use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Revision\RevisionLookup;
use Wikimedia\Services\ServiceContainer;

class Hooks	implements MediaWikiServicesHook {
	/**
	 * This hook is called when a global MediaWikiServices instance is initialized.
	 * Extensions may use this to define, replace, or wrap services. However, the
	 * preferred way to define a new service is the $wgServiceWiringFiles array.
	 *
	 * @param MediaWikiServices $services
	 * @return void
	 */
	public function onMediaWikiServices( $services ) {
		$services->addServiceManipulator( 'PageStoreFactory',
			static function ( PageStoreFactory $factory, ServiceContainer $container ) {
				return new PageStoreFactoryManipulator(
					$factory,
					$container->getService( 'LazyMirror' )
				);
			} );

		$services->addServiceManipulator( 'PageRestHelperFactory',
			static function ( PageRestHelperFactory $factory, ServiceContainer $container ) {
				return new PageRestHelperFactoryManipulator( $factory );
			} );

		$services->addServiceManipulator( 'RevisionLookup',
			static function ( RevisionLookup $lookup, ServiceContainer $container ) {
				return new RevisionLookupManipulator(
					$lookup,
					$container->getService( 'LazyMirror' )
				);
			} );
	}
}
