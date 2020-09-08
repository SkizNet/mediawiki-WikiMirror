<?php

namespace WikiMirror\API;

use Title;

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

	/**
	 * PageInfoResponse constructor.
	 *
	 * @param array $response Associative array of API response
	 */
	public function __construct( array $response ) {
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

		if ( array_key_exists( 'redirect', $response ) && array_key_exists( 'links', $response ) ) {
			// convert to db key form
			$title = str_replace( ' ', '_', $response['links'][0]['title'] );
			$this->redirect = Title::makeTitleSafe( $response['links'][0]['ns'], $title );
		} else {
			$this->redirect = null;
		}
	}

	public function getTitle() {
		return Title::makeTitleSafe( $this->namespace, str_replace( ' ', '_', $this->title ) );
	}
}
