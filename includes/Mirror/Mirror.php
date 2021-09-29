<?php

namespace WikiMirror\Mirror;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\MediaWikiServices;
use MWException;
use Status;
use Title;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\API\ParseResponse;
use WikiMirror\API\SiteInfoResponse;
use WikiPage;

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

	/** @var int Version of data stored in process cache (increment to invalidate all existing cache entries) */
	public const PC_VERSION = 2;

	/** @var ServiceOptions */
	protected $options;

	/** @var InterwikiLookup */
	protected $interwikiLookup;

	/** @var HttpRequestFactory */
	protected $httpRequestFactory;

	/** @var WANObjectCache */
	protected $cache;

	/** @var ILoadBalancer */
	protected $loadBalancer;

	/** @var array */
	private $titleCache;

	/**
	 * Mirror constructor.
	 *
	 * @param InterwikiLookup $interwikiLookup
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param WANObjectCache $wanObjectCache
	 * @param ILoadBalancer $loadBalancer
	 * @param ServiceOptions $options
	 */
	public function __construct(
		InterwikiLookup $interwikiLookup,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $wanObjectCache,
		ILoadBalancer $loadBalancer,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->interwikiLookup = $interwikiLookup;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $wanObjectCache;
		$this->loadBalancer = $loadBalancer;
		$this->options = $options;
		$this->titleCache = [];
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
			$this->cache->makeKey( 'mirror', 'remote-info', self::PC_VERSION, $id ),
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
			$this->cache->makeKey( 'mirror', 'remote-text', self::PC_VERSION, $id ),
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
	 * Determine whether or not the given title is eligible to be mirrored.
	 *
	 * @param Title $title
	 * @return bool True if the title can be mirrored, false if not.
	 */
	public function canMirror( Title $title ) {
		$cacheKey = $title->getPrefixedDBkey();

		if ( isset( $this->titleCache[$cacheKey] ) ) {
			return $this->titleCache[$cacheKey];
		}

		if ( $title->exists() && $title->getLatestRevID() ) {
			// page exists locally
			$this->titleCache[$cacheKey] = false;
			return false;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$result = $dbr->selectField( 'forked_titles', 'COUNT(1)', [
			'ft_namespace' => $title->getNamespace(),
			'ft_title' => $title->getDBkey()
		], __METHOD__ );

		if ( $result > 0 ) {
			// title has been forked locally despite the page not existing
			$this->titleCache[$cacheKey] = false;
			return false;
		}

		if ( !$this->getCachedPage( $title )->isOK() ) {
			// not able to successfully fetch the mirrored page
			$this->titleCache[$cacheKey] = false;
			return false;
		}

		$this->titleCache[$cacheKey] = true;
		return true;
	}

	/**
	 * Mark that a Title is about to be imported, preventing it from being mirrored.
	 *
	 * @param Title $title
	 * @return void
	 */
	public function markForImport( Title $title ) {
		$cacheKey = $title->getPrefixedDBkey();
		$this->titleCache[$cacheKey] = false;
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
	 * Retrieve all mirrored redirects to the given Title based on cached data.
	 *
	 * @param Title $title Title to check incoming redirects for
	 * @return Title[] All remote-only pages that redirect to the passed-in Title
	 */
	public function getRedirects( Title $title ) {
		// TODO: FINISH
		// We'll want to query remote_redirects to check for things that redirect to $title
		// Then we need to map that remote page id to remote_page, while also checking that
		// the page doesn't exist locally (whether in page or in forked_titles).
		return [];
	}

	/**
	 * Convenience function to retrieve the redirect target of a potentially mirrored page.
	 *
	 * @param Title $title Title to retrieve redirect target for
	 * @return Title|null Redirect target, or null if this Title is not a redirect.
	 * @throws MWException On internal error
	 */
	public function getRedirectTarget( Title $title ) {
		if ( !$this->canMirror( $title ) ) {
			// page is local, call WikiPage::getRedirectTarget if it exists
			if ( !$title->exists() ) {
				return null;
			}

			if ( method_exists( '\MediaWiki\MediaWikiServices', 'getWikiPageFactory' ) ) {
				// 1.36+
				// @phan-suppress-next-line PhanUndeclaredMethod
				$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			} else {
				// 1.35
				$wikiPage = WikiPage::factory( $title );
			}

			return $wikiPage->getRedirectTarget();
		}

		$status = $this->getCachedPage( $title );
		if ( !$status->isOK() ) {
			return null;
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();
		return $pageInfo->redirect;
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
	private function getLiveText( Title $title ) {
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
			'prop' => 'info|revisions',
			'indexpageids' => 1,
			'inprop' => 'displaytitle',
			'rvdir' => 'older',
			'rvlimit' => 1,
			'rvprop' => 'ids|timestamp|user|userid|size|slotsize|sha1|slotsha1|contentmodel|comment',
			'rvslots' => '*',
			'titles' => $title->getPrefixedText()
		];

		$data = $this->getRemoteApiResponse( $params, __METHOD__ );
		if ( $data === false ) {
			wfDebug( "{$title->getPrefixedText()} could not be fetched from remote mirror." );
			return null;
		}

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
		$pageInfo = $data['pages'][0];

		// check if this is a redirect, and if so fetch information about the redirect
		if ( array_key_exists( 'redirect', $pageInfo ) && $pageInfo['redirect'] ) {
			$params = [
				'format' => 'json',
				'formatversion' => 2,
				'action' => 'query',
				'prop' => 'info',
				'titles' => $title->getPrefixedText(),
				'redirects' => true
			];

			$data = $this->getRemoteApiResponse( $params, __METHOD__ );
			$pageInfo['redirect'] = $data['pages'][0];
		}

		return $pageInfo;
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
		if ( !is_array( $data ) || !array_key_exists( $action, $data ) ) {
			wfWarn( "Unexpected response from remote mirror API action={$action}." );
			return false;
		}

		return $data[$action];
	}
}
