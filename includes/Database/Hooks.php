<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Database;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Hooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * Define extension tables.
	 *
	 * Do not use this hook with a handler that uses a "services" option in
	 * its ObjectFactory spec. It is called in a context where the global
	 * service locator is not initialised.
	 *
	 * @param DatabaseUpdater $updater DatabaseUpdater subclass
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable( 'forked_page', __DIR__ . '/../../sql/add-forked_page.sql' );
		$updater->addExtensionTable( 'forked_titles', __DIR__ . '/../../sql/add-forked_titles.sql' );
		$updater->addExtensionTable( 'remote_page', __DIR__ . '/../../sql/add-remote_page.sql' );
	}
}
