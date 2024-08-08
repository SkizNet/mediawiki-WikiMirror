<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\Title;
use ReflectionClass;
use WikiMirror\Mirror\LazyMirror;

class PageStoreManipulator extends PageStore {
	/** @var LazyMirror */
	private LazyMirror $mirror;

	/**
	 * PageStoreManipulator constructor.
	 *
	 * @param PageStore $original
	 * @param LazyMirror $mirror
	 */
	public function __construct( PageStore $original, LazyMirror $mirror ) {
		$this->mirror = $mirror;

		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPageByName(
		int $namespace,
		string $dbKey,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingPageRecord {
		$mirror = $this->mirror->getMirror();
		$title = Title::makeTitleSafe( $namespace, $dbKey );
		if ( $title === null ) {
			return null;
		}

		if ( $mirror->canMirror( $title, true ) ) {
			return $mirror->getMirrorPageRecord( $namespace, $dbKey );
		}

		return parent::getPageByName( $namespace, $dbKey, $queryFlags );
	}
}
