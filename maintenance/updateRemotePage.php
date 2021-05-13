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
	use WikiMirror\Compat\CurlHandler;

	// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.WrongCase
	class UpdateRemotePage extends Maintenance {
		/** @var array */
		private $originalOptions;

		/** @var bool */
		private $isChild = false;

		/** @var int */
		private $total = -1;

		/** @var array */
		private $offsets;

		public function __construct() {
			parent::__construct();
			$this->requireExtension( 'WikiMirror' );
			$this->setBatchSize( 50000 );
			$this->setAllowUnregisteredOptions( true );
			$this->addDescription(
				'Update locally-stored tracking data about which pages and namespaces exist on the remote wiki'
			);

			$this->addOption(
				'dump',
				'URL or file path of the dump containing valid page names',
				true,
				true
			);
		}

		/**
		 * @inheritDoc
		 */
		public function finalSetup() {
			// save a pristine copy of $this->mOptions before our parent munges it
			$this->originalOptions = $this->mOptions;
			parent::finalSetup();
		}

		/**
		 * Update remote_page with data from remote wiki
		 * @return bool
		 * @throws Exception
		 */
		public function execute() {
			// delete records older than 3 days
			$stale = wfTimestamp( TS_MW, time() - 259200 );
			$batchSize = $this->getBatchSize();
			$skip = $this->getOption( 'skip' );
			$count = $this->getOption( 'count' );

			if ( $batchSize > 0 && $skip !== null && $count !== null ) {
				// we're a child process executing in batched mode
				$this->isChild = true;
			} else {
				// non-batched mode or we're the parent
				$count = 0;
				$skip = 0;
			}

			$path = $this->getOption( 'dump' );
			$haveTempFile = false;
			if ( wfParseUrl( $path ) !== false ) {
				// we were passed a URL to download;
				// this will generate a temporary file we need to clean up
				$path = $this->downloadDump( $path );
				$haveTempFile = true;
			}

			try {
				if ( !$this->isChild ) {
					$this->outputChanneled( 'Calculating dataset size...' );
					$this->calculateTotalAndOffsets( $path );
					$this->outputChanneled( 'Loading data...' );
				}

				if ( $batchSize > 0 && !$this->isChild ) {
					$this->spawnSubprocesses( $path );
				} else {
					$this->fetchFromDump( $path, $skip, $count );
				}
			} finally {
				if ( $haveTempFile ) {
					// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
					@unlink( $path );
				}
			}

			if ( !$this->isChild ) {
				$this->outputChanneled( 'Data loaded successfully!' );
				$this->deleteStaleRecords( $stale );
			}

			return true;
		}

		/**
		 * Downloads a dump file from the passed-in URL, displaying a progress meter
		 * to the user.
		 *
		 * Example URL: https://dumps.wikimedia.org/enwiki/20210320/enwiki-20210320-all-titles.gz
		 *
		 * @param string $remote URL to download dump from
		 * @return string Full path of downloaded dump
		 * @throws Exception on error
		 */
		private function downloadDump( string $remote ) {
			$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
			$tmpFile = tempnam( wfTempDir(), 'wmr' );

			try {
				// MediaWiki is broken and doesn't actually pass the timeout to Guzzle correctly,
				// so the default timeout is always used. Fix that here.
				$handler = new CurlHandler( [
					CURLOPT_TIMEOUT => 0
				] );

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
					$this->fatalError( 'The HTTP engine is not Guzzle; do not modify the default engine' );
				}

				$this->outputChanneled( "Downloading $remote..." );
				$res = $http->execute();
				if ( !$res->isOK() ) {
					$this->fatalError( $res->getMessage()->text() );
				}

				$this->outputChanneled( 'Download complete!' );
			} catch ( Exception $e ) {
				// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmpFile );
				throw $e;
			}

			return $tmpFile;
		}

		/**
		 * Populates $this->total and $this->offsets
		 *
		 * @param string $path File path to dump
		 */
		private function calculateTotalAndOffsets( $path ) {
			$batchSize = $this->getBatchSize();
			$offsets = [ 0 => 0 ];
			$count = 0;

			$fh = gzopen( $path, 'rb' );
			if ( $fh === false ) {
				$this->fatalError( 'Could not open dump file for reading' );
			}

			while ( !gzeof( $fh ) ) {
				$line = gzgets( $fh );
				if ( $line === false || $line === '' ) {
					continue;
				}

				++$count;

				if ( $count % $batchSize === 0 ) {
					$offsets[$count] = gztell( $fh );
				}
			}

			$this->total = $count;
			$this->offsets = $offsets;
		}

		/**
		 * Spawns subprocesses to handle the batches. This reduces memory pressure
		 * on the main PHP process for very large dumps, as some memory is not freed
		 * until the script completely finishes executing.
		 *
		 * @param string $path Path to dump file
		 */
		private function spawnSubprocesses( $path ) {
			$batchSize = $this->getBatchSize();
			$specialOpts = [ 'skip', 'count', 'total' ];

			$opts = $this->originalOptions;
			$opts['batch-size'] = $batchSize;
			$opts['dump'] = $path;
			$opts['total'] = $this->total;

			for ( $cur = 0; $cur < $this->total; $cur += $batchSize ) {
				$opts['skip'] = $this->offsets[$cur];
				$opts['count'] = $cur;
				$parameters = [];
				foreach ( $opts as $k => $v ) {
					if ( in_array( $k, $specialOpts ) ) {
						$parameters[] = "--{$k}={$v}";
					} else {
						$parameters[] = "--{$k}";
						$parameters[] = $v;
					}
				}

				// explicitly pass the same php binary executing the current script, since $wgPhpCli is
				// typically never set in LocalSettings.php (which is what makeScriptCommand defaults to);
				// this makes the default completely unusable on Windows and exotic setups
				$command = Shell::makeScriptCommand( __FILE__, $parameters, [ 'php' => PHP_BINARY ] );

				// don't actually execute through Shell because we want to have stdout print immediately
				// in the subprocesses, and we don't want to limit via cgroups, firejail, etc.
				// passthru() does exactly what we want it to do, so use that directly
				if ( method_exists( $command, 'getCommandString' ) ) {
					// 1.36+
					// @phan-suppress-next-line PhanUndeclaredMethod
					$commandString = $command->getCommandString();
				} else {
					// 1.35
					$commandString = substr( (string)$command, 10 );
				}

				// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.passthru
				passthru( $commandString, $exitCode );
				if ( $exitCode !== 0 ) {
					// subcommand failed, abort
					$this->fatalError( 'Aborting data load due to subcommand failure.' );
				}
			}
		}

		/**
		 * Fetches a dump from the passed-in file. The dump should be a TSV
		 * with the namespace id as the first column and page name as second column (in db key format).
		 * The dump can be gzipped, and will be extracted before being loaded.
		 *
		 * @param string $path File (possibly gzipped) containing the TSV dump
		 * @param int $skip Byte offset to skip to in the dump file
		 * @param int $count Number of records retrieved by previous executions
		 * @throws Exception on error
		 */
		private function fetchFromDump( string $path, $skip, $count ) {
			$now = wfTimestampNow();
			$db = $this->getDB( DB_PRIMARY );

			// check if we need to execute in batched mode
			$batchSize = $this->getBatchSize();
			$total = $this->getOption( 'total', $this->total );
			$width = intval( log10( $total ) ) + 1;

			if ( $total === -1 ) {
				$this->fatalError( 'Missing total' );
			}

			try {
				// write new data to db
				$fh = gzopen( $path, 'rb' );
				if ( $fh === false ) {
					$this->fatalError( 'Could not open dump file for reading' );
				}

				if ( $skip > 0 ) {
					gzseek( $fh, $skip, SEEK_SET );
				}

				while ( !gzeof( $fh ) ) {
					$line = gzgets( $fh );
					if ( $line === false || $line === '' ) {
						continue;
					}

					++$count;
					list( $nsId, $title ) = explode( "\t", $line );
					$db->upsert( 'remote_page', [
						'rp_namespace' => $nsId,
						'rp_title' => trim( $title ),
						'rp_updated' => $now
					], [
						[ 'rp_namespace', 'rp_title' ]
					], [
						'rp_updated' => $now
					], __METHOD__ );

					$percent = $count / $total * 100;
					$this->outputChanneled( sprintf( "\r[ %{$width}d / %d ] %3d%% ",
						$count, $total, $percent ), 'progress' );

					if ( $batchSize > 0 && $count % $batchSize === 0 ) {
						break;
					}
				}
			} finally {
				// @phan-suppress-next-line PhanPossiblyUndeclaredVariable
				if ( $fh !== false && $fh !== null ) {
					// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
					@gzclose( $fh );
				}
			}
		}

		/**
		 * Delete records from cache that are older than the specified timestamp
		 *
		 * @param string $ts Timestamp (in TS_MW format) of cutoff
		 */
		private function deleteStaleRecords( $ts ) {
			$db = $this->getDB( DB_PRIMARY );
			$db->delete( 'remote_page', [
				'rp_updated < ' . $ts
			], __METHOD__ );

			$numRows = $db->affectedRows();
			$this->outputChanneled( "$numRows stale records deleted!" );
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

			// trailing spaces in output are intentional so that blocky cursors don't cover up
			// the final dot in the ellipses and to get rid of leftover chars should our output length shrink
			$this->outputChanneled( "\r$current / $total...            ", 'progress' );
		}
	}
}

namespace {
	/** @noinspection PhpUnusedLocalVariableInspection */
	$maintClass = 'WikiMirror\Maintenance\UpdateRemotePage';
	/** @noinspection PhpIncludeInspection */
	require RUN_MAINTENANCE_IF_MAIN;
}
