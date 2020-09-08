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

class Mirror {
	/** @var string[] */
	public const CONSTRUCTOR_OPTIONS = [
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
		$id = hash( 'sha256', $title->getPrefixedText() );
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-info', $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $title ) {
				return $this->getLivePage( $title );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( $value === null ) {
			return Status::newFatal( 'wikimirror-no-mirror', $title->getPrefixedText() );
		}

		return Status::newGood( new PageInfoResponse( $value ) );
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
		$value = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'mirror', 'remote-text', $id ),
			$this->options->get( 'TranscludeCacheExpiry' ),
			function ( $oldValue, &$ttl, &$setOpts, $oldAsOf ) use ( $title ) {
				return $this->getLiveText( $title );
			},
			[
				'pcTTL' => $this->cache::TTL_PROC_LONG,
				'pcGroup' => self::PC_GROUP,
				'staleTTL' => $this->cache::TTL_DAY
			]
		);

		if ( $value === null ) {
			return Status::newFatal( 'wikimirror-no-mirror', $title->getPrefixedText() );
		}

		return Status::newGood( new ParseResponse( $value ) );
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
	 *    "text" => [
	 *      "*" => "Rendered HTML of page"
	 *    ],
	 *    "langlinks" => [
	 *      [
	 *        "lang" => "de",
	 *        "url" => "URL of remote language page",
	 *        "langname" => "German",
	 *        "autonym" => "Not sure what this is",
	 *        "*" => "Name of remote language page"
	 *      ],
	 *      ...
	 *    ],
	 *    "categories" => [
	 *      [
	 *        "sortkey" => "Sort key or empty string",
	 *        "hidden" => "", // omitted if category is not hidden
	 *        "*" => "Category db key (unprefixed)"
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
	 *      [
	 *        "name" => "featured-star",
	 *        "*" => "HTML of indicator"
	 *      ],
	 *      ...
	 *    ],
	 *    "wikitext" => [
	 *      "*" => "Wikitext"
	 *    ],
	 *    "properties" => [
	 *      [
	 *        "name" => "displaytitle",
	 *        "*" => "Property value"
	 *      ],
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

		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );
		$apiUrl = $interwiki->getAPI();
		$params = [
			'format' => 'json',
			'action' => 'parse',
			'oldid' => $pageInfo->lastRevisionId,
			'prop' => 'text|langlinks|categorieshtml|modules|jsconfigvars|indicators|wikitext|properties',
			'disablelimitreport' => true,
			'disableeditsection' => true
		];

		$res = $this->httpRequestFactory->get( wfAppendQuery( $apiUrl, $params ), [], __METHOD__ );

		if ( $res === null ) {
			// API error
			return false;
		}

		$data = json_decode( $res, true );

		return $data['parse'];
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
	 *   "redirect" => "",
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

		$remoteWiki = $this->options->get( 'WikiMirrorRemote' );
		if ( $remoteWiki === null ) {
			// no remote wiki configured, so we can't mirror anything
			return false;
		}

		$interwiki = $this->interwikiLookup->fetch( $remoteWiki );
		if ( $interwiki === null || $interwiki === false ) {
			// invalid interwiki configuration
			return false;
		}

		if ( $title->isExternal()
			|| $title->getNamespace() < 0
			|| $title->getNamespace() === NS_MEDIAWIKI
			|| $title->getNamespace() === NS_FILE
			|| $title->isUserConfigPage()
		) {
			// title refers to an interwiki page or a sensitive page
			// cache a null value here so we don't need to continually carry out these checks
			return null;
		}

		// check if the remote page exists
		$apiUrl = $interwiki->getAPI();
		$params = [
			'format' => 'json',
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

		$res = $this->httpRequestFactory->get( wfAppendQuery( $apiUrl, $params ), [], __METHOD__ );

		if ( $res === null ) {
			// API error
			return false;
		}

		$data = json_decode( $res, true );
		if ( $data['query']['pageids'][0] == '-1' ) {
			// == instead of === is intentional; right now the API returns a string for the page id
			// but I'd rather not rely on that behavior. This lets the -1 be coerced to int if required.
			// This indicates the page doesn't exist on the remote, so cache that failure result.
			return null;
		}

		// have an actual page id, which means the title exists on the remote
		// cache the API response so we have the data available for future calls on the same title
		return $data['query']['pages'][$data['query']['pageids'][0]];
	}

	/**
	 * Determine whether or not the given title is eligible to be mirrored.
	 * This does not check local fork status.
	 *
	 * @param Title $title
	 * @return bool True if the title can be mirrored, false if not.
	 */
	public function canMirror( Title $title ) {
		return $this->getCachedPage( $title )->isOK();
	}
}
