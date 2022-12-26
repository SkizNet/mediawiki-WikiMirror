<?php

namespace WikiMirror\API;

use ApiBase;
use ApiUsageException;
use MWException;
use Title;
use WikiMirror\Compat\ReflectionHelper;
use WikiMirror\Mirror\Mirror;

class ApiPageSet extends \ApiPageSet {
	/** @var Mirror */
	private $mirror;

	/** @var array */
	private $params;

	/**
	 * ApiPageSet constructor.
	 *
	 * @param ApiBase $dbSource
	 * @param Mirror $mirror
	 * @throws ApiUsageException
	 */
	public function __construct( ApiBase $dbSource, Mirror $mirror ) {
		parent::__construct( $dbSource );
		$this->mirror = $mirror;
		$this->params = $this->extractRequestParams();
	}

	/**
	 * Perform a normal run (not a dry run). If we were passed titles
	 * as the data source and are resolving redirects, do so with any
	 * mirrored titles passed into us; registering them as special page
	 * redirects so that we don't try to add them to the database.
	 *
	 * @return void
	 * @throws MWException On MW compatibility error
	 */
	public function execute() {
		$redirects = [];
		if ( $this->getDataSource() === 'titles' && $this->isResolvingRedirects() ) {
			$redirects = $this->resolveMirroredRedirects();
		}

		// run default logic
		parent::execute();

		// omit mirrored redirects from the result set
		$parent = \ApiPageSet::class;
		$allPages = ReflectionHelper::getPrivateProperty( $parent, 'mAllPages', $this );
		$titles = ReflectionHelper::getPrivateProperty( $parent, 'mTitles', $this );
		$goodAndMissingPages = ReflectionHelper::getPrivateProperty( $parent, 'mGoodAndMissingPages', $this );
		$missingPages = ReflectionHelper::getPrivateProperty( $parent, 'mMissingPages', $this );
		$missingTitles = ReflectionHelper::getPrivateProperty( $parent, 'mMissingTitles', $this );

		foreach ( $redirects as [ $from, $to ] ) {
			/** @var Title $from */
			/** @var Title $to */
			$fromNs = $from->getNamespace();
			$fromKey = $from->getDBkey();
			$toNs = $to->getNamespace();
			$toKey = $to->getDBkey();
			if ( isset( $allPages[$fromNs][$fromKey] )
				&& isset( $allPages[$toNs][$toKey] )
				&& $allPages[$fromNs][$fromKey] < 0
			) {
				$filter = static function ( Title $title ) use ( $fromNs, $fromKey ) {
					return $title->getNamespace() !== $fromNs || $title->getDBkey() !== $fromKey;
				};

				unset( $allPages[$fromNs][$fromKey] );
				unset( $goodAndMissingPages[$fromNs][$fromKey] );
				unset( $missingPages[$fromNs][$fromKey] );
				$titles = array_filter( $titles, $filter );
				$missingTitles = array_filter( $missingTitles, $filter );
			}
		}

		ReflectionHelper::setPrivateProperty( $parent, 'mAllPages', $this, $allPages );
		ReflectionHelper::setPrivateProperty( $parent, 'mTitles', $this, $titles );
		ReflectionHelper::setPrivateProperty( $parent, 'mGoodAndMissingPages', $this, $goodAndMissingPages );
		ReflectionHelper::setPrivateProperty( $parent, 'mMissingPages', $this, $missingPages );
		ReflectionHelper::setPrivateProperty( $parent, 'mMissingTitles', $this, $missingTitles );
	}

	/**
	 * Check if any of the titles passed to us are being mirrored, and if so
	 * whether or not they are redirects. If they are, fully resolve them.
	 *
	 * @return array all resolved redirects
	 * @throws MWException On MW compatibility error
	 */
	private function resolveMirroredRedirects() {
		$redirects = [];

		foreach ( $this->params['titles'] as $titleStr ) {
			$title = Title::newFromText( $titleStr );
			if ( $title === null ) {
				continue;
			}

			while ( true ) {
				$target = $this->mirror->getRedirectTarget( $title );
				if ( $target === null ) {
					break;
				}

				// add redirect entry
				$targetTitle = Title::newFromLinkTarget( $target );
				$redirects[$title->getPrefixedDBkey()] = [ $title, $targetTitle ];

				// if we hit a loop, stop
				if ( isset( $redirects[$targetTitle->getPrefixedDBkey()] ) ) {
					break;
				}

				// continue processing the redirect chain
				$title = $targetTitle;
			}
		}

		// update redirect information for ApiPageSet
		ReflectionHelper::setPrivateProperty(
			\ApiPageSet::class,
			'mPendingRedirectSpecialPages',
			$this,
			$redirects
		);

		return $redirects;
	}
}
