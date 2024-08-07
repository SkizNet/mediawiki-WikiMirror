<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\Title;
use RuntimeException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Mirror\Mirror;
use WikiMirror\Mirror\MirrorPageRecord;

class PageLookupManipulator implements PageLookup {
	/** @var PageLookup */
	private PageLookup $pageLookup;

	/** @var Mirror */
	private Mirror $mirror;

	/**
	 * PageLookupManipulator constructor.
	 *
	 * @param PageLookup $pageLookup
	 * @param Mirror $mirror
	 */
	public function __construct( PageLookup $pageLookup, Mirror $mirror ) {
		$this->pageLookup = $pageLookup;
		$this->mirror = $mirror;
	}

	/**
	 * @inheritDoc
	 */
	public function getPageForLink(
		LinkTarget $link,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ProperPageIdentity {
		return $this->pageLookup->getPageForLink( $link, $queryFlags );
	}

	/**
	 * @inheritDoc
	 */
	public function getPageById(
		int $pageId,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingPageRecord {
		return $this->pageLookup->getPageById( $pageId, $queryFlags );
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
	public function getPageByText(
		string $text,
		int $defaultNamespace = NS_MAIN,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ?ProperPageIdentity {
		return $this->pageLookup->getPageByText( $text, $defaultNamespace, $queryFlags );
	}

	/**
	 * @inheritDoc
	 */
	public function getExistingPageByText(
		string $text,
		int $defaultNamespace = NS_MAIN,
		int $queryFlags = IDBAccessObject::READ_NORMAL
	): ?ExistingPageRecord {
		$title = Title::newFromText( $text, $defaultNamespace );
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

		return $this->pageLookup->getPageByReference( $page, $queryFlags );
	}
}
