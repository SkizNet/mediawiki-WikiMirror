<?php

namespace WikiMirror\Search;

use ISearchResultSet;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use PaginatingSearchEngine;
use SearchEngine;
use Status;
use Wikimedia\Assert\Assert;

/**
 * A search engine which searches all engines listed in $wgSearchTypeAlternatives
 */
class CombinedSearch extends SearchEngine implements PaginatingSearchEngine {
	/** @var SearchEngine[] */
	private array $engines;

	public function __construct() {
		// As of 1.41, the ObjectFactory spec for search engines doesn't support service injection
		$config = MediaWikiServices::getInstance()->getSearchEngineConfig();
		$factory = MediaWikiServices::getInstance()->getSearchEngineFactory();

		foreach ( $config->getSearchTypes() as $type ) {
			if ( $type === 'CombinedSearch' ) {
				continue;
			}

			$this->engines[] = $factory->create( $type );
		}
	}

	/**
	 * @param string $term Search term
	 * @param string $type Search type - either doSearchTitle or doSearchText
	 * @return Status|CombinedSearchResultSet|null
	 */
	private function doCombinedSearch( string $term, string $type ) {
		$results = [];
		$limit = $this->limit;
		$offset = $this->offset;

		foreach ( $this->engines as $engine ) {
			if ( $limit <= 0 ) {
				break;
			}

			$engine->setLimitOffset( $limit, $offset );
			$r = $engine->$type( $term );

			if ( $r instanceof Status ) {
				if ( !$r->isGood() ) {
					// omit failed mirrored searches from results, but pass other failures to the end user
					if ( get_class( $engine ) === 'WikiMirror\Search\MirrorSearch' ) {
						continue;
					} else {
						return $r;
					}
				}

				$r = $r->getValue();
			}

			// skip empty/dummy result sets
			if ( $r === null ) {
				continue;
			}

			// $r must be an ISearchResultSet at this point; fail if not as this indicates a compatibility issue
			Assert::postcondition( $r instanceof ISearchResultSet,
				'SearchEngine backend did not return a value of the correct type' );
			$results[] = $r;
			$limit -= $r->numRows();
			$offset = max( $offset - ( $r->getTotalHits() ?? $r->numRows() ), 0 );
		}

		// no results?
		if ( $results === [] ) {
			return null;
		}

		return new CombinedSearchResultSet( $results );
	}

	/** @inheritDoc */
	protected function doSearchText( $term ) {
		return $this->doCombinedSearch( $term, 'doSearchText' );
	}

	/** @inheritDoc */
	protected function doSearchTitle( $term ) {
		return $this->doCombinedSearch( $term, 'doSearchTitle' );
	}

	/** @inheritDoc */
	protected function simplePrefixSearch( $search ) {
		$results = [];
		$numEngines = count( $this->engines );
		// simple pagination; some results will be repeated between pages but this is primarily used by opensearch
		// which doesn't page at all, so that shouldn't come up much in practice anyway
		$offset = (int)( $this->offset / $numEngines );

		foreach ( $this->engines as $engine ) {
			// always pull a full set of results in case one engine returns nothing
			$engine->setLimitOffset( $this->limit, $offset );
			$results = array_merge( $results, $engine->simplePrefixSearch( $search ) );
		}

		usort( $results, static function ( Title $a, Title $b ) {
			return $a->getText() <=> $b->getText() ?: $a->getNamespace() <=> $b->getNamespace();
		} );

		// return the most relevant results
		return array_slice( $results, 0, $this->limit );
	}
}
