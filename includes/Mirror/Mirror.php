<?php

namespace WikiMirror\Mirror;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Interwiki\InterwikiLookup;
use Status;
use Title;
use WANObjectCache;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\API\ParseResponse;
use WikiMirror\API\SiteInfoResponse;

class Mirror {
	/** @var string[] */
	public const CONSTRUCTOR_OPTIONS = [
		'ArticlePath',
		'ScriptPath',
		'Server',
		'TranscludeCacheExpiry',
		'WikiMirrorRemote'
	];

	/** @var string Group for process cache (1000 entries stored) */
	public const PC_GROUP = 'mirror:1000';

	/** @var ServiceOptions */
	protected $options;

	/** @var InterwikiLookup */
	protected $interwikiLookup;

	/** @var HttpRequestFactory */
	protected $httpRequestFactory;

	/** @var WANObjectCache */
	protected $cache;

	/**
	 * Mirror constructor.
	 *
	 * @param InterwikiLookup $interwikiLookup
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param WANObjectCache $wanObjectCache
	 * @param ServiceOptions $options
	 */
	public function __construct(
		InterwikiLookup $interwikiLookup,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $wanObjectCache,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->interwikiLookup = $interwikiLookup;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $wanObjectCache;
		$this->options = $options;
	}

	/**
	 * Retrieve remote URL (for end user viewing) of the given title.
	 *
	 * @param string $title
	 * @return string
	 */
	public function getPageUrl( string $title ) {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );

