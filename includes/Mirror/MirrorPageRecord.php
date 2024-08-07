<?php

namespace WikiMirror\Mirror;

use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Parser\ParserOutput;
use WikiMirror\API\PageInfoResponse;

class MirrorPageRecord extends PageIdentityValue implements ExistingPageRecord {
	/** @var PageInfoResponse */
	private PageInfoResponse $pageInfo;

	public function __construct( PageInfoResponse $pageInfo ) {
		$this->pageInfo = $pageInfo;
		$dbKey = $pageInfo->getTitle()->getDBkey();
		// lie about the wiki id for now since a lot of core doesn't support non-local pages
		parent::__construct( $pageInfo->pageId, $pageInfo->namespace, $dbKey, self::LOCAL );
	}

	/**
	 * @inheritDoc
	 */
	public function isNew() {
		return $this->pageInfo->lastRevision !== null && $this->pageInfo->lastRevision->parentId > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function isRedirect() {
		return $this->pageInfo->redirect !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function getLatest( $wikiId = self::LOCAL ) {
		$this->assertWiki( $wikiId );
		return $this->pageInfo->lastRevisionId;
	}

	/**
	 * @inheritDoc
	 */
	public function getTouched() {
		return $this->pageInfo->touched;
	}

	/**
	 * @inheritDoc
	 */
	public function getLanguage() {
		return $this->pageInfo->pageLanguage;
	}

	/**
	 * Get a ParserOutput object for this page without pulling in a dozen extra services.
	 *
	 * @return ParserOutput
	 * @internal
	 */
	public function getParserOutput(): ParserOutput {
		$content = new MirrorContent( $this->pageInfo );
		$handler = new MirrorContentHandler();
		$cpoParams = new ContentParseParams( $this, $this->getLatest() );
		return $handler->getParserOutput( $content, $cpoParams );
	}
}
