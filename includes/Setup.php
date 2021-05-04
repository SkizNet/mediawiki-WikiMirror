<?php

namespace WikiMirror;

class Setup {
	/**
	 * extension.json callback
	 *
	 * Initializes our constants
	 */
	public static function callback() {
		define( 'CONTENT_MODEL_MIRROR', 'mirror' );

		// 1.35 compat
		if ( !defined( 'DB_PRIMARY' ) ) {
			// phpcs:disable MediaWiki.Usage.DeprecatedConstantUsage.DB_MASTER
			define( 'DB_PRIMARY', DB_MASTER );
		}
	}
}
