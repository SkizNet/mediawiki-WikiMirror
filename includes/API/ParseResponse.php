<?php

namespace WikiMirror\API;

use MediaWiki\Languages\LanguageFactory;
use ParserOutput;
use Title;
use Wikimedia\UUID\GlobalIdGenerator;
use WikiMirror\Mirror\Mirror;

class ParseResponse {
	/** @var string */
	public string $title;

	/** @var int */
	public int $pageId;

	/** @var string */
	public string $html;

	/** @var array */
	public array $languageLinks;

	/** @var array */
	public array $categoryLinks;

	/** @var string[] */
	public array $modules;

	/** @var string[] */
	public array $moduleScripts;

	/** @var string[] */
	public array $moduleStyles;

	/** @var array */
	public array $jsConfigVars;

	/** @var array */
	public array $indicators;

	/** @var string */
	public string $wikitext;

	/** @var array */
	public array $properties;

	/** @var PageInfoResponse */
	private PageInfoResponse $pageInfo;

	/** @var Mirror */
	private Mirror $mirror;

	/** @var GlobalIdGenerator */
	private GlobalIdGenerator $globalIdGenerator;

	/** @var LanguageFactory */
	private LanguageFactory $languageFactory;

	/**
	 * ParseResponse constructor.
	 *
	 * @param Mirror $mirror Mirror service that generated $response
	 * @param array $response Associative array of API response
	 * @param PageInfoResponse $pageInfo Page info corresponding to parsed text
	 * @param GlobalIdGenerator $globalIdGenerator Global ID generator service
	 * @param LanguageFactory $languageFactory Language factory service
	 */
	public function __construct(
		Mirror $mirror,
		array $response,
		PageInfoResponse $pageInfo,
		GlobalIdGenerator $globalIdGenerator,
		LanguageFactory $languageFactory
	) {
		$this->mirror = $mirror;
		$this->globalIdGenerator = $globalIdGenerator;
		$this->languageFactory = $languageFactory;

		$this->pageInfo = $pageInfo;
		$this->title = $response['title'];
		$this->pageId = $response['pageid'];
		$this->modules = $response['modules'];
		$this->moduleScripts = $response['modulescripts'];
		$this->moduleStyles = $response['modulestyles'];
		$this->jsConfigVars = $response['jsconfigvars'];
		$this->wikitext = $response['wikitext'];
		$this->indicators = $response['indicators'];
		$this->properties = $response['properties'];

		$this->languageLinks = [];
		foreach ( $response['langlinks'] as $languageLink ) {
			$this->languageLinks[] = $languageLink['lang'] . ':' . $languageLink['title'];
		}

		$this->categoryLinks = [];
		foreach ( $response['categories'] as $category ) {
			$this->categoryLinks[$category['category']] = $category['sortkey'];
		}

		$this->html = $this->fixLocalLinks( $response['text'] );
	}

	/**
	 * Fills a ParserOutput from remote API data.
	 *
	 * @return ParserOutput
	 */
	public function getParserOutput() {
		$output = new ParserOutput( $this->html, $this->languageLinks, $this->categoryLinks );

		if ( isset( $this->properties['displaytitle'] ) ) {
			$output->setDisplayTitle( $this->properties['displaytitle'] );
			unset( $this->properties['displaytitle'] );
		}

		foreach ( $this->indicators as $name => $html ) {
			$output->setIndicator( $name, $html );
		}

		$output->setHideNewSection( true );

		if ( $this->pageInfo->lastRevisionId ) {
			$output->setTimestamp( wfTimestamp( TS_MW, $this->pageInfo->lastRevision->timestamp ) );
		} else {
			$output->setTimestamp( wfTimestamp( TS_MW ) );
		}

		$revId = $this->pageInfo->lastRevisionId;
		$uniqueId = $this->globalIdGenerator->newUUIDv1();
		$output->setLanguage( $this->languageFactory->getLanguage( $this->pageInfo->pageLanguage ) );
		$output->setCacheRevisionId( $revId );
		$output->setCacheTime( $this->pageInfo->touched );

		if ( method_exists( $output, 'setRenderId' ) ) {
			// 1.42+
			// @phan-suppress-next-line PhanUndeclaredMethod
			$output->setRenderId( "{$revId}/{$uniqueId}" );
		} else {
			// 1.41 compat
			$output->setExtensionData( 'parsoid-render-id', "{$revId}/{$uniqueId}" );
		}

		return $output;
	}

	/**
	 * Fixes local links to conform to our own article/action paths.
	 *
	 * @param string $html
	 * @return string
	 */
	private function fixLocalLinks( $html ) {
		$status = $this->mirror->getCachedSiteInfo();
		if ( !$status->isOK() ) {
			return $html;
		}

		/** @var SiteInfoResponse $siteInfo */
		$siteInfo = $status->getValue();

		// remap remote article path to local article path
		// we support remote $wgMainPageIsDomainRoot but do not support remote $wgActionPaths or $wgVariantArticlePath
		// (local versions of the above are all supported as we go through Title to generate the new URL)
		$remotePathInfo = wfParseUrl( $siteInfo->server . $siteInfo->articlePath );
		$inPath = isset( $remotePathInfo['path'] ) && str_contains( $remotePathInfo['path'], '$1' );
		$queryKey = null;
		if ( !$inPath && isset( $remotePathInfo['query'] ) ) {
			$remotePathInfo['query'] = wfCgiToArray( $remotePathInfo['query'] );
			foreach ( $remotePathInfo['query'] as $key => $value ) {
				if ( $value === '$1' ) {
					$queryKey = $key;
					break;
				}
			}
		}

		if ( $inPath ) {
			$titleMatch = '([^"?]*)';
		} else {
			$titleMatch = '([^"&]*)';
		}

		$regex = preg_quote( $siteInfo->articlePath, '#' );
		$regex = '#<a href="(' . str_replace( '\\$1', $titleMatch, $regex ) . '[^"]*?)"#';
		return preg_replace_callback( $regex, static function ( array $matches ) use ( $siteInfo, $queryKey ) {
			$url = wfParseUrl( $siteInfo->server . html_entity_decode( $matches[1] ) );
			if ( $url === false ) {
				return $matches[0];
			}

			$query = [];
			if ( isset( $url['query'] ) && $url['query'] !== '' ) {
				$query = wfCgiToArray( $url['query'] );
				unset( $query[$queryKey] );
			}

			$titleText = urldecode( $matches[2] );
			if ( isset( $url['fragment'] ) && $url['fragment'] !== '' ) {
				$titleText .= '#' . $url['fragment'];
			}

			$title = Title::newFromText( $titleText );
			if ( $title === null ) {
				return $matches[0];
			}

			$newUrl = $title->getLinkURL( $query );
			return '<a href="' . htmlspecialchars( $newUrl ) . '"';
		}, $html );
	}
}
