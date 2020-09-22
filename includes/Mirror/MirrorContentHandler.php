<?php

namespace WikiMirror\Mirror;

use TextContentHandler;

class MirrorContentHandler extends TextContentHandler {
	public function __construct( $modelId = CONTENT_MODEL_MIRROR ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_WIKITEXT ] );
	}
}
