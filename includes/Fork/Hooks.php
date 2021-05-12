<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Fork;

use ImportTitleFactory;
use Language;
use MediaWiki\Hook\ImportHandlePageXMLTagHook;
use MWException;
use NaiveForeignTitleFactory;
use NamespaceAwareForeignTitleFactory;
use WikiImporter;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiMirror\Compat\ReflectionHelper;

class Hooks implements ImportHandlePageXMLTagHook {
	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var Language */
	private $contentLanguage;

	/**
	 * Hooks constructor.
	 *
	 * @param ILoadBalancer $loadBalancer
	 * @param Language $contentLanguage
	 */
	public function __construct( ILoadBalancer $loadBalancer, Language $contentLanguage ) {
		$this->loadBalancer = $loadBalancer;
		$this->contentLanguage = $contentLanguage;
	}

	/**
	 * Mark imported pages as forked so that the page creation succeeds
	 * on imported edits
	 *
	 * @param WikiImporter $reader
	 * @param array &$pageInfo
	 * @throws MWException on error
	 */
	public function onImportHandlePageXMLTag( $reader, &$pageInfo ) {
		static $cache = [];

		// check if we've parsed enough of the <page> tag to determine what the title is
		$tag = $reader->getReader();
		if ( $tag->localName !== 'revision' && $tag->localName !== 'upload' ) {
			return;
		}

		$foreignNs = intval( $pageInfo['ns'] ?? 0 );
		$cacheKey = "{$foreignNs}:{$pageInfo['title']}";
		if ( array_key_exists( $cacheKey, $cache ) ) {
			// we already processed this title
			return;
		}

		// all of this stuff is annoyingly marked private in WikiImporter
		// and there are no public accessor methods
		$class = get_class( $reader );
		$foreignNamespaces = ReflectionHelper::getPrivateProperty( $class, 'foreignNamespaces', $reader );
		/** @var ImportTitleFactory $importTitleFactory */
		$importTitleFactory = ReflectionHelper::getPrivateProperty( $class, 'importTitleFactory', $reader );

		if ( $foreignNamespaces === null ) {
			$foreignTitleFactory = new NaiveForeignTitleFactory( $this->contentLanguage );
		} else {
			$foreignTitleFactory = new NamespaceAwareForeignTitleFactory( $foreignNamespaces );
		}

		$foreignTitle = $foreignTitleFactory->createForeignTitle( $pageInfo['title'], $foreignNs );
		$title = $importTitleFactory->createTitleFromForeignTitle( $foreignTitle );

		// if we get here, we've "processed" this title; we cache it regardless of whether or not
		// we actually inserted any rows into forked_titles
		$cache[$cacheKey] = true;

		if ( $title === null || $title->isExternal() || !$title->canExist() ) {
			// this error will be picked up later by the import machinery
			// and will properly alert the user of the bad title
			return;
		}

		if ( $title->exists() ) {
			// if the title already exists, it was either created locally or already forked;
			// in either case we don't need to add it to forked_titles
			return;
		}

		// add the record to forked_titles if it doesn't already exist
		// this allows the Mirror service to recognize the title as forked so it allows page creation/editing
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->insert( 'forked_titles', [
			'ft_namespace' => $title->getNamespace(),
			'ft_title' => $title->getDBkey(),
			'ft_forked' => wfTimestampNow(),
			'ft_imported' => 1
		], __METHOD__, [ 'IGNORE' ] );
	}
}
