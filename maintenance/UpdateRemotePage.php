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
	use Wikimedia\AtEase\AtEase;

	class UpdateRemotePage extends Maintenance {
		public function __construct() {
			parent::__construct();
			$this->requireExtension( 'WikiMirror' );
			$this->setBatchSize( 50000 );
			$this->addDescription(
				'Update locally-stored tracking data about which pages and namespaces exist on the remote wiki.'
				. ' This script is designed to be invoked multiple times; first with --page and --out,'
				. ' then with --redirect and --out. Each of these invocations will generate a .sql file that should'
				. ' be read in via the mysql cli. Finally, this script should be invoked a third time with --finish'
				. ' after those sql files have been read in.'
			);

			$this->addOption(
				'page',
				'File path of the dump containing the page table',
				false,
				true
			);

			$this->addOption(
				'redirect',
				'File path of the dump containing the redirect table',
				false,
				true
			);

			$this->addOption(
				'out',
				'File path to write SQL output to',
				false,
				true
			);

			$this->addOption(
				'finish',
				'Perform cleanup after independently reading in SQL files',
				false,
				false
			);
		}

		/**
		 * Update remote_page with data from remote wiki
		 * @return bool
		 * @throws Exception
		 */
		public function execute() {
			$config = $this->getConfig();
			$db = $this->getDB( DB_PRIMARY );
			$db->setSchemaVars( [
				'wgDBTableOptions' => $config->get( 'DBTableOptions' ),
				'now' => wfTimestampNow()
			] );

			$dumpPage = $db->tableName( 'wikimirror_page' );
			$dumpRedirect = $db->tableName( 'wikimirror_redirect' );

			$paths = [
				'page' => $this->getOption( 'page' ),
				'redirect' => $this->getOption( 'redirect' )
			];

			$replacements = [
				'`page`' => $dumpPage,
				'`redirect`' => $dumpRedirect
			];

			$out = $this->getOption( 'out' );
			$finish = $this->hasOption( 'finish' );
			$pathCount = count( array_filter( $paths ) );
			if ( $pathCount === 2 ) {
				$this->fatalError( 'You cannot specify both --page and --redirect in the same invocation' );
			} elseif ( $pathCount === 1 ) {
				if ( $out === null ) {
					$this->fatalError( 'The --out option is required when specifying --page or --redirect' );
				} elseif ( $finish ) {
					$this->fatalError( 'You cannot specify --finish along with --page or --redirect' );
				}
			}

			foreach ( $paths as $type => $path ) {
				if ( $path === null ) {
					continue;
				}

				$this->outputChanneled( "Loading {$type} data..." );
				$this->fetchFromDump( $path, $replacements, $out );
				$this->outputChanneled( "{$type} data loaded successfully!" );
			}

			if ( $finish ) {
				$this->outputChanneled( 'Finishing up...' );
				$db->sourceFile( __DIR__ . '/sql/updateRemotePage.sql' );
			}

			$this->outputChanneled( 'Complete!' );

			return true;
		}

		/**
		 * Fetches a dump from the passed-in file. The dump should be a SQL file with
		 * CREATE TABLE and INSERT INTO statements. The dump can be gzipped, and will
		 * be extracted before being loaded. Some transformations are performed on the
		 * dump file before it is executed as SQL, to use a different table name.
		 * The data is loaded to an output file to execute via the mysql cli, as
		 * MediaWiki's facilities to execute SQL files choke on large files.
		 *
		 * @param string $path File (possibly gzipped) containing the SQL dump
		 * @param array $replacements String replacements to make to the SQL dump
		 * @param string $out File to write the resultant SQL to
		 * @throws Exception on error
		 */
		private function fetchFromDump( string $path, array $replacements, string $out ) {
			// these definitions don't do anything but exist to make phan happy
			$gfh = null;
			$tfh = null;

			try {
				// extract gzipped file to a temporary file
				$gfh = gzopen( $path, 'rb' );
				$tfh = fopen( $out, 'wb' );
				if ( $gfh === false ) {
					$this->fatalError( 'Could not open dump file for reading' );
				}

				if ( $tfh === false ) {
					$this->fatalError( 'Could not open temp file for writing' );
				}

				while ( !gzeof( $gfh ) ) {
					$line = gzgets( $gfh );
					if ( $line === false ) {
						$this->fatalError( 'Error reading dump file' );
					}

					$line = strtr( trim( $line ), $replacements );
					fwrite( $tfh, $line . "\n" );
				}
			} finally {
				AtEase::suppressWarnings();
				if ( $gfh !== false && $gfh !== null ) {
					gzclose( $gfh );
				}

				if ( $tfh !== false && $tfh !== null ) {
					fclose( $tfh );
				}

				AtEase::restoreWarnings();
			}
		}
	}
}

namespace {
	$maintClass = 'WikiMirror\Maintenance\UpdateRemotePage';
	require RUN_MAINTENANCE_IF_MAIN;
}
