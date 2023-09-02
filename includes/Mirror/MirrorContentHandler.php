<?php

namespace WikiMirror\Mirror;

use Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use OOUI\ButtonWidget;
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

		$pageInfo = $content->getMirrorPageInfo();

		$mirrorIndicator = new ButtonWidget( [
			'href' => $pageInfo->getUrl(),
			'target' => '_blank',
			'icon' => 'articles',
			'framed' => false,
			'title' => wfMessage( 'wikimirror-mirrored' )->plain()
		] );

		$modules = [ 'oojs-ui.styles.icons-content' ];
		if ( $content->getRedirectTarget() !== null ) {
			$modules[] = 'mediawiki.action.view.redirectPage';
		}

		$output = $pageInfo->getText()->getParserOutput();
		$output->setEnableOOUI( true );
		$output->addModuleStyles( $modules );
		$output->setIndicator( 'ext-wm-indicator-mirror', $mirrorIndicator );
	}
}
