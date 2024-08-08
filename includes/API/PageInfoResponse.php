<?php

namespace WikiMirror\API;

use MWException;
use Title;
use WikiMirror\Mirror\Mirror;

/**
 * Wrapper around MediaWiki's API action=query&prop=info response
 */
class PageInfoResponse {
	/** @var string */
	public string $wikiId;

	/** @var int */
	public int $pageId;

	/** @var int */
	public int $namespace;

	/** @var string */
	public string $title;

	/** @var string */
	public string $contentModel;

	/** @var string */
	public string $pageLanguage;

	/** @var string */
	public string $pageLanguageHtmlCode;

	/** @var string */
	public string $pageLanguageDir;

	/** @var string ISO timestamp */
	public string $touched;

	/** @var int */
	public int $lastRevisionId;

	/** @var int */
	public int $length;

	/** @var Title|null */
	public ?Title $redirect;

	/** @var string */
	public string $displayTitle;

	/** @var RevisionInfoResponse|null */
	public ?RevisionInfoResponse $lastRevision;

	/** @var Mirror */
	private Mirror $mirror;

	/** @var Title|null */
	private ?Title $titleObj = null;

	/**
	 * PageInfoResponse constructor.
	 *
	 * @param Mirror $mirror Mirror service that generated $response
	 * @param array $response Associative array of API response
	 */
	public function __construct( Mirror $mirror, array $response ) {
		$this->mirror = $mirror;

		$this->wikiId = $mirror->getWikiID();
		$this->pageId = $response['pageid'];
		$this->namespace = $response['ns'];
		$this->title = $response['title'];
		$this->contentModel = $response['contentmodel'];
		$this->pageLanguage = $response['pagelanguage'];
		$this->pageLanguageHtmlCode = $response['pagelanguagehtmlcode'];
		$this->pageLanguageDir = $response['pagelanguagedir'];
		$this->touched = $response['touched'];
		$this->lastRevisionId = $response['lastrevid'];
		$this->length = $response['length'];
		$this->displayTitle = $response['displaytitle'];

		if ( array_key_exists( 'revisions', $response ) ) {
			$this->lastRevision = new RevisionInfoResponse( $response['revisions'][0] );
		} else {
			$this->lastRevision = null;
		}

		if ( array_key_exists( 'redirect', $response ) && is_array( $response['redirect'] ) ) {
			// convert to db key form
			$title = str_replace( ' ', '_', $response['redirect']['title'] );
			// strip namespace prefix if any
			if ( $response['redirect']['ns'] !== 0 ) {
				$parts = explode( ':', $title, 2 );
				$title = $parts[1];
			}

			$this->redirect = Title::makeTitleSafe( $response['redirect']['ns'], $title );
		} else {
			$this->redirect = null;
		}
	}

	/**
	 * Get Title object for remote page
	 *
	 * @return Title
	 */
	public function getTitle() {
		if ( $this->titleObj === null ) {
			$this->titleObj = Title::newFromText( $this->title );
		}

		return $this->titleObj;
	}

	/**
	 * Get URL for remote page
	 *
	 * @return string
	 */
	public function getUrl() {
		return $this->mirror->getPageUrl( $this->getTitle()->getPrefixedDBkey() );
	}

	/**
	 * Get text for the remote page
	 *
	 * @return ParseResponse
	 * @throws MWException On error
	 */
	public function getText() {
		$status = $this->mirror->getCachedText( $this->getTitle() );
		if ( $status->isOK() ) {
			return $status->getValue();
		}

		throw new MWException( $status->getMessage()->plain() );
	}
}
