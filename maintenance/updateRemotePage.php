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
	use Maintenance;
	use MediaWiki\Shell\Shell;

	// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.WrongCase
	class UpdateRemotePage extends Maintenance {
		/** @var array */
		private $originalOptions;

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
				'page',
				'URL or file path of the dump containing the page table',
				true,
				true
			);

			$this->addOption(
				'redirect',
				'URL or file path of the dump containing the redirect table',
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
			// delete records older than 7 days
			$stale = wfTimestamp( TS_MW, time() - 604800 );
			$batchSize = $this->getBatchSize();
			$skip = $this->getOption( 'skip' );
			$count = $this->getOption( 'count' );
			$childPath = $this->getOption( 'path' );

			if ( $batchSize > 0 && $skip !== null && $count !== null ) {
				// we're a child process executing in batched mode
				$this->fetchFromDump( $childPath, $skip, $count );
				return true;
			}

			// non-batched mode or we're the parent
			$count = 0;
			$skip = 0;

			$paths = [
				'page' => $this->getOption( 'page' ),
				'redirect' => $this->getOption( 'redirect' )
			];

			foreach ( $paths as $type => $path ) {
				$this->outputChanneled( "Calculating {$type} dataset size..." );
				$this->calculateTotalAndOffsets( $path );
				$this->outputChanneled( "Loading {$type} data..." );

				if ( $batchSize > 0 ) {
					$this->spawnSubprocesses( $path );
				} else {
					$this->fetchFromDump( $path, $skip, $count );
				}

				$this->outputChanneled( "{$type} data loaded successfully!" );
			}

			$this->outputChanneled( 'Deleting stale pages...' );
			$this->deleteStaleRecords( $stale );

			return true;
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

			// we implement a simple state machine to count the number of tuples in an INSERT line;
			// this is also doable via regex but that ended up being more complicated to implement.
			// note that this isn't a perfect SQL parser and will accept certain invalid constructions, but
			// we assume the underlying .sql is valid and in mysqldump format so this should be good enough.
			$transitions = [
				0 => [
					'(' => 1,
					'default' => 0
				],
				1 => [
					"'" => 2,
					')' => 4,
					'default' => 1
				],
				2 => [
					"'" => 1,
					'\\' => 3,
					'default' => 2
				],
				3 => [
					'default' => 2
				],
				4 => [
					',' => 5,
					';' => 6,
					'default' => 'reject'
				],
				5 => [
					'(' => 1,
					'default' => 'reject'
				],
				6 => [
					'default' => 'reject'
				]
			];

			// accepting states
			$accept = [ 6 ];

			// states in which we've fully processed one record and should increment the count
			$incr = [ 4 ];

			while ( !gzeof( $fh ) ) {
				$line = gzgets( $fh );
				if ( $line === false ) {
					continue;
				}

				$line = trim( $line );
				if ( substr( $line, 0, 11 ) !== 'INSERT INTO' ) {
					continue;
				}

				$s = 0;
				$len = strlen( $line );
				for ( $i = 0; $i < $len; ++$i ) {
					$c = $line[$i];
					if ( isset( $transitions[$s][$c] ) ) {
						$s = $transitions[$s][$c];
					} else {
						$s = $transitions[$s]['default'];
					}

					if ( $s === 'reject' ) {
						gzclose( $fh );
						$this->fatalError( 'Error in dump file, unable to parse.' );
					}

					if ( in_array( $s, $incr ) ) {
						++$count;

						if ( $count % $batchSize === 0 ) {
							$offsets[$count] = gztell( $fh );
						}
					}
				}

				if ( !in_array( $s, $accept ) ) {
					gzclose( $fh );
					$this->fatalError( 'Error in dump file, unable to parse.' );
				}
			}

			$this->total = $count;
			$this->offsets = $offsets;
			gzclose( $fh );
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
			$opts['path'] = $path;
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
	}
}

namespace {
	/** @noinspection PhpUnusedLocalVariableInspection */
	$maintClass = 'WikiMirror\Maintenance\UpdateRemotePage';
	/** @noinspection PhpIncludeInspection */
	require RUN_MAINTENANCE_IF_MAIN;
}
