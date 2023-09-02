<?php

namespace WikiMirror\Search;

use MediaWiki\MediaWikiServices;
use SearchEngine;
use WikiMirror\Mirror\Mirror;

class MirrorSearch extends SearchEngine {
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
			'srlimit' => $this->maxResults,
			'srwhat' => $type,
			'srprop' => 'size|wordcount|timestamp|snippet',
		];

		/* API response example:
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
		*/
		$resp = $this->mirror->getRemoteApiResponse( $params, __METHOD__ );

		if ( $resp === false ) {
			// search failed
			return null;
		}

		$results = [];
		foreach ( $resp['search'] as $result ) {
			$results[] = new MirrorSearchResult( $result, $type );
		}

		return new MirrorSearchResultSet( $results );
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
