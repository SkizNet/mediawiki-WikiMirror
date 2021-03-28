<?php

namespace {
	$IP = getenv( 'MW_INSTALL_PATH' );
	if ( $IP === false ) {
		$IP = __DIR__ . '/../../..';
	}

	require_once "$IP/maintenance/Maintenance.php";
}

namespace WikiMirror\Maintenance {

	use Exception;
	use GuzzleHttpRequest;
	use Maintenance;
	use MediaWiki\MediaWikiServices;
	use MediaWiki\Shell\Shell;
	use ReflectionClass;
	use WikiMirror\API\SiteInfoResponse;
	use WikiMirror\Compat\CurlHandler;
	use WikiMirror\Mirror\Mirror;

	// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase
	class UpdateRemotePage extends Maintenance {
		public function __construct() {
			parent::__construct();
			$this->requireExtension( 'WikiMirror' );
			$this->setBatchSize( 500 );
			$this->addDescription(
				'Update locally-stored tracking data about which pages and namespaces exist on the remote wiki'
			);
			$this->addOption(
				'dump',
				'Load page titles from an online dump at the specified URL rather than via the API (faster)',
				false,
				true
			);
		}

		/**
		 * Update remote_page with data from remote wiki
		 * @return bool true on success, false on failure
		 * @throws Exception
		 */
		public function execute() {
			if ( $this->hasOption( 'dump' ) ) {
				return $this->fetchFromDump();
			} else {
				return $this->fetchFromAPI();
			}
		}

		private function fetchFromAPI() {
			$config = $this->getConfig();
			$interwikiLookup = MediaWikiServices::getInstance()->getInterwikiLookup();
			$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();

			$remoteWiki = $config->get( 'WikiMirrorRemote' );
			if ( $remoteWiki === null ) {
				$this->error( '$wgWikiRemote not configured in LocalSettings.php.' );
				return false;
			}

			$interwiki = $interwikiLookup->fetch( $remoteWiki );
			if ( $interwiki === null || $interwiki === false ) {
				// invalid interwiki configuration
				$this->error( 'Invalid interwiki configuration for $wgWikiMirrorRemote.' );
				return false;
			}

			/** @var Mirror $mirror */
			$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
			$status = $mirror->getCachedSiteInfo();
			if ( !$status->isOK() ) {
				$this->error( 'Unable to retrieve remote site information.' );
				return false;
			}

			/** @var SiteInfoResponse $siteInfo */
			$siteInfo = $status->getValue();

			$apiUrl = $interwiki->getAPI();
			$params = [
				'format' => 'json',
				'formatversion' => 2,
				'errorformat' => 'plaintext',
				'errorlang' => 'en',
				'action' => 'query',
				'list' => 'allpages',
				'aplimit' => 'max'
			];

			// start a transaction
			$db = $this->getDB( DB_MASTER );
			$db->begin( __METHOD__ );

			// delete existing data
			$db->delete( 'remote_page', '1=1', __METHOD__ );

			// number of pages processed thus far
			$count = 0;

			foreach ( $siteInfo->namespaces as $nsData ) {
				$nsName = $nsData['id'] === 0 ? '(Main)' : $nsData['name'];
				$this->outputChanneled( 'Retrieving namespace ' . $nsName );
				$params['apnamespace'] = $nsData['id'];

				do {
					$res = $requestFactory->get( wfAppendQuery( $apiUrl, $params ), [], __METHOD__ );
					$now = wfTimestampNow();

					if ( $res === null ) {
						// API error
						$this->error( 'Unable to retrieve remote page data. Rolling back (this may take a while).' );
						$db->rollback( __METHOD__ );
						return false;
					}

					$data = json_decode( $res, true );
					if ( array_key_exists( 'continue', $data ) ) {
						$params['apcontinue'] = $data['continue']['apcontinue'];
						$done = false;
					} else {
						$done = true;
					}

					foreach ( $data['query']['allpages'] as $page ) {
						$db->insert( 'remote_page', [
							'rp_namespace' => $page['ns'],
							'rp_title' => str_replace( ' ', '_', $page['title'] ),
							'rp_updated' => $now
						], __METHOD__ );

						if ( ++$count % 500 === 0 ) {
							$this->outputChanneled( $count );
						}
					}
				} while ( !$done );
			}

			// commit
			$db->commit( __METHOD__ );

			return true;
		}

