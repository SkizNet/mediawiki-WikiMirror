<?php

namespace WikiMirror\Search;

use ISearchResultSet;
use MediaWiki\MediaWikiServices;
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

		foreach ( $this->engines as $engine ) {
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
}
