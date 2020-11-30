<?php

namespace {
	$IP = getenv( 'MW_INSTALL_PATH' );
	if ( $IP === false ) {
		$IP = __DIR__ . '../../..';
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

			$apiUrl = $interwiki->getAPI();
			$params = [
				'format' => 'json',
				// ...
			];

			// number of pages processed thus far
			$count = 0;

			do {
				$res = $requestFactory->get( wfAppendQuery( $apiUrl, $params ), [], __METHOD__ );

				if ( $res === null ) {
					// API error
					$this->error( 'Error with remote mirror API [details here].' );
					return false;
				}

				$data = json_decode( $res, true );
				$done = false;
			} while ( !$done );

			return true;
		}

		/**
		 * Fetches a dump from the passed-in URL. The dump should be a TSV
		 * with the namespace id as the first column and page name as second column (in db key format).
		 * The dump can be gzipped, and will be extracted before being loaded.
		 *
		 * @return bool True on success, false on failure
		 * @throws Exception on error
		 */
		private function fetchFromDump() {
			$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
			$remote = $this->getOption( 'dump' );

			$tmpFile = tempnam( wfTempDir(), 'wmr' );
			$fh = false;
			try {
				$http = $requestFactory->create( $remote, [ 'sink' => $tmpFile ], __METHOD__ );
				// set up some options that aren't directly exposed, such as a progress meter
				if ( $http instanceof GuzzleHttpRequest ) {
					$guzzleClass = new ReflectionClass( $http );
					$guzzleOptions = $guzzleClass->getProperty( 'guzzleOptions' );
					$guzzleOptions->setAccessible( true );
					$currentOptions = $guzzleOptions->getValue( $http );
					$currentOptions['progress'] = [ $this, 'progress' ];
				} else {
					// someone decided to change the default http engine
					// this is deprecated yet still supported in core, but unsupported here
					// we can remove this once the ability to configure the http engine is removed
					$this->error( 'The HTTP engine is not Guzzle; do not modify the default engine' );
					return false;
				}

				$this->outputChanneled( "Downloading $remote..." );
				$res = $http->execute();
				$this->outputChanneled( 'Download complete!' );
				if ( !$res->isOK() ) {
					$this->error( $res->getMessage()->text() );
					return false;
				}

				// start a transaction, then delete existing data
				$db = $this->getDB( DB_MASTER );
				$db->begin();

				// write the new data in the database
				$this->outputChanneled( 'Loading data...' );
				$fh = gzopen( $tmpFile, 'rb' );
				if ( $fh === false ) {
					$this->error( 'Could not open downloaded file for reading' );
					return false;
				}

				while ( !gzeof( $fh ) ) {
					$line = gzgets( $fh );
					if ( $line === false || $line === '' ) {
						break;
					}
				}

				$this->outputChanneled( 'Load complete!' );

				// load data into database
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
