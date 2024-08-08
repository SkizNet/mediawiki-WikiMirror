<?php

namespace WikiMirror\Mirror;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Title\TitleFormatter;
use Status;
use Title;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\UUID\GlobalIdGenerator;
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

	/** @var int Version of data stored in process cache (increment to invalidate all existing cache entries) */
	public const PC_VERSION = 3;

	/** @var ServiceOptions */
	protected ServiceOptions $options;

	/** @var InterwikiLookup */
	protected InterwikiLookup $interwikiLookup;

	/** @var HttpRequestFactory */
	protected HttpRequestFactory $httpRequestFactory;

	/** @var WANObjectCache */
	protected WANObjectCache $cache;

	/** @var RedirectLookup */
	protected RedirectLookup $redirectLookup;

	/** @var ILoadBalancer */
	protected ILoadBalancer $loadBalancer;

	/** @var GlobalIdGenerator */
	protected GlobalIdGenerator $globalIdGenerator;

	/** @var LanguageFactory */
	protected LanguageFactory $languageFactory;

	protected TitleFormatter $titleFormatter;

	/** @var array<string, string> */
	private array $titleCache;

	/**
	 * @var array<string, ?MirrorPageRecord>
	 */
	private array $pageRecordCache;

	/**
	 * Mirror constructor.
	 *
	 * @param InterwikiLookup $interwikiLookup
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param WANObjectCache $wanObjectCache
	 * @param ILoadBalancer $loadBalancer
	 * @param RedirectLookup $redirectLookup
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param LanguageFactory $languageFactory
	 * @param TitleFormatter $titleFormatter
	 * @param ServiceOptions $options
	 */
	public function __construct(
		InterwikiLookup $interwikiLookup,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $wanObjectCache,
		ILoadBalancer $loadBalancer,
		RedirectLookup $redirectLookup,
		GlobalIdGenerator $globalIdGenerator,
		LanguageFactory $languageFactory,
		TitleFormatter $titleFormatter,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->interwikiLookup = $interwikiLookup;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $wanObjectCache;
		$this->loadBalancer = $loadBalancer;
		$this->redirectLookup = $redirectLookup;
		$this->globalIdGenerator = $globalIdGenerator;
		$this->languageFactory = $languageFactory;
		$this->titleFormatter = $titleFormatter;

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
	 * Retrieve wiki id of the remote wiki for internal use.
	 *
	 * @return string
	 */
	public function getWikiId() {
		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );

		return $interwiki->getWikiID();
	}

	/**
	 * Retrieve cached page information, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param PageIdentity $page Page to fetch
	 * @return Status On success, page data from remote API as a PageInfoResponse
	 */
	public function getCachedPage( PageIdentity $page ) {
		$pageName = $this->titleFormatter->getPrefixedText( $page );
		if ( !$page->canExist() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		$id = hash( 'sha256', $pageName );
		wfDebugLog( 'WikiMirror', "Retrieving cached info for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-info', self::PC_VERSION, $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $page, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Info not found in cache." );
				return $this->getLivePage( $page );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( !$value ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		return Status::newGood( new PageInfoResponse( $this, $value ) );
	}

	/**
	 * Retrieve cached page text, refreshing the cache as necessary.
	 * The data is cached for $wgTranscludeCacheExpiry time, although stale data
	 * may be returned if we are unable to contact the remote wiki.
	 *
	 * @param PageIdentity $page Title to fetch
	 * @return Status On success, page text from remote API as a ParseResponse
	 */
	public function getCachedText( PageIdentity $page ) {
		$pageName = $this->titleFormatter->getPrefixedText( $page );
		$id = hash( 'sha256', $pageName );
		wfDebugLog( 'WikiMirror', "Retrieving cached text for {$pageName}." );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-text', self::PC_VERSION, $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $page, $pageName ) {
				wfDebugLog( 'WikiMirror', "{$pageName}: Text not found in cache." );
				return $this->getLiveText( $page );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		$pageInfo = $this->getCachedPage( $page );

		if ( !$value || !$pageInfo->isOK() ) {
			return Status::newFatal( 'wikimirror-no-mirror', $pageName );
		}

		return Status::newGood( new ParseResponse(
			$this,
			$value,
			$pageInfo->getValue(),
			$this->globalIdGenerator,
			$this->languageFactory
		) );
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
	 * Determine whether this Title is completely ineligible for mirroring,
	 * without checking if it exists anywhere.
	 *
	 * @param PageIdentity $page
	 * @return bool True if the Title is legal for mirroring, false otherwise
	 */
	private function isLegalTitleForMirroring( PageIdentity $page ) {
		$title = Title::newFromPageIdentity( $page );
		$illegal = $title->isExternal()
			|| $title->getNamespace() < 0
			|| $title->getNamespace() === NS_MEDIAWIKI
			|| $title->getNamespace() === NS_FILE
			|| $title->getNamespace() === NS_PROJECT
			|| $title->getNamespace() === NS_PROJECT_TALK
			|| $title->isUserConfigPage();

		return !$illegal;
	}

	/**
	 * Determine whether the given title is currently forked.
	 *
	 * @param Title $title
	 * @return bool
	 */
	public function isForked( Title $title ) {
		// prime the title cache
		$this->canMirror( $title, true );

		$cacheKey = $title->getPrefixedDBkey();
		return $this->titleCache[$cacheKey] === 'forked';
	}

	/**
	 * Retrieve a PageRecord for a mirrored page. This will still return records for forked pages,
	 * and must be paired with a call to canMirror() to determine whether the returned record
	 * should be used.
	 *
	 * @param int $namespace Namespace ID
	 * @param string $dbKey DB key, assumed to be valid
	 * @return MirrorPageRecord|null The record, or null if the page does not exist remotely
	 */
	public function getMirrorPageRecord( int $namespace, string $dbKey ): ?MirrorPageRecord {
		$cacheKey = "{$namespace}:{$dbKey}";
		if ( array_key_exists( $cacheKey, $this->pageRecordCache ) ) {
			return $this->pageRecordCache[$cacheKey];
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'remote_page' )
			->leftJoin( 'remote_redirect', null, 'rp_id = rr_from' )
			->where( [
				'rp_namespace' => $namespace,
				'rp_title' => $dbKey
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false ) {
			$this->pageRecordCache[$cacheKey] = null;
			return null;
		}

		$record = new MirrorPageRecord( $row, $this );
		$this->pageRecordCache[$cacheKey] = $record;
		return $record;
	}

	/**
	 * Determine whether or not the given title is eligible to be mirrored.
	 *
	 * @param PageIdentity $page
	 * @param bool $fast If true, skip expensive checks
	 * @return bool True if the title can be mirrored, false if not.
	 */
	public function canMirror( PageIdentity $page, bool $fast = false ) {
		$cacheKey = $this->titleFormatter->getPrefixedText( $page );

		if ( isset( $this->titleCache[$cacheKey] ) ) {
			$cachedResult = $this->titleCache[$cacheKey];
			if ( $fast || !str_starts_with( $cachedResult, 'fast_' ) ) {
				return $cachedResult === 'valid' || $cachedResult === 'fast_valid';
			}
		}

		// Force recursive calls to exit early (e.g. from Title::exists) with a false value
		// so that fallbacks to core MW logic are run instead in such situations.
		// Would be better if we could refactor to not need recursion at all, but that's more complicated
		// while we're still using Titles here.
		$this->titleCache[$cacheKey] = 'recursion_guard';

		if ( !$this->isLegalTitleForMirroring( $page ) ) {
			$this->titleCache[$cacheKey] = 'illegal_title';
			return false;
		}

		if ( $page->exists() ) {
			// page exists locally
			$this->titleCache[$cacheKey] = 'forked';
			return false;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$result = $dbr->selectField( 'forked_titles', 'COUNT(1)', [
			'ft_namespace' => $page->getNamespace(),
			'ft_title' => $page->getDBkey()
		], __METHOD__ );

		if ( $result > 0 ) {
			// title has been forked locally despite the page not existing
			$this->titleCache[$cacheKey] = 'forked';
			return false;
		}

		if ( $fast ) {
			$result = $this->getMirrorPageRecord( $page->getNamespace(), $page->getDBkey() );
			$exists = $result !== null;
			$this->titleCache[$cacheKey] = $exists ? 'fast_valid' : 'fast_errored';
			return $exists;
		} elseif ( !$this->getCachedPage( $page )->isOK() ) {
			// not able to successfully fetch the mirrored page
			$this->titleCache[$cacheKey] = 'errored';
			return false;
		}

		$this->titleCache[$cacheKey] = 'valid';
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
	 * Convenience function to retrieve the redirect target of a potentially mirrored page.
	 *
	 * @param Title $title Title to retrieve redirect target for
	 * @return LinkTarget|null Redirect target, or null if this Title is not a redirect.
	 */
	public function getRedirectTarget( Title $title ) {
		if ( !$this->canMirror( $title ) ) {
			// page is local, call WikiPage::getRedirectTarget if it exists
			if ( !$title->exists() ) {
				return null;
			}

			return $this->redirectLookup->getRedirectTarget( $title );
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
	 * @param PageIdentity $page Title to fetch
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedText()
	 */
	private function getLiveText( PageIdentity $page ) {
		$status = $this->getCachedPage( $page );
		if ( !$status->isOK() ) {
			return null;
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();

		$params = [
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
	 * @param PageIdentity $page
	 * @return array|bool|null False upon transient failure,
	 * 		null if page can't be mirrored,
	 * 		array of information from remote wiki on success
	 * @see Mirror::getCachedPage()
	 */
	private function getLivePage( PageIdentity $page ) {
		// We say that the title can be mirrored if:
		// 1. The title exists on the remote wiki
		// 2. It is not a sensitive page (MediaWiki:*, user css/js/json pages)
		// 3. It is not in a special namespace (Special, Media); remote Media
		//    pages are handled via InstantCommons instead of this extension.
		// If any of these checks fail, we do not cache any values

		$pageName = $this->titleFormatter->getPrefixedText( $page );
		if ( !$this->isLegalTitleForMirroring( $page ) ) {
			// title refers to an interwiki page or a sensitive page
			// cache a null value here so we don't need to continually carry out these checks
			wfDebugLog( 'WikiMirror', "{$pageName} is an external or sensitive page; not mirroring." );
			return null;
		}

		// check if the remote page exists
		$params = [
			'action' => 'query',
			'prop' => 'info|revisions',
			'indexpageids' => 1,
			'inprop' => 'displaytitle',
			'rvdir' => 'older',
			'rvlimit' => 1,
			'rvprop' => 'ids|timestamp|user|userid|size|slotsize|sha1|slotsha1|contentmodel'
				. '|flags|comment|parsedcomment|content|tags|roles',
			'rvslots' => '*',
			'titles' => $pageName
		];

		$data = $this->getRemoteApiResponse( $params, __METHOD__ );
		if ( $data === false ) {
			wfDebug( "{$pageName} could not be fetched from remote mirror." );
			return null;
		}

		if ( isset( $data['interwiki'] ) ) {
			// cache the failure since there's no reason to query for an interwiki multiple times.
			wfDebug( "{$pageName} is an interwiki on remote mirror." );
			return null;
		}

		if ( $data['pageids'][0] == '-1' ) {
			// == instead of === is intentional; right now the API returns a string for the page id
			// but I'd rather not rely on that behavior. This lets the -1 be coerced to int if required.
			// This indicates the page doesn't exist on the remote, so cache that failure result.
			wfDebug( "{$pageName} doesn't exist on remote mirror." );
			return null;
		}

		// have an actual page id, which means the title exists on the remote
		// cache the API response so we have the data available for future calls on the same title
		$pageInfo = $data['pages'][0];

		// check if this is a redirect, and if so fetch information about the redirect
		if ( array_key_exists( 'redirect', $pageInfo ) && $pageInfo['redirect'] ) {
			$params = [
				'action' => 'query',
				'prop' => 'info',
				'titles' => $pageName,
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
	 * @param bool $topLevel If false (default), the returned array only includes results instead of all metadata
	 * @return false|array
	 */
	public function getRemoteApiResponse( array $params, string $caller, bool $topLevel = false ) {
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

		$params['format'] = 'json';
		$params['formatversion'] = 2;

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

		return $topLevel ? $data : $data[$action];
	}
}
