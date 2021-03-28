<?php

namespace WikiMirror\Mirror;

use MWException;
use OOUI\ButtonWidget;
use ParserOptions;
use ParserOutput;
use TextContent;
use Title;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\API\ParseResponse;

class MirrorContent extends TextContent {
	/** @var PageInfoResponse */
	protected $pageInfo;
	/** @var ParseResponse */
	protected $text;

	/**
	 * MirrorContent constructor.
	 *
	 * @param PageInfoResponse $pageInfo Page information this content is for
	 * @throws MWException on error
	 */
	public function __construct( PageInfoResponse $pageInfo ) {
		$this->pageInfo = $pageInfo;
		$this->text = $pageInfo->getText();

		parent::__construct( $this->text->wikitext, CONTENT_MODEL_MIRROR );
	}

	/**
	 * @inheritDoc
	 */
	public function getRedirectTarget() {
		return $this->pageInfo->redirect;
	}

	/**
	 * @inheritDoc
	 */
	protected function getHtml() {
		return $this->text->html;
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
		$mirrorIndicator = new ButtonWidget( [
			'href' => $this->pageInfo->getUrl(),
			'target' => '_blank',
			'icon' => 'articles',
			'framed' => false,
			'title' => wfMessage( 'wikimirror-mirrored' )->plain()
		] );

		$modules = [ 'oojs-ui.styles.icons-content' ];
		if ( $this->getRedirectTarget() !== null ) {
			$modules[] = 'mediawiki.action.view.redirectPage';
		}

		$output = $this->text->getParserOutput();
		$output->setEnableOOUI( true );
		$output->addModuleStyles( $modules );
		$output->setIndicator( 'ext-wm-indicator-mirror', $mirrorIndicator );
	}
}
