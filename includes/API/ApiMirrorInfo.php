<?php

namespace WikiMirror\API;

use JsonException;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Interwiki\InterwikiLookup;
use WikiMirror\Mirror\Mirror;

class ApiMirrorInfo extends ApiQueryBase {
	/** @var InterwikiLookup */
	private InterwikiLookup $interwikiLookup;

	/** @var Mirror */
	private Mirror $mirror;

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param InterwikiLookup $interwikiLookup
	 * @param Mirror $mirror
	 */
	public function __construct(
		ApiQuery $query,
		string $moduleName,
		InterwikiLookup $interwikiLookup,
		Mirror $mirror
	) {
		parent::__construct( $query, $moduleName );
		$this->interwikiLookup = $interwikiLookup;
		$this->mirror = $mirror;
	}

	public function execute() {
		$this->getResult()->addValue( 'query', $this->getModuleName(), $this->getUpdateStatus() );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'apihelp-query+mirrorinfo-description' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&meta=mirrorinfo' => 'apihelp-query+mirrorinfo-example',
		];
	}

	/**
	 * @return array{last_update:?string,update_in_progress:bool}
	 */
	private function getUpdateStatus(): array {
		return [
			'dump_update' => $this->getXmlUpdateDate(),
			'wme_update' => $this->getEnterpriseUpdateDate(),
			'wme_enabled' => $this->mirror->isEnterpriseCacheEnabled(),
			'update_in_progress' => $this->isXmlUpdateInProgress() || $this->isEnterpriseUpdateInProgress(),
		];
	}

	private function getXmlUpdateDate(): ?string {
		$date = $this->getDB()->selectField( 'remote_page', 'MAX(rp_updated)', [], __METHOD__ );
		if ( is_string( $date ) ) {
			return wfTimestamp( TS_ISO_8601, $date );
		}

		return null;
	}

	private function getEnterpriseUpdateDate(): ?string {
		if ( !$this->mirror->isEnterpriseCacheEnabled() ) {
			return null;
		}

		$cacheDirectory = $this->getConfig()->get( 'WikiMirrorCacheDirectory' );
		if ( $cacheDirectory === null ) {
			return null;
		}

		$progressFile = rtrim( $cacheDirectory, '/\\' ) . '/' . $this->mirror->getWikiId() . '/progress.json';
		// TOCTTOU race conditions prevent us from easily determining if file_get_contents would succeed
		// Simply try it and let it fail (without raising warnings) if the file does not exist or is unreadable.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$progressData = @file_get_contents( $progressFile );
		if ( $progressData === false ) {
			return null;
		}

		try {
			$progress = json_decode( $progressData, true, 8, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}

		if ( !is_array( $progress ) ) {
			return null;
		}

		$dates = [];
		foreach ( $progress as $namespace ) {
			if ( !is_array( $namespace ) || !isset( $namespace['date_modified'] ) ) {
				continue;
			}

			$date = wfTimestamp( TS_ISO_8601, (string)$namespace['date_modified'] );
			if ( $date !== false ) {
				$dates[] = $date;
			}
		}

		return $dates ? min( $dates ) : null;
	}

	private function isXmlUpdateInProgress(): bool {
		return $this->getDB()->tableExists( 'wikimirror_page', __METHOD__ )
			|| $this->getDB()->tableExists( 'wikimirror_redirect', __METHOD__ );
	}

	private function isEnterpriseUpdateInProgress(): bool {
		if ( !$this->mirror->isEnterpriseCacheEnabled() ) {
			return false;
		}

		$cacheDir = $this->getConfig()->get( 'WikiMirrorCacheDirectory' );
		$wikiId = $this->mirror->getWikiId();
		$progressFile = "{$cacheDir}/{$wikiId}/progress.json";
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$progressData = @file_get_contents( $progressFile );
		if ( $progressData === false ) {
			return false;
		}

		try {
			$progress = json_decode( $progressData, true, 8, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return false;
		}

		if ( !is_array( $progress ) ) {
			return false;
		}

		foreach ( $progress as $namespace ) {
			if ( !is_array( $namespace )
				|| !isset( $namespace['chunks'], $namespace['total_chunks'] )
				|| !is_array( $namespace['chunks'] )
			) {
				continue;
			}

			if ( count( $namespace['chunks'] ) < $namespace['total_chunks'] ) {
				return true;
			}
		}

		return false;
	}
}