		/**
		 * Fetches a dump from the passed-in URL. The dump should be a TSV
		 * with the namespace id as the first column and page name as second column (in db key format).
		 * The dump can be gzipped, and will be extracted before being loaded.
		 *
		 * Example URL: https://dumps.wikimedia.org/enwiki/20210320/enwiki-20210320-all-titles.gz
		 *
		 * @return bool True on success, false on failure
		 * @throws Exception on error
		 */
		private function fetchFromDump() {
			$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
			$remote = $this->getOption( 'dump' );

			$tmpFile = tempnam( wfTempDir(), 'wmr' );

			// MediaWiki is broken and doesn't actually pass the timeout to Guzzle correctly,
			// so the default timeout is always used. Fix that here.
			$handler = new CurlHandler( [
				CURLOPT_TIMEOUT => 0
			] );

			$fh = false;
			$now = wfTimestampNow();

			try {
				$http = $requestFactory->create( $remote, [
					'sink' => $tmpFile,
					'handler' => $handler
				], __METHOD__ );

				// set up some options that aren't directly exposed, such as a progress meter
				if ( $http instanceof GuzzleHttpRequest ) {
					$guzzleClass = new ReflectionClass( $http );
					$guzzleOptions = $guzzleClass->getProperty( 'guzzleOptions' );
					$guzzleOptions->setAccessible( true );
					$currentOptions = $guzzleOptions->getValue( $http );
					$currentOptions['progress'] = [ $this, 'progress' ];
					$guzzleOptions->setValue( $http, $currentOptions );
				} else {
					// someone decided to change the default http engine
					// this is deprecated yet still supported in core, but unsupported here
					// we can remove this once the ability to configure the http engine is removed
					$this->error( 'The HTTP engine is not Guzzle; do not modify the default engine' );
					return false;
				}

				$this->outputChanneled( "Downloading $remote..." );
				$res = $http->execute();
				if ( !$res->isOK() ) {
					$this->error( $res->getMessage()->text() );
					return false;
				}

				$this->outputChanneled( 'Download complete!' );

				// start a transaction
				$db = $this->getDB( DB_MASTER );
				$db->begin( __METHOD__ );

				// delete existing data
				$db->delete( 'remote_page', '1=1', __METHOD__ );

				// write new data to db
				$this->outputChanneled( 'Loading data...' );
				$fh = gzopen( $tmpFile, 'rb' );
				if ( $fh === false ) {
					$this->error( 'Could not open downloaded file for reading' );
					return false;
				}

				$count = 0;
				while ( !gzeof( $fh ) ) {
					$line = gzgets( $fh );
					if ( $line === false || $line === '' ) {
						break;
					}

					list( $nsId, $title ) = explode( "\t", $line );
					$db->insert( 'remote_page', [
						'rp_namespace' => $nsId,
						'rp_title' => $title,
						'rp_updated' => $now
					], __METHOD__ );

					if ( ++$count % 1000 === 0 ) {
						$this->outputChanneled( $count );
					}
				}

				// commit transaction
				$db->commit( __METHOD__ );
				$this->outputChanneled( 'Load complete!' );
			} finally {
				// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged
				if ( $fh !== false ) {
					@gzclose( $fh );
				}

				@unlink( $tmpFile );
			}

			return true;
		}

		/**
		 * Display a progress report for the dump download.
		 * This is not meant to be called externally, treat it as a private method.
		 *
		 * @param int $dlTotal Total bytes to download, or 0 if unknown
		 * @param int $dlBytes Bytes downloaded thus far
		 * @param int $upTotal Unused
		 * @param int $upBytes Unused
		 */
		public function progress( $dlTotal, $dlBytes, $upTotal, $upBytes ) {
			// display a progress report
			if ( $dlTotal === 0 ) {
				$total = 'unknown';
			} elseif ( $dlTotal < 1000000 ) {
				$total = sprintf( '%.2d KB', $dlTotal / 1000 );
			} elseif ( $dlTotal < 1000000000 ) {
				$total = sprintf( '%.2d MB', $dlTotal / 1000000 );
			} else {
				$total = sprintf( '%.2d GB', $dlTotal / 1000000000 );
			}

			if ( $dlBytes < 1000000 ) {
				$current = sprintf( '%.2d KB', $dlBytes / 1000 );
			} elseif ( $dlBytes < 1000000000 ) {
				$current = sprintf( '%.2d MB', $dlBytes / 1000000 );
			} else {
				$current = sprintf( '%.2d GB', $dlBytes / 1000000000 );
			}

			// trailing space in output is intentional so that blocky cursors don't cover up
			// the final dot in the ellipses.
			$this->outputChanneled( "\r$current / $total... ", 'progress' );
		}
	}
}

namespace {
	/** @noinspection PhpUnusedLocalVariableInspection */
	$maintClass = 'WikiMirror\Maintenance\UpdateRemotePage';
	/** @noinspection PhpIncludeInspection */
	require RUN_MAINTENANCE_IF_MAIN;
}
