<?php

namespace WikiMirror\Search;

use SearchResult;
use SearchResultSet;

class MirrorSearchResultSet extends SearchResultSet {
	/** @var int */
	private int $totalHits;

	/**
	 * @param SearchResult[] $results
	 * @param int $totalHits
	 * @param bool $hasMoreResults
	 * @return void
	 */
	public function __construct( array $results, int $totalHits, bool $hasMoreResults ) {
		parent::__construct( false, $hasMoreResults );
		$this->results = $results;
		$this->totalHits = $totalHits;
	}

	/** @inheritDoc */
	public function getTotalHits() {
		return $this->totalHits;
	}
}
