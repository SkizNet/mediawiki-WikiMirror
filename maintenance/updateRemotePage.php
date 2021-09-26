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
	use MediaWiki\MediaWikiServices;
	use Wikimedia\AtEase\AtEase;
	use Wikimedia\Rdbms\IDatabase;

	// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.WrongCase
	class UpdateRemotePage extends Maintenance {
		public function __construct() {
			parent::__construct();
			$this->requireExtension( 'WikiMirror' );
			$this->setBatchSize( 50000 );
			$this->addDescription(
				'Update locally-stored tracking data about which pages and namespaces exist on the remote wiki'
			);

			$this->addOption(
				'page',
				'File path of the dump containing the page table',
				true,
				true
			);

			$this->addOption(
				'redirect',
				'File path of the dump containing the redirect table',
				true,
				true
			);
		}

		/**
		 * Update remote_page with data from remote wiki
		 * @return bool
		 * @throws Exception
		 */
		public function execute() {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$db = $this->getDB( DB_PRIMARY );
			$db->setSchemaVars( [
				'wgDBTableOptions' => $config->get( 'DBTableOptions' ),
				'now' => wfTimestampNow()
			] );

			$dumpPage = $db->tableName( 'wikimirror_page' );
			$dumpRedirect = $db->tableName( 'wikimirror_redirect' );

			// avoid log overhead when running in debug mode; these files are huge
			// and we do *not* want to log these queries because of memory limit concerns
			$db->clearFlag( IDatabase::DBO_DEBUG );

			// clean up from previously failed runs
			$db->query( "DROP TABLE IF EXISTS $dumpPage" );
			$db->query( "DROP TABLE IF EXISTS $dumpRedirect" );

			$paths = [
				'page' => $this->getOption( 'page' ),
				'redirect' => $this->getOption( 'redirect' )
			];

			$replacements = [
				'`page`' => $dumpPage,
				'`redirect`' => $dumpRedirect
			];

			foreach ( $paths as $type => $path ) {
				$this->outputChanneled( "Loading {$type} data..." );
				$this->fetchFromDump( $db, $path, $replacements );
				$this->outputChanneled( "{$type} data loaded successfully!" );
			}

			$this->outputChanneled( 'Finishing up...' );
			$db->sourceFile( __DIR__ . '/sql/updateRemotePage.sql' );
			$this->outputChanneled( 'Complete!' );

			return true;
		}

		/**
		 * Fetches a dump from the passed-in file. The dump should be a SQL file with
		 * CREATE TABLE and INSERT INTO statements. The dump can be gzipped, and will
		 * be extracted before being loaded. Some transformations are performed on the
		 * dump file before it is executed as SQL, to use a different table name. Once
		 * the data is loaded, the new table is renamed to replace the old table.
		 *
		 * @param IDatabase $db Database to write to
		 * @param string $path File (possibly gzipped) containing the SQL dump
		 * @param array $replacements String replacements to make to the SQL dump
		 * @throws Exception on error
		 */
		private function fetchFromDump( IDatabase $db, string $path, array $replacements ) {
			$db = $this->getDB( DB_PRIMARY );
			$tempFsFileFactory = MediaWikiServices::getInstance()->getTempFSFileFactory();
			$tempFile = $tempFsFileFactory->newTempFSFile( 'urp', 'sql' );

			// these definitions don't do anything but exist to make phan happy
			$gfh = null;
			$tfh = null;

			try {
				// extract gzipped file to a temporary file
				$gfh = gzopen( $path, 'rb' );
				$tfh = fopen( $tempFile->getPath(), 'wb' );
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

					$line = trim( $line );
					if ( substr( $line, 0, 4 ) === 'DROP' ) {
						// ignore DROP TABLE lines
						continue;
					}

					$line = strtr( $line, $replacements );
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

			// Create either wikimirror_page or wikimirror_redirect
			$db->sourceFile( $tempFile->getPath() );
		}
	}
}

namespace {
	/** @noinspection PhpUnusedLocalVariableInspection */
	$maintClass = 'WikiMirror\Maintenance\UpdateRemotePage';
	/** @noinspection PhpIncludeInspection */
	require RUN_MAINTENANCE_IF_MAIN;
}
