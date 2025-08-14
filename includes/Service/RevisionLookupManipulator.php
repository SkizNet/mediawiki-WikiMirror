<?php

namespace WikiMirror\Service;

use IDBAccessObject;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use RuntimeException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Mirror\LazyMirror;
use WikiMirror\Mirror\RemoteRevisionRecord;

class RevisionLookupManipulator implements RevisionLookup {
	/** @var RevisionLookup */
	private RevisionLookup $revisionLookup;

	/** @var LazyMirror */
	private LazyMirror $mirror;

	/**
	 * RevisionLookupManipulator constructor.
	 *
	 * @param RevisionLookup $revisionLookup
	 * @param LazyMirror $mirror
	 */
	public function __construct( RevisionLookup $revisionLookup, LazyMirror $mirror ) {
		$this->revisionLookup = $revisionLookup;
		$this->mirror = $mirror;
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionById( $id, $flags = 0, $page = null ) {
		return $this->revisionLookup->getRevisionById( $id, $flags, $page );
	}

	/**
	 * @inheritDoc
	 */
	public function getRevisionByTitle( $page, $revId = 0, $flags = 0 ) {
		$title = Title::newFromPageIdentity( $page );
		$mirror = $this->mirror->getMirror();
		if ( !$revId && $mirror->canMirror( $title, true ) ) {
			$status = $mirror->getCachedPage( $title );
			if ( !$status->isOK() ) {
				throw new RuntimeException( (string)$status );
			}

			return new RemoteRevisionRecord( $status->getValue() );
		}

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
		int $flags = IDBAccessObject::READ_NORMAL
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
	 */
	public function getKnownCurrentRevision( PageIdentity $page, $revId = 0 ) {
		$title = Title::newFromPageIdentity( $page );
		$mirror = $this->mirror->getMirror();

		if ( !$revId && $mirror->canMirror( $title, true ) ) {
			$status = $mirror->getCachedPage( $title );
			if ( !$status->isOK() ) {
				throw new RuntimeException( (string)$status );
			}

			/** @var ?PageInfoResponse $pageInfo */
			$pageInfo = $status->getValue();
			if ( $pageInfo === null ) {
				return false;
			}

			return new RemoteRevisionRecord( $pageInfo );
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
