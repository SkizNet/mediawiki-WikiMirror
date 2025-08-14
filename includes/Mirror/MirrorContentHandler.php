<?php

namespace WikiMirror\Mirror;

use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContentHandler;
use MediaWiki\Parser\ParserOutput;

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

		$pageInfo = $content->getMirrorPageInfo();
		$output = $pageInfo->getText()->getParserOutput();
		$output->addModuleStyles( $modules );
		// We can't directly add the OOUI ButtonWidget here due to a core bug (phab task pending)
		// So set a placeholder and add indicator via BeforePageDisplay
		$output->setIndicator( 'ext-wm-indicator-mirror', $pageInfo->getUrl() );
	}
}
