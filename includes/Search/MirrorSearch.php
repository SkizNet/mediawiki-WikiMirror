<?php

namespace WikiMirror\Search;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\MediaWikiTitleCodec;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleArrayFromResult;
use PaginatingSearchEngine;
use SearchEngine;
use WikiMirror\Mirror\Mirror;

class MirrorSearch extends SearchEngine implements PaginatingSearchEngine {
	/** @var Mirror */
	private Mirror $mirror;

	/** @var int */
	private int $maxResults;

	public function __construct() {
		// As of 1.41, the ObjectFactory spec for search engines doesn't support service injection
		$this->mirror = MediaWikiServices::getInstance()->getService( 'Mirror' );
		$this->maxResults = MediaWikiServices::getInstance()->getMainConfig()->get( 'WikiMirrorSearchMaxResults' );
	}

	/**
	 * @param string $term search term
	 * @param string $type text or title
	 * @return MirrorSearchResultSet|null
	 */
	private function doApiSearch( $term, $type ) {
		$params = [
			'action' => 'query',
			'list' => 'search',
			'srsearch' => $term,
			'srnamespace' => implode( '|', $this->namespaces ),
			'srlimit' => min( $this->limit, $this->maxResults ),
			'sroffset' => $this->offset,
			'srwhat' => $type,
			'srprop' => 'size|wordcount|timestamp|snippet',
		];

		/* API response example:
		"batchcomplete": true,
		"continue": {
			"sroffset": 50,
			"continue": "-||"
		},
		"query": {
			"searchinfo": {
				"totalhits": 10472
			},
			"search": [
				{
					"ns": 0,
					"title": "Notifications/Testing",
					"pageid": 106749,
					"size": 7716,
					"wordcount": 884,
					"snippet": "Welcome to our <span class=\"searchmatch\">testing</span> page",
					"timestamp": "2020-07-29T20:47:57Z"
				},
				...
			]
		}
		*/
		$results = [];

		do {
			$resp = $this->mirror->getRemoteApiResponse( $params, __METHOD__, true );

			if ( $resp === false ) {
				// search failed
				return null;
			}

			foreach ( $resp['query']['search'] as $result ) {
				$results[] = new MirrorSearchResult( $result, $type );
			}

			// prep for next page, if there is one
			// (if there isn't, break out of the loop so we don't go on forever)
			if ( !isset( $resp['continue']['sroffset'] ) ) {
				break;
			}

			foreach ( $resp['continue'] as $key => $value ) {
				$params[$key] = $value;
			}
		} while ( count( $results ) < $this->limit );

		// are there still more results?
		$hasMoreResults = count( $results ) > $this->limit || isset( $resp['continue']['sroffset'] );

		return new MirrorSearchResultSet( $results, $resp['query']['searchinfo']['totalhits'], $hasMoreResults );
	}

	/** @inheritDoc */
	protected function doSearchText( $term ) {
		return $this->doApiSearch( $term, 'text' );
	}

	/** @inheritDoc */
	protected function doSearchTitle( $term ) {
		return $this->doApiSearch( $term, 'title' );
	}

	/** @inheritDoc */
	protected function simplePrefixSearch( $search ) {
		$namespaces = $this->namespaces;
		$limit = $this->limit;
		$offset = $this->offset;

		// Copied and modified from TitlePrefixSearch::defaultSearchBackend
		if ( !$namespaces ) {
			$namespaces = [ NS_MAIN ];
		}

		if ( in_array( NS_SPECIAL, $namespaces ) ) {
			// For now, if special is included, ignore the other namespaces
			return parent::simplePrefixSearch( $search );
		}

		// Construct suitable prefix for each namespace. They differ in cases where
		// some namespaces always capitalize and some don't.
		$prefixes = [];
		// Allow to do a prefix search for e.g. "Talk:"
		if ( $search === '' ) {
			$prefixes[$search] = $namespaces;
		} else {
			// Don't just ignore input like "[[Foo]]", but try to search for "Foo"
			$search = preg_replace( MediaWikiTitleCodec::getTitleInvalidRegex(), '', $search );
			foreach ( $namespaces as $namespace ) {
				$title = Title::makeTitleSafe( $namespace, $search );
				if ( $title ) {
					$prefixes[ $title->getDBkey() ][] = $namespace;
				}
			}
		}
		if ( !$prefixes ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		// Often there is only one prefix that applies to all requested namespaces,
		// but sometimes there are two if some namespaces do not always capitalize.
		$conds = [];
		foreach ( $prefixes as $prefix => $namespaces ) {
			$condition = [ 'page_namespace' => $namespaces ];
			if ( $prefix !== '' ) {
				$condition[] = 'page_title' . $dbr->buildLike( $prefix, $dbr->anyString() );
			}
			$conds[] = $dbr->makeList( $condition, LIST_AND );
		}

		// alias remote_page field names to match that of the page table so that newTitleArrayFromResult() works,
		// except omit page_id since the page doesn't exist locally and we don't want to cause id collisions
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace' => 'rp_namespace', 'page_title' => 'rp_title' ] )
			->from( 'remote_page' )
			->where( $dbr->orExpr( $conds ) )
			->orderBy( [ 'page_title', 'page_namespace' ] )
			->limit( $limit )
			->offset( $offset );
		$res = $queryBuilder->caller( __METHOD__ )->fetchResultSet();

		return iterator_to_array( new TitleArrayFromResult( $res ) );
	}
}
