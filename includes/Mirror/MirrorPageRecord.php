<?php

namespace WikiMirror\Mirror;

use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use ParserOutput;
use RuntimeException;
use stdClass;
use Title;
use Wikimedia\Assert\Assert;
use WikiMirror\API\PageInfoResponse;

class MirrorPageRecord extends PageIdentityValue implements ExistingPageRecord {
	/**
	 * Fields that must be present in the constructor.
	 * Various rr_* fields are all optional since they might be null.
	 */
	public const REQUIRED_FIELDS = [
		'rp_id',
		'rp_namespace',
		'rp_title',
	];

	/** @var stdClass Row from database */
	private stdClass $row;

	/** @var Mirror */
	private Mirror $mirror;

	/** @var PageInfoResponse|null */
	private ?PageInfoResponse $pageInfo = null;

	/**
	 * Constructor
	 *
	 * @param stdClass $row Row from database
	 * @param Mirror $mirror
	 */
	public function __construct( stdClass $row, Mirror $mirror ) {
		foreach ( self::REQUIRED_FIELDS as $field ) {
			Assert::parameter( isset( $row->$field ), '$row->' . $field, 'is required' );
		}

		Assert::parameter( $row->rp_id > 0, '$pageId', 'must be greater than zero (page must exist)' );

		// lie about the wiki id for now since a lot of core doesn't support non-local pages
		parent::__construct( $row->rp_id, $row->rp_namespace, $row->rp_title, self::LOCAL );

		$this->row = $row;
		$this->mirror = $mirror;
	}

	/**
	 * @inheritDoc
	 */
	public function isNew() {
		return (bool)$this->getPageInfo()?->lastRevision?->parentId;
	}

	/**
	 * @inheritDoc
	 */
	public function isRedirect() {
		return isset( $this->row->rr_from );
	}

	public function getRedirectTarget() {
		if ( !$this->isRedirect() ) {
			return null;
		}

		return Title::makeTitleSafe( $this->row->rr_namespace, $this->row->rr_title );
	}

	/**
	 * @inheritDoc
	 */
	public function getLatest( $wikiId = self::LOCAL ) {
		$this->assertWiki( $wikiId );
		return $this->getPageInfo()?->lastRevisionId ?? 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getTouched() {
		return MWTimestamp::convert( TS_MW, $this->getPageInfo()?->touched ?? '19700101000000' );
	}

	/**
	 * @inheritDoc
	 */
	public function getLanguage() {
		return $this->getPageInfo()?->pageLanguage;
	}

	/**
	 * Get a ParserOutput object for this page without pulling in a dozen extra services.
	 *
	 * @return ParserOutput
	 * @internal
	 */
	public function getParserOutput(): ParserOutput {
		$content = new MirrorContent( $this->getPageInfo() );
		$handler = new MirrorContentHandler();
		$cpoParams = new ContentParseParams( $this, $this->getLatest() );
		return $handler->getParserOutput( $content, $cpoParams );
	}

	private function getPageInfo(): ?PageInfoResponse {
		if ( $this->pageInfo !== null ) {
			return $this->pageInfo;
		}

		$status = $this->mirror->getCachedPage( $this );
		if ( $status->isOK() ) {
			$this->pageInfo = $status->getValue();
			return $this->pageInfo;
		}

		// 1.41 compat; use StatusFormatter once we only support 1.42+
		throw new RuntimeException( $status->getMessage() );
	}
}
