<?php

namespace WikiMirror\API;

/**
 * Wrapper around the data returned by the Wikimedia Enterprise snapshot API
 */
class EnterpriseCacheResponse {
	// additional_entities for Wikidata information has no current use and is not captured in this object

	/**
	 * @var string HTML containing the article
	 *
	 * Derived from article_body.html
	 */
	public string $pageHtml;

	/**
	 * @var string Wikitext of the article
	 *
	 * Derived from article_body.wikitext
	 */
	public string $pageText;

	// categories has no current use and is not captured in this object

	/**
	 * @var string ISO 8601 timestamp containing when the article was last modified
	 *
	 * Derived from date_modified
	 */
	public string $lastModified;

	// event has no current use and is not captured in this object

	/**
	 * @var int Page ID from the remote wiki
	 *
	 * Derived from identifier
	 */
	public int $pageId;

	/**
	 * @var string Language code for the article
	 *
	 * Derived from in_language.identifier
	 */
	public string $pageLanguage;

	// is_part_of has no current use and is not captured in this object
	// license has no current use and is not captured in this object
	// main_entity has no current use and is not captured in this object

	/**
	 * @var string Prefixed page name, in text format (spaces instead of underscores)
	 *
	 * Derived from name
	 */
	public string $pageTitle;

	/**
	 * @var int Namespace ID for the page
	 *
	 * Derived from namespace.identifier
	 */
	public int $pageNamespace;

	// protection has no current use and is not captured in this object
	// redirects has no current use and is not captured in this object (note: this is redirects **TO** this page)
	// templates has no current use and is not captured in this object
	// url has no current use and is not captured in this object

	/**
	 * @var ?string Edit summary for the most recent revision
	 *
	 * Derived from version.comment
	 */
	public ?string $revisionComment;

	// version.editor has no current use and is not captured in this object

	/**
	 * @var int Revision ID for the most recent revision
	 *
	 * Derived from version.identifier
	 */
	public int $revisionId;

	// version.number_of_characters has no current use and is not captured in this object
	// version.scores has no current use and is not captured in this object

	/**
	 * @var int Size (in bytes) for the most recent revision
	 *
	 * Derived from version.size.value
	 * We currently assume that version.size.unit_text is always "B"
	 */
	public int $revisionSize;

	// version.tags has no current use and is not captured in this object

	/**
	 * Construct a new EnterpriseCacheResponse.
	 *
	 * @param array $data An associative array received from parsing the JSON data for an article
	 */
	public function __construct( array $data ) {
		$this->pageHtml = $data['article_body']['html'];
		$this->pageText = $data['article_body']['wikitext'];
		$this->lastModified = $data['date_modified'];
		$this->pageId = $data['identifier'];
		$this->pageLanguage = $data['in_language']['identifier'];
		$this->pageTitle = $data['name'];
		$this->pageNamespace = $data['namespace']['identifier'];
		$this->revisionId = $data['version']['identifier'];
		$this->revisionSize = $data['version']['size']['value'];

		// comment may be undefined if it was hidden via revdelete
		if ( isset( $data['version']['comment'] ) ) {
			$this->revisionComment = $data['version']['comment'];
		}

		// for HTML, we need to transform the output slightly. WME API gives us a full HTML document
		// we instead want a fragment where the outside node is a <div class="mw-parser-output">
		// WME HTML has mw-parser-output on the <body>
		// First truncate everything up to the <body and replace with <div
		$this->pageHtml = preg_replace( '/^.*?<body /', '<div ', $this->pageHtml );
		// Then replace and truncate the ending
		$this->pageHtml = preg_replace( '#</body></html>$#', '</div>', $this->pageHtml );

		if ( $data['version']['unit_text'] !== 'B' ) {
			wfWarn( "Data for page <{$this->pageTitle}> ({$this->pageId}) has a size in a unit other than bytes" );
		}
	}
}
