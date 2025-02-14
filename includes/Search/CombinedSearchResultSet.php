<?php

namespace WikiMirror\Search;

use ISearchResultSet;
use SearchResultSetTrait;

class CombinedSearchResultSet implements ISearchResultSet {
	use SearchResultSetTrait;

	/** @var ISearchResultSet[] */
	private array $results;

	/**
	 * @param ISearchResultSet[] $results
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	/**
	 * @inheritDoc
	 */
	public function count(): int {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry + $item->count();
		}, 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function numRows() {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry + $item->numRows();
		}, 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function getTotalHits() {
		$total = 0;
		foreach ( $this->results as $result ) {
			$add = $result->getTotalHits();
			if ( $add === null ) {
				// not supported in this backend, which means not supported in general
				return null;
			}

			$total += $add;
		}

		return $total;
	}

	/**
	 * @inheritDoc
	 */
	public function isApproximateTotalHits(): bool {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			// @phan-suppress-next-line PhanUndeclaredMethod
			return $carry || $item->isApproximateTotalHits();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function hasRewrittenQuery() {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry || $item->hasRewrittenQuery();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryAfterRewrite() {
		foreach ( $this->results as $result ) {
			$r = $result->getQueryAfterRewrite();
			if ( $r !== null ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryAfterRewriteSnippet() {
		foreach ( $this->results as $result ) {
			$r = $result->getQueryAfterRewriteSnippet();
			if ( $r !== null ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function hasSuggestion() {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry || $item->hasSuggestion();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function getSuggestionQuery() {
		foreach ( $this->results as $result ) {
			$r = $result->getSuggestionQuery();
			if ( $r !== null ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getSuggestionSnippet() {
		foreach ( $this->results as $result ) {
			$r = $result->getSuggestionSnippet();
			if ( $r !== null ) {
				return $r;
			}
		}

		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getInterwikiResults( $type = self::SECONDARY_RESULTS ) {
		$results = [];

		foreach ( $this->results as $result ) {
			$results = array_merge( $results, $result->getInterwikiResults( $type ) );
		}

		return $results;
	}

	/**
	 * @inheritDoc
	 */
	public function hasInterwikiResults( $type = self::SECONDARY_RESULTS ) {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry || $item->hasInterwikiResults();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function searchContainedSyntax() {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry || $item->searchContainedSyntax();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function hasMoreResults() {
		return array_reduce( $this->results, static function ( $carry, ISearchResultSet $item ) {
			return $carry || $item->hasMoreResults();
		}, false );
	}

	/**
	 * @inheritDoc
	 */
	public function shrink( $limit ) {
		$remaining = $limit;

		foreach ( $this->results as $result ) {
			$shrinkTo = min( $result->count(), $remaining );
			$result->shrink( $shrinkTo );
			$remaining -= $shrinkTo;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function extractResults() {
		$return = [];

		foreach ( $this->results as $result ) {
			$return = array_merge( $return, $result->extractResults() );
		}

		return $return;
	}

	/**
	 * @inheritDoc
	 */
	public function extractTitles() {
		$return = [];

		foreach ( $this->results as $result ) {
			$return = array_merge( $return, $result->extractTitles() );
		}

		return $return;
	}
}
