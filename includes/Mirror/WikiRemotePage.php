<?php

namespace WikiMirror\Mirror;

use IDBAccessObject;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentity;
use MWException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Compat\ReflectionHelper;
use WikiPage;

class WikiRemotePage extends WikiPage {
	private MirrorPageRecord $mirrorRecord;

	/**
	 * @param PageIdentity $pageIdentity
	 * @param MirrorPageRecord $mirrorRecord
	 */
	public function __construct( PageIdentity $pageIdentity, MirrorPageRecord $mirrorRecord ) {
		parent::__construct( $pageIdentity );
		$this->mirrorRecord = $mirrorRecord;
	}

	/** @inheritDoc */
	public function exists(): bool {
		return $this->mirrorRecord->exists();
	}

	/** @inheritDoc */
	public function getId( $wikiId = self::LOCAL ): int {
		return $this->mirrorRecord->getId( $wikiId );
	}

	/** @inheritDoc */
	public function isNew() {
		return $this->mirrorRecord->isNew();
	}

	/** @inheritDoc */
	public function isRedirect() {
		return $this->mirrorRecord->isRedirect();
	}

	/** @inheritDoc */
	public function getTouched() {
		return $this->mirrorRecord->getTouched();
	}

	/** @inheritDoc */
	public function getLanguage() {
		return $this->mirrorRecord->getLanguage();
	}

	/** @inheritDoc */
	public function getLatest( $wikiId = self::LOCAL ) {
		return $this->mirrorRecord->getLatest( $wikiId );
	}

	/** @inheritDoc */
	public function getContentModel() {
		return CONTENT_MODEL_MIRROR;
	}

	/** @inheritDoc */
	public function loadPageData( $from = 'fromdb' ) {
		$from = self::convertSelectType( $from );
		if ( !is_int( $from ) ) {
			// passed a row object
			$this->loadFromRow( $from, IDBAccessObject::READ_NORMAL );
			return;
		}

		// check if we're already loaded
		if ( $from <= $this->mDataLoadedFrom ) {
			return;
		}

		// construct a row object given information from the remote wiki
		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$status = $mirror->getCachedPage( $this->mTitle );

		if ( !$status->isOK() ) {
			$row = false;
		} else {
			/** @var PageInfoResponse $pageData */
			$pageData = $status->getValue();
			$row = (object)[
				// we need to trick mediawiki into thinking this page exists locally
				// this makes canMirror checks a bit trickier however as we can't rely on $title->exists()
				'page_id' => $pageData->pageId,
				'page_namespace' => $this->mTitle->getNamespace(),
				'page_title' => $this->mTitle->getDBkey(),
				'page_restrictions' => '',
				'page_is_redirect' => $pageData->redirect !== null,
				'page_is_new' => 0,
				'page_touched' => wfTimestamp( TS_MW, $pageData->touched ),
				'page_links_updated' => null,
				'page_latest' => 0,
				'page_len' => $pageData->length,
				'page_content_model' => 'mirror',
				'page_lang' => $pageData->pageLanguage
			];
		}

		$this->loadFromRow( $row, IDBAccessObject::READ_LATEST );
	}

	/** @inheritDoc */
	protected function loadLastEdit() {
		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$status = $mirror->getCachedPage( $this->mTitle );
		if ( !$status->isOK() ) {
			throw new MWException( $status->getMessage() );
		}

		/** @var PageInfoResponse $revData */
		$revData = $status->getValue();

		$revision = new RemoteRevisionRecord( $revData );
		// Annoyingly setLastEdit is marked as private instead of protected in WikiPage.
		ReflectionHelper::callPrivateMethod( WikiPage::class, 'setLastEdit', $this, [ $revision ] );
	}

	/** @inheritDoc */
	public function getRedirectTarget() {
		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$status = $mirror->getCachedPage( $this->mTitle );
		if ( !$status->isOK() ) {
			throw new MWException( $status->getMessage() );
		}

		/** @var PageInfoResponse $pageData */
		$pageData = $status->getValue();

		return $pageData->redirect;
	}

	/** @inheritDoc */
	public function toPageRecord(): ExistingPageRecord {
		return $this->mirrorRecord;
	}
}
