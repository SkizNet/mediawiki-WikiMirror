<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Service;

use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Parser\Parsoid\ParsoidOutputAccess;
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
					// pass Mirror as lazily-loaded to avoid service dependency loops
					static fn() => $container->getService( 'Mirror' )
				);
			} );

		$services->addServiceManipulator( 'ParsoidOutputAccess',
			static function ( ParsoidOutputAccess $outputAccess, ServiceContainer $container ) {
				return new ParsoidOutputAccessManipulator( $outputAccess );
			} );

		$services->addServiceManipulator( 'RevisionLookup',
			static function ( RevisionLookup $lookup, ServiceContainer $container ) {
				return new RevisionLookupManipulator(
					$lookup,
					$container->getService( 'Mirror' )
				);
			} );
	}
}
