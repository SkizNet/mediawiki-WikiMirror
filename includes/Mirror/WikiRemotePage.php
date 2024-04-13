<?php

namespace WikiMirror\Mirror;

use IDBAccessObject;
use MediaWiki\MediaWikiServices;
use MWException;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\Compat\ReflectionHelper;
use WikiPage;

class WikiRemotePage extends WikiPage {
	/**
	 * @inheritDoc
	 */
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

	/**
	 * @inheritDoc
	 * @throws MWException On error
	 */
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

	/**
	 * @inheritDoc
	 * @throws MWException On error
	 */
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
}
