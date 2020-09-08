<?php

namespace WikiMirror\Mirror;

use MWException;
use ParserOptions;
use ParserOutput;
use TextContent;
use Title;
use WikiMirror\API\ParseResponse;

class MirrorContent extends TextContent {
	/** @var ParseResponse */
	protected $parseResponse;

	/**
	 * MirrorContent constructor.
	 *
	 * @param Mirror $mirror Mirror service
	 * @param Title $title Title this content is for
	 * @throws MWException on error
	 */
	public function __construct( Mirror $mirror, Title $title ) {
		$status = $mirror->getCachedText( $title );
		if ( !$status->isOK() ) {
			throw new MWException( $status->getMessage() );
		}

		/** @var ParseResponse $text */
		$text = $status->getValue();
		$this->parseResponse = $text;

		parent::__construct( $text->wikitext, CONTENT_MODEL_MIRROR );
	}

	/**
	 * @inheritDoc
	 */
	protected function getHtml() {
		return $this->parseResponse->html;
	}

	/**
	 * @inheritDoc
	 */
	protected function fillParserOutput(
		Title $title,
		$revId,
		ParserOptions $options,
		$generateHtml,
		ParserOutput &$output
	) {
		$output = $this->parseResponse->getParserOutput();
	}
}
