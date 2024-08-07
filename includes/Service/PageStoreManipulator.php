<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\Title;
use ReflectionClass;
use RuntimeException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Mirror\LazyMirror;
use WikiMirror\Mirror\MirrorPageRecord;

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
			$status = $mirror->getCachedPage( $title );
			if ( !$status->isOK() ) {
				throw new RuntimeException( (string)$status );
			}

			/** @var PageInfoResponse $pageInfo */
			$pageInfo = $status->getValue();

			return new MirrorPageRecord( $pageInfo );
		}

		return parent::getPageByName( $namespace, $dbKey, $queryFlags );
	}
}
