<?php

namespace WikiMirror\Search;

use MediaWiki\Title\Title;
use RevisionSearchResult;

class MirrorSearchResult extends RevisionSearchResult {
	/** @var string */
	private string $type;

	/** @var string */
	private string $snippet;

	/** @var string|null */
	private ?string $timestamp;

	/** @var int */
	private int $size;

	/** @var int */
	private int $wordCount;

	/**
	 * @param array $apiResult
	 */
	public function __construct( $apiResult, $type ) {
		$title = Title::makeTitleSafe( $apiResult['ns'], strtr( $apiResult['title'], ' ', '_' ) );
		parent::__construct( $title );

		// full page text isn't relevant here; we have the info based on it directly
		$this->mText = '';

		$this->type = $type;
		$this->snippet = $apiResult['snippet'];
		$this->size = $apiResult['size'];
		$this->wordCount = $apiResult['wordcount'];

		if ( $apiResult['timestamp'] !== null ) {
			$this->timestamp = wfTimestamp( TS_MW, $apiResult['timestamp'] );
		}
	}

	/** @inheritDoc */
	protected function initFromTitle( $title ) {
		// no-op; purely here to override default behavior from the parent class
	}

	/** @inheritDoc */
	public function isMissingRevision() {
		return false;
	}

	/** @inheritDoc */
	public function getTextSnippet( $terms = [] ) {
		return $this->type === 'text' ? $this->snippet : '';
	}

	/** @inheritDoc */
	public function getTitleSnippet() {
		return $this->type === 'title' ? $this->snippet : '';
	}

	/** @inheritDoc */
	public function getTimestamp() {
		return $this->timestamp;
	}

	/** @inheritDoc */
	public function getWordCount() {
		return $this->wordCount;
	}

	/** @inheritDoc */
	public function getByteSize() {
		return $this->size;
	}
}
