<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Title;
use WikiMirror\Mirror\Mirror;
use WikiMirror\Mirror\RemoteRevisionRecord;

class RevisionLookupManipulator implements RevisionLookup {
	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var Mirror */
	private $mirror;

	/**
	 * RevisionLookupManipulator constructor.
	 *
	 * @param RevisionLookup $revisionLookup
	 * @param Mirror $mirror
	 */
	public function __construct( RevisionLookup $revisionLookup, Mirror $mirror ) {
		$this->revisionLookup = $revisionLookup;
		$this->mirror = $mirror;
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionById( $id, $flags = 0, $page = null ) {
		// In 1.35, getRevisionById only takes 2 args, however it's safe in PHP to pass
		// too many arguments to a thing, so suppress phan for 1.35 instead of doing a version_compare
		// @phan-suppress-next-line PhanParamTooMany
		return $this->revisionLookup->getRevisionById( $id, $flags, $page );
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionByTitle( $page, $revId = 0, $flags = 0 ) {
		return $this->revisionLookup->getRevisionByTitle( $page, $revId, $flags );
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionByPageId( $pageId, $revId = 0, $flags = 0 ) {
		return $this->revisionLookup->getRevisionByPageId( $pageId, $revId, $flags );
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionByTimestamp(
		$page,
		string $timestamp,
		int $flags = RevisionLookup::READ_NORMAL
	): ?RevisionRecord {
		return $this->revisionLookup->getRevisionByTimestamp( $page, $timestamp, $flags );
	}

	/**
	 * @inheritDoc
	 */
	public function getPreviousRevision( RevisionRecord $rev, $flags = 0 ) {
		return $this->revisionLookup->getPreviousRevision( $rev, $flags );
	}

	/**
	 * @inheritDoc
	 */
	public function getNextRevision( RevisionRecord $rev, $flags = 0 ) {
		return $this->revisionLookup->getNextRevision( $rev, $flags );
	}

	/**
	 * @inheritDoc
	 */
	public function getTimestampFromId( $id, $flags = 0 ) {
		return $this->revisionLookup->getTimestampFromId( $id, $flags );
	}

	/**
	 * @inheritDoc
	 * @suppress PhanUndeclaredClassInstanceOf, PhanUndeclaredClassMethodCall
	 */
	public function getKnownCurrentRevision( $page, $revId = 0 ) {
		if ( $page instanceof Title ) {
			$title = $page;
		} elseif ( interface_exists( '\MediaWiki\Page\PageIdentity' ) && $page instanceof PageIdentity ) {
			// 1.36+, remove phan suppressions from doc comment above when we drop 1.35 support
			$title = Title::newFromDBkey( $page->getDBkey() );
		} else {
			// should never happen
			return false;
		}

		if ( $title === null ) {
			// invalid title somehow, should never happen
			// but check for it anyway to make phan happy
			return false;
		}

		if ( !$revId && $this->mirror->canMirror( $title ) ) {
			$status = $this->mirror->getCachedPage( $title );
			if ( $status->isOK() ) {
				return new RemoteRevisionRecord( $status->getValue() );
			}
		}

		return $this->revisionLookup->getKnownCurrentRevision( $page, $revId );
	}

	/**
	 * @inheritDoc
	 */
	public function getFirstRevision(
		$page,
		int $flags = IDBAccessObject::READ_NORMAL
	): ?RevisionRecord {
		return $this->revisionLookup->getFirstRevision( $page, $flags );
	}
}
