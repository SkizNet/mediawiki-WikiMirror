<?php

namespace WikiMirror\API;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBacklinksprop;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;

class ApiQueryRedirects extends ApiQueryBacklinksprop {
	public function __construct(
		ApiQuery $query,
		string $moduleName,
		LinksMigration $linksMigration
	) {
		parent::__construct( $query, $moduleName, $linksMigration );
	}

	public function execute() {
		parent::execute();
		$this->addMirroredRedirectInfo();
	}

	protected function setContinueEnumParameter( $paramName, $paramValue ) {
		if ( substr_count( $paramValue, '|' ) === 1 ) {
			// add mirror flag; this came from core code so it's always set to 0
			$paramValue .= '|0';
		}

		parent::setContinueEnumParameter( $paramName, $paramValue );
	}

	protected function parseContinueParamOrDie( string $continue, array $types ): array {
		// this function is only called by core. If we're trying to continue with mirrored pages,
		// have it fetch the first page of non-mirrored (since mirrored displays first)
		$parts = explode( '|', $continue );
		if ( count( $parts ) > 1 ) {
			$mirror = array_pop( $parts );
			if ( $mirror === '0' ) {
				$continue = implode( '|', $parts );
			} elseif ( $mirror === '1' ) {
				array_pop( $parts );
				$parts[] = '0';
				$continue = implode( '|', $parts );
			} else {
				$this->dieWithError( 'apierror-badcontinue' );
			}
		}

		return parent::parseContinueParamOrDie( $continue, $types );
	}

	/**
	 * Add information about mirrored redirects to action=query&prop=redirects.
	 */
	private function addMirroredRedirectInfo() {
		$params = $this->extractRequestParams();

		$result = $this->getResult();
		$pages = $result->getResultData(
			[ 'query', 'pages' ],
			[
				// don't apply backwards-compat transformations to results
				'BC' => [ 'nobool', 'no*', 'nosub' ],
				// recursively strip all metadata
				'Strip' => 'all'
			]
		);

		if ( !is_array( $pages ) ) {
			return;
		}

		$dbr = $this->getDB();

		// target pages to include in our query, mapping of ns => [ name1, name2, ... ]
		$include = [];

		// clauses for the query
		$where = [];
		$orderby = [];

		// figure out if we need to include rdcontinue
		$pageSet = $this->getQuery()->getPageSet();
		$pageTitles = $pageSet->getGoodAndMissingPages();
		$pageMap = $pageSet->getGoodAndMissingTitlesByNamespace();

		foreach ( $pageSet->getSpecialPages() as $id => $title ) {
			$pageMap[$title->getNamespace()][$title->getDBkey()] = $id;
			$pageTitles[] = $title;
		}

		if ( count( $pageMap ) > 1 ) {
			$orderby[] = 'rr_namespace';
		}

		$theTitle = null;
		foreach ( $pageMap as $nsTitles ) {
			$key = array_key_first( $nsTitles );
			$theTitle ??= $key;
			if ( count( $nsTitles ) > 1 || $key !== $theTitle ) {
				$orderby[] = 'rr_title';
				break;
			}
		}

		$orderby[] = 'rr_from';
		unset( $pageSet, $pageTitles, $pageMap );

		if ( isset( $params['rdcontinue'] ) ) {
			// formatting was already validated in execute()
			$continue = explode( '|', $params['rdcontinue'] );
			$mirror = array_pop( $continue );
			if ( $mirror === '1' ) {
				$continue = array_combine( $orderby, $continue );
				$where[] = $dbr->buildComparison( '>=', $continue );
			}
		}

		$saved = [];
		$map = [];

		// Check for mirrored redirects to these pages and add them in.
		// Mirrored redirects are listed first (before all regular redirects are enumerated).
		foreach ( $pages as $i => $page ) {
			$pageNs = $page['ns'];
			$offset = strpos( $page['title'], ':' );
			if ( $offset === false ) {
				$offset = -1;
			}

			$pageTitle = substr( $page['title'], $offset + 1 );

			$title = Title::makeTitleSafe( $pageNs, $pageTitle );
			if ( $title === null ) {
				// invalid title somehow?
				wfDebugLog( 'WikiMirror', "Invalid Title ns=$pageNs title=$pageTitle" );
				continue;
			}

			// retrieve normalized data
			$pageNs = $title->getNamespace();
			$pageTitle = $title->getDBkey();

			$map["{$pageNs}#{$pageTitle}"] = $i;

			// save off and remove original redirect results
			if ( array_key_exists( 'redirects', $page ) ) {
				foreach ( $page['redirects'] as $redirect ) {
					$saved[] = [
						0 => $pageNs,
						1 => $pageTitle,
						2 => $redirect['pageid'],
						'rr_namespace' => $pageNs,
						'rr_title' => $pageTitle,
						'rr_from' => $redirect['pageid'],
						'data' => $redirect
					];
				}

				$result->removeValue( [ 'query', 'pages', $i, 'redirects' ], null );
			}

			$include[$pageNs][] = $pageTitle;
		}

		foreach ( $include as $ns => $pages ) {
			$where[] = $dbr->makeList( [
				'rr_namespace' => $ns,
				'rr_title' => $pages
			], IDatabase::LIST_AND );
		}

		$res = $dbr->select(
			[ 'remote_redirect', 'remote_page' ],
			[ 'rr_from', 'rr_namespace', 'rr_title', 'rp_namespace', 'rp_title' ],
			$dbr->makeList( $where, IDatabase::LIST_OR ),
			__METHOD__,
			[
				'LIMIT' => $params['limit'] + 1,
				'ORDER BY' => $orderby
			],
			[ 'remote_page' => [ 'INNER JOIN', 'rr_from = rp_id' ] ]
		);

		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// hit our limit, add a continue
				$mc = [];
				foreach ( $orderby as $field ) {
					$mc[] = $row->$field;
				}

				// mark this as a mirrored continue
				$mc[] = '1';

				$this->setContinueEnumParameter( 'continue', implode( '|', $mc ) );
				return;
			}

			$path = [ 'query', 'pages', $map["{$row->rr_namespace}#{$row->rr_title}"], 'redirects' ];
			$result->addValue( $path, null, [
				'ns' => (int)$row->rp_namespace,
				'title' => $row->rp_title,
				'missing' => true,
				'known' => true
			] );
		}

		usort( $saved, static function ( $left, $right ) {
			for ( $i = 0; $i < 3; ++$i ) {
				$c = $left[$i] <=> $right[$i];
				if ( $c !== 0 ) {
					return $c;
				}
			}

			return 0;
		} );

		while ( $count < $params['limit'] ) {
			$next = array_shift( $saved );
			if ( $next === null ) {
				break;
			}

			++$count;
			$path = [ 'query', 'pages', $map["{$next[0]}#{$next[1]}"], 'redirects' ];
			$result->addValue( $path, null, $next['data'] );
		}

		$next = array_shift( $saved );
		if ( $next !== null ) {
			// update rdcontinue to begin with this next item
			$rc = [];
			$offset = 3 - count( $orderby );
			foreach ( $orderby as $key ) {
				$rc[] = $next[$key + $offset];
			}

			// mark as non-mirrored continue
			$rc[] = '0';

			$this->setContinueEnumParameter( 'continue', implode( '|', $rc ) );
		}
	}
}
