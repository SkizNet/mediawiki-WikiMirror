<?php

namespace WikiMirror\API;

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
	/** @var string|null Null if this page is not a redirect */
	public $redirect;
	/** @var string */
	public $displayTitle;

	/**
	 * PageInfoResponse constructor.
	 *
	 * @param array $response Associative array of API response
	 */
	public function __construct( $response ) {
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
		$this->redirect = $response['redirect'] ?: null;
		$this->displayTitle = $response['displaytitle'];
	}
}