		return $interwiki->getURL( $title );
	}

	/**
	 * Retrieve cached page information, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param Title $title Title to fetch
	 * @return Status On success, page data from remote API as a PageInfoResponse
	 */
	public function getCachedPage( Title $title ) {
		if ( !$title->isValid() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $title->getPrefixedText() );
		}

		$id = hash( 'sha256', $title->getPrefixedText() );
		$pageName = $title->getPrefixedText();
		wfDebugLog( 'WikiMirror', "Retrieving cached info for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-info', $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $title, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Info not found in cache." );
				return $this->getLivePage( $title );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( !$value ) {
			return Status::newFatal( 'wikimirror-no-mirror', $title->getPrefixedText() );
		}

		return Status::newGood( new PageInfoResponse( $this, $value ) );
	}

	/**
	 * Retrieve cached page text, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param Title $title Title to fetch
	 * @return Status On success, page text from remote API as a ParseResponse
	 */
	public function getCachedText( Title $title ) {
		$id = hash( 'sha256', $title->getPrefixedText() );
		$pageName = $title->getPrefixedText();
		wfDebugLog( 'WikiMirror', "Retrieving cached text for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-text', $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $title, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Text not found in cache." );
				return $this->getLiveText( $title );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		$pageInfo = $this->getCachedPage( $title );

		if ( !$value || !$pageInfo->isOK() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $title->getPrefixedText() );
		}

		return Status::newGood( new ParseResponse( $this, $value, $pageInfo->getValue() ) );
	}

	/**
	 * Fetch rendered HTML and raw wikitext from remote wiki API.
	 * Do not directly call this; call getCachedText() instead.
	 *
	 * On success, the returned array looks like the following:
	 * @code
	 * [
	 *    "title" => "Page title",
	 *    "pageid" => 1234,
	 *    "text" => "Rendered HTML of page"
	 *    "langlinks" => [
	 *      [
	 *        "lang" => "de",
	 *        "url" => "URL of remote language page",
	 *        "langname" => "German",
	 *        "autonym" => "Not sure what this is",
	 *        "title" => "Name of remote language page"
	 *      ],
	 *      ...
	 *    ],
	 *    "categories" => [
	 *      [
	 *        "sortkey" => "Sort key or empty string",
	 *        "hidden" => true, // omitted if category is not hidden
	 *        "category" => "Category db key (unprefixed)"
	 *      ],
	 *      ...
	 *    ],
	 *    "modules" => [
	 *      "ext.module1",
	 *      ...
	 *    ],
	 *    "modulescripts" => [
	 *      "ext.module1.scripts",
	 *      ...
	 *    ],
	 *    "modulestyles" => [
	 *      "ext.module1.styles",
	 *      ...
	 *    ],
	 *    "jsconfigvars" => [
	 *      "var1" => "value",
	 *      ...
	 *    ],
	 *    "indicators" => [
	 *      "Indicator name" => "Indicator HTML",
	 *      ...
	 *    ],
	 *    "wikitext" => "Wikitext",
	 *    "properties" => [
	 *      "Property name" => "Property value",
	 *      ...
	 *    ]
	 * ]
	 * @endcode
	 *
	 * @param Title $title Title to fetch
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedText()
	 */
	public function getLiveText( Title $title ) {
		$status = $this->getCachedPage( $title );
		if ( !$status->isOK() ) {
			return null;
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();

		$params = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'parse',
			'oldid' => $pageInfo->lastRevisionId,
			'prop' => 'text|langlinks|categories|modules|jsconfigvars|indicators|wikitext|properties',
			'disablelimitreport' => true,
			'disableeditsection' => true
		];

		return $this->getRemoteApiResponse( $params, __METHOD__ );
	}

	/**
	 * Fetch page information from remote wiki API.
	 * Do not directly call this; call getCachedPage() instead.
	 *
	 * On success, the returned array looks like the following:
	 * @code
	 * [
	 *   "pageid" => 1423,
	 *   "ns" => 0,
	 *   "title" => "Main Page",
	 *   "contentmodel" => "wikitext",
	 *   "pagelanguage" => "en",
	 *   "pagelanguagehtmlcode" => "en",
	 *   "pagelanguagedir" => "ltr",
	 *   "touched" => "2020-07-23T13:18:52Z",
	 *   "lastrevid" => 5875,
	 *   "length" => 23,
	 *   "redirect" => true,
	 *   "displaytitle" => "Main Page"
	 * ]
	 * @endcode
	 *
	 * @param Title $title Title to fetch
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedPage()
	 */
	private function getLivePage( Title $title ) {
		// We say that the title can be mirrored if:
		// 1. The title exists on the remote wiki
		// 2. It is not a sensitive page (MediaWiki:*, user css/js/json pages)
		// 3. It is not in a special namespace (Special, Media); remote Media
		//    pages are handled via InstantCommons instead of this extension.
		// If any of these checks fail, we do not cache any values

		if ( $title->isExternal()
			|| $title->getNamespace() < 0
			|| $title->getNamespace() === NS_MEDIAWIKI
			|| $title->getNamespace() === NS_FILE
			|| $title->isUserConfigPage()
		) {
			// title refers to an interwiki page or a sensitive page
			// cache a null value here so we don't need to continually carry out these checks
			wfDebugLog( 'WikiMirror',
				"{$title->getPrefixedText()} is an external or sensitive page; not mirroring." );
			return null;
		}

		// check if the remote page exists
		$params = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			'prop' => 'info|revisions|links',
			'indexpageids' => 1,
			'inprop' => 'displaytitle',
			'rvdir' => 'older',
			'rvlimit' => 1,
			'rvprop' => 'ids|timestamp|user|userid|size|slotsize|sha1|slotsha1|contentmodel|comment',
			'rvslots' => '*',
			'pllimit' => 1,
			'titles' => $title->getPrefixedText()
		];

		$data = $this->getRemoteApiResponse( $params, __METHOD__ );

		if ( isset( $data['interwiki'] ) ) {
			// cache the failure since there's no reason to query for an interwiki multiple times.
			wfDebug( "{$title->getPrefixedText()} is an interwiki on remote mirror." );
			return null;
		}

		if ( $data['pageids'][0] == '-1' ) {
			// == instead of === is intentional; right now the API returns a string for the page id
			// but I'd rather not rely on that behavior. This lets the -1 be coerced to int if required.
			// This indicates the page doesn't exist on the remote, so cache that failure result.
			wfDebug( "{$title->getPrefixedText()} doesn't exist on remote mirror." );
			return null;
		}

		// have an actual page id, which means the title exists on the remote
		// cache the API response so we have the data available for future calls on the same title
		return $data['pages'][0];
	}

	/**
	 * Retrieve meta information about the remote wiki, potentially from cache.
	 *
	 * @return Status
	 */
	public function getCachedSiteInfo() {
		wfDebugLog( 'WikiMirror', "Retrieving cached site info." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-site-info' ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) {
				wfDebugLog( 'WikiMirror', "Site info not found in cache." );
				return $this->getLiveSiteInfo();
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( !$value ) {
			return Status::newFatal( 'wikimirror-api-error' );
		}

		return Status::newGood( new SiteInfoResponse( $this, $value ) );
	}

	/**
	 * Retrieve meta information about the remote wiki.
	 *
	 * @return false|array
	 */
	private function getLiveSiteInfo() {
		$params = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'general|namespaces|namespacealiases'
		];

		return $this->getRemoteApiResponse( $params, __METHOD__ );
	}

	/**
	 * Determine whether or not the given title is eligible to be mirrored.
	 *
	 * @param Title $title
	 * @return bool True if the title can be mirrored, false if not.
	 */
	public function canMirror( Title $title ) {
		return ( !$title->exists() || !$title->getLatestRevID() ) && $this->getCachedPage( $title )->isOK();
	}

	/**
	 * Call remote VE API and retrieve the results from it.
	 * This is not cached.
	 *
	 * @param array $params Params to pass through to remote API
	 * @return array|false API response, or false on failure
	 */
	public function getVisualEditorApi( array $params ) {
		$params['action'] = 'visualeditor';
		$params['format'] = 'json';
		$params['formatversion'] = 2;

		$result = $this->getRemoteApiResponse( $params, __METHOD__ );
		if ( $result !== false ) {
			// fix <base> tag in $result['content']
			$base = $this->options->get( 'Server' ) .
				str_replace( '$1', '', $this->options->get( 'ArticlePath' ) );

			$result['content'] = preg_replace(
				'#<base href=".*?"#',
				"<base href=\"{$base}\"",
				$result['content']
			);

			// fix load.php URLs in $result['content']
			$script = $this->options->get( 'ScriptPath' );
			$result['content'] = preg_replace(
				'#="[^"]*?/load.php#',
				"=\"{$script}/load.php",
				$result['content']
			);
		}

		return $result;
	}

	/**
	 * Execute a remote API request
	 *
	 * @param array $params API params
	 * @param string $caller Pass __METHOD__
	 * @return false|array
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function getRemoteApiResponse( array $params, string $caller ) {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		if ( $remoteWiki === null ) {
			// no remote wiki configured, so we can't mirror anything
			wfLogWarning( '$wgWikiMirrorRemote not configured.' );
			return false;
		}

		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );
		if ( $interwiki === null || $interwiki === false ) {
			// invalid interwiki configuration
			wfLogWarning( 'Invalid interwiki configuration for $wgWikiMirrorRemote.' );
			return false;
		}

		$apiUrl = $interwiki->getAPI();
		$action = $params['action'];
		$res = $this->httpRequestFactory->get( wfAppendQuery( $apiUrl, $params ), [], $caller );

		if ( $res === null ) {
			// API error
			wfWarn( "Error with remote mirror API action={$action}." );
			return false;
		}

		$data = json_decode( $res, true );

		return $data[$action];
	}
}
