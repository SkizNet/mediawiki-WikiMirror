<?php

namespace WikiMirror\API;

use ParserOutput;

class ParseResponse {
	/** @var string */
	public $title;
	/** @var int */
	public $pageId;
	/** @var string */
	public $html;
	/** @var array */
	public $languageLinks;
	/** @var array */
	public $categoryLinks;
	/** @var string[] */
	public $modules;
	/** @var string[] */
	public $moduleScripts;
	/** @var string[] */
	public $moduleStyles;
	/** @var array */
	public $jsConfigVars;
	/** @var array */
	public $indicators;
	/** @var string */
	public $wikitext;
	/** @var array */
	public $properties;

	/**
	 * ParseResponse constructor.
	 *
	 * @param array $response Associative array of API response
	 */
	public function __construct( array $response ) {
		$this->title = $response['title'];
		$this->pageId = $response['pageid'];
		$this->html = $response['text']['*'];
		$this->modules = $response['modules'];
		$this->moduleScripts = $response['modulescripts'];
		$this->moduleStyles = $response['modulestyles'];
		$this->jsConfigVars = $response['jsconfigvars'];
		$this->wikitext = $response['wikitext'];
		$this->languageLinks = $response['langlinks'];
		$this->categoryLinks = $response['categories'];

		$this->indicators = [];
		foreach ( $response['indicators'] as $indicator ) {
			$this->indicators[$indicator['name']] = $indicator['*'];
		}

		$this->properties = [];
		foreach ( $response['properties'] as $property ) {
			$this->properties[$property['name']] = $property['*'];
		}
	}

	/**
	 * Fills a ParserOutput from remote API data.
	 *
	 * @return ParserOutput
	 */
	public function getParserOutput() {
		$output = new ParserOutput( $this->html, $this->languageLinks, $this->categoryLinks );

		if ( isset( $this->properties['displaytitle'] ) ) {
			$output->setDisplayTitle( $this->properties['displaytitle'] );
			unset( $this->properties['displaytitle'] );
		}
		
		return $output;
	}
}
