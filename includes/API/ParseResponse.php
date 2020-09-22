<?php

namespace WikiMirror\API;

use ParserOutput;
use WikiMirror\Mirror\Mirror;

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
	/** @var PageInfoResponse */
	private $pageInfo;
	/** @var Mirror */
	private $mirror;

	/**
	 * ParseResponse constructor.
	 *
	 * @param Mirror $mirror Mirror service that generated $response
	 * @param array $response Associative array of API response
	 * @param PageInfoResponse $pageInfo Page info corresponding to parsed text
	 */
	public function __construct( Mirror $mirror, array $response, PageInfoResponse $pageInfo ) {
		$this->mirror = $mirror;
		$this->pageInfo = $pageInfo;
		$this->title = $response['title'];
		$this->pageId = $response['pageid'];
		$this->html = $response['text']['*'];
		$this->modules = $response['modules'];
		$this->moduleScripts = $response['modulescripts'];
		$this->moduleStyles = $response['modulestyles'];
		$this->jsConfigVars = $response['jsconfigvars'];
		$this->wikitext = $response['wikitext']['*'];
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

		foreach ( $this->indicators as $name => $html ) {
			$output->setIndicator( $name, $html );
		}

		$output->hideNewSection( true );
		if ( $this->pageInfo->lastRevisionId ) {
			$output->setTimestamp( wfTimestamp( TS_MW, $this->pageInfo->lastRevision->timestamp ) );
		} else {
			$output->setTimestamp( wfTimestamp( TS_MW ) );
		}

		return $output;
	}
}
