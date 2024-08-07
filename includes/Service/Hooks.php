<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Service;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
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
		// limited scope replacement of PageLookup since the service name is PageStore and the PageLookup interface
		// doesn't encompass all PageStore methods. Also this was easier to patch in without needing to worry about
		// the rest of the wiki breaking. Eventually we should just replace PageStore/PageLookup everywhere though.
		$services->redefineService( 'PageRestHelperFactory',
			static function ( MediaWikiServices $services ): PageRestHelperFactory {
				return new PageRestHelperFactory(
					new ServiceOptions( PageRestHelperFactory::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
					$services->getRevisionLookup(),
					$services->getTitleFormatter(),
					new PageLookupManipulator( $services->getPageStore(), $services->getService( 'Mirror' ) ),
					$services->getParsoidOutputStash(),
					$services->getStatsdDataFactory(),
					new ParsoidOutputAccessManipulator( $services->getParsoidOutputAccess() ),
					$services->getHtmlTransformFactory(),
					$services->getContentHandlerFactory(),
					$services->getLanguageFactory(),
					$services->getRedirectStore(),
					$services->getLanguageConverterFactory()
				);
			} );

		$services->addServiceManipulator( 'RevisionLookup',
			static function ( RevisionLookup $lookup, ServiceContainer $container ) {
				return new RevisionLookupManipulator( $lookup, $container->getService( 'Mirror' ) );
			} );
	}
}
