<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Service;

use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionLookup;
use Wikimedia\Services\ServiceContainer;

class Hooks	implements MediaWikiServicesHook {
	/**
	 * This hook is called when a global MediaWikiServices instance is initialized.
	 * Extensions may use this to define, replace, or wrap services. However, the
	 * preferred way to define a new service is the $wgServiceWiringFiles array.
	 *
	 * @param MediaWikiServices $services
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onMediaWikiServices( $services ) {
		$services->addServiceManipulator( 'RevisionLookup',
			function ( RevisionLookup $lookup, ServiceContainer $container ) {
				return new RevisionLookupManipulator( $lookup, $container->getService( 'Mirror' ) );
			} );
	}
}
