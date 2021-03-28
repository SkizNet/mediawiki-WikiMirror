<?php

namespace WikiMirror\API;

use WikiMirror\Mirror\Mirror;

/**
 * Wrapper around MediaWiki's API action=query&meta=siteinfo response
 */
class SiteInfoResponse {
	/** @var string */
	public $server;
	/** @var string */
	public $articlePath;
	/** @var bool */
	public $mainPageIsDomainRoot;
	/** @var string|false */
	public $variantArticlePath;
	/** @var array */
	public $namespaces;
	/** @var array */
	public $namespaceAliases;
	/** @var Mirror */
	private $mirror;

	/**
	 * SiteInfoResponse constructor.
	 *
	 * @param Mirror $mirror Mirror service that generated $response
	 * @param array $response Associative array of API response
	 */
	public function __construct( Mirror $mirror, array $response ) {
		$this->mirror = $mirror;
		$this->server = $response['general']['server'];
		$this->articlePath = $response['general']['articlepath'];
		$this->mainPageIsDomainRoot = $response['general']['mainpageisdomainroot'];
		$this->variantArticlePath = $response['general']['variantarticlepath'];
		$this->namespaces = $response['namespaces'];
		$this->namespaceAliases = $response['namespacealiases'];
	}

	/**
	 * Retrieve a mapping of namespace names to numbers on the remote wiki.
	 *
	 * @return array|false Map of namespace names to numbers, or false on error
	 */
	public function getNamespaceMap() {
		$namespaces = [];
		foreach ( $this->namespaces as $ns => $nsData ) {
			$nsKey = str_replace( ' ', '_', $nsData['name'] );
			$namespaces[$nsKey] = intval( $ns );
		}

		foreach ( $this->namespaceAliases as $ns => $nsData ) {
			$nsKey = str_replace( ' ', '_', $nsData['alias'] );
			$namespaces[$nsKey] = intval( $ns );
		}

		return $namespaces;
	}
}
