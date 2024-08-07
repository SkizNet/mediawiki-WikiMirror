<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\Title;
use ReflectionClass;
use RuntimeException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Mirror\Mirror;
use WikiMirror\Mirror\MirrorPageRecord;

class PageStoreManipulator extends PageStore {
	/** @var Mirror */
	private Mirror $mirror;

	/**
	 * PageStoreManipulator constructor.
	 *
	 * @param PageStore $original
	 * @param Mirror $mirror
	 */
	public function __construct( PageStore $original, Mirror $mirror ) {
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
		$title = Title::makeTitleSafe( $namespace, $dbKey );
		if ( $title === null ) {
			return null;
		}

		return $this->getPageByReference( $title, $queryFlags );
	}

	/**
	 * @inheritDoc
	 */
	public function getPageByReference(
		PageReference $page,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingPageRecord {
		// should refactor this so the Mirror service takes on the new PageWhatever interfaces instead of Title
		$title = Title::newFromPageReference( $page );
		if ( $this->mirror->canMirror( $title, true ) ) {
			$status = $this->mirror->getCachedPage( $title );
			if ( !$status->isOK() ) {
				throw new RuntimeException( (string)$status );
			}

			/** @var PageInfoResponse $pageInfo */
			$pageInfo = $status->getValue();

			return new MirrorPageRecord( $pageInfo );
		}

		return parent::getPageByReference( $page, $queryFlags );
	}
}
