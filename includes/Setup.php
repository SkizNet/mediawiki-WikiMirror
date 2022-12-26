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
	}
}
