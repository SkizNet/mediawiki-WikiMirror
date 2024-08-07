<?php

namespace WikiMirror\Mirror;

use TextContent;
use WikiMirror\API\PageInfoResponse;

class MirrorContent extends TextContent {
	/** @var PageInfoResponse */
	protected PageInfoResponse $pageInfo;

	/**
	 * MirrorContent constructor.
	 *
	 * @param PageInfoResponse $pageInfo Page information this content is for
	 */
	public function __construct( PageInfoResponse $pageInfo ) {
		$this->pageInfo = $pageInfo;
		parent::__construct( $pageInfo->getText()->wikitext, CONTENT_MODEL_MIRROR );
	}

	/**
	 * @inheritDoc
	 */
	public function getRedirectTarget() {
		return $this->pageInfo->redirect;
	}

	/**
	 * @return PageInfoResponse
	 */
	public function getMirrorPageInfo() {
		return $this->pageInfo;
	}
}
