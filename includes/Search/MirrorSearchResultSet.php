<?php

namespace WikiMirror\Search;

use SearchResult;
use SearchResultSet;

class MirrorSearchResultSet extends SearchResultSet {
	/**
	 * @param SearchResult[] $results
	 * @return void
	 */
	public function __construct( array $results ) {
		parent::__construct( false, false );
		$this->results = $results;
	}
}
