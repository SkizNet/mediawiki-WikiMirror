<?php

namespace WikiMirror\API;

use MWException;
use Title;
use WikiMirror\Mirror\Mirror;

/**
 * Wrapper around MediaWiki's API action=query&prop=info response
 */
class PageInfoResponse {
	/** @var int */
	public $pageId;
	/** @var int */
	public $namespace;
	/** @var string */
	public $title;
	/** @var string */
	public $contentModel;
	/** @var string */
	public $pageLanguage;
	/** @var string */
	public $pageLanguageHtmlCode;
	/** @var string */
	public $pageLanguageDir;
	/** @var string ISO timestamp */
	public $touched;
	/** @var int */
	public $lastRevisionId;
	/** @var int */
	public $length;
	/** @var Title|null */
	public $redirect;
	/** @var string */
	public $displayTitle;
	/** @var RevisionInfoResponse|null */
	public $lastRevision;
	/** @var Mirror */
	private $mirror;
	/** @var Title */
	private $titleObj = null;

	/**
	 * PageInfoResponse constructor.
	 *
	 * @param Mirror $mirror Mirror service that generated $response
	 * @param array $response Associative array of API response
	 */
	public function __construct( Mirror $mirror, array $response ) {
		$this->mirror = $mirror;

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

		if ( array_key_exists( 'redirect', $response ) && $response['redirect'] ) {
			// convert to db key form
			$title = str_replace( ' ', '_', $response['links'][0]['title'] );
			$this->redirect = Title::makeTitleSafe( $response['links'][0]['ns'], $title );
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
