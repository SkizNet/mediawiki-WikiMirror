<?php

namespace WikiMirror\Search;

use MediaWiki\MediaWikiServices;
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
}
