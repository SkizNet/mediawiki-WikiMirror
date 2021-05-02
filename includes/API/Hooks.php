<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\API;

use ApiModuleManager;
use ExtensionRegistry;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;

class Hooks implements ApiMain__moduleManagerHook {
	/**
	 * Override API action=visualeditor to accomodate mirrored pages
	 *
	 * @param ApiModuleManager $moduleManager
	 * @return void
	 */
	public function onApiMain__moduleManager( $moduleManager ) {
		// If VE isn't loaded, bail out early
		$extensionRegistry = ExtensionRegistry::getInstance();
		if ( !$extensionRegistry->isLoaded( 'VisualEditor' )
			|| !$moduleManager->isDefined( 'visualeditor', 'action' )
		) {
			return;
		}

		// ObjectFactory spec here should be kept in sync with VE extension.json
		// with an additional Mirror service tacked on the end
		$moduleManager->addModule( 'visualeditor', 'action', [
			'class' => 'WikiMirror\API\ApiVisualEditor',
			'services' => [
				'UserNameUtils',
				'Mirror'
			]
		] );
	}
}
