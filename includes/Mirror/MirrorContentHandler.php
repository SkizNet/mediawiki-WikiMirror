<?php

namespace WikiMirror\Mirror;

use Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use ParserOutput;
use TextContentHandler;

class MirrorContentHandler extends TextContentHandler {
	/**
	 * MirrorContentHandler constructor.
	 *
	 * @param string $modelId
	 */
	public function __construct( $modelId = CONTENT_MODEL_MIRROR ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_WIKITEXT ] );
	}

	/**
	 * @inheritDoc
	 */
	public function supportsRedirects() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getActionOverrides() {
		return [
			'history' => HistoryAction::class
		];
	}

	/**
	 * @inheritDoc
	 */
	public function fillParserOutput( Content $content, ContentParseParams $cpoParams, ParserOutput &$output ) {
		if ( !( $content instanceof MirrorContent ) ) {
			parent::fillParserOutput( $content, $cpoParams, $output );
			return;
		}

		$modules = [];
		if ( $content->getRedirectTarget() !== null ) {
			$modules[] = 'mediawiki.action.view.redirectPage';
		}

		$output = $content->getMirrorPageInfo()->getText()->getParserOutput();
		$output->addModuleStyles( $modules );
	}
}
