<?php

namespace WikiMirror\Mirror;

use MediaWiki\MediaWikiServices;
use MWException;
use ReflectionClass;
use ReflectionException;
use WikiPage;

class WikiRemotePage extends WikiPage {
	/**
	 * @inheritDoc
	 */
	public function loadPageData( $from = 'fromdb' ) {
		$from = self::convertSelectType( $from );
		if ( !is_int( $from ) ) {
			// passed a row object
			$this->loadFromRow( $from, self::READ_NORMAL );
			return;
		}

		// check if we're already loaded
		if ( $from <= $this->mDataLoadedFrom ) {
			return;
		}

		// construct a row object given information from the remote wiki
		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$pageData = $mirror->getCachedPage( $this->mTitle );

		if ( $pageData === null ) {
			$row = false;
		} else {
			$row = (object)[
				// don't expose remote page/rev id, we want mw to know this page doesn't exist locally
				'page_id' => 0,
				'page_namespace' => $this->mTitle->getNamespace(),
				'page_title' => $this->mTitle->getDBkey(),
				'page_restrictions' => '',
				'page_is_redirect' => $pageData->redirect !== null,
				'page_is_new' => 0,
				'page_touched' => wfTimestamp( TS_MW, $pageData->touched ),
				'page_links_updated' => null,
				'page_latest' => 0,
				'page_len' => $pageData->length,
				'page_content_model' => $pageData->contentModel,
				'page_lang' => $pageData->pageLanguage
			];
		}

		$this->loadFromRow( $row, self::READ_LATEST );
	}

	/**
	 * @inheritDoc
	 * @throws ReflectionException If the underlying MW version is unsupported by this extension
	 * @throws MWException On error
	 */
	protected function loadLastEdit() {
		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$revData = $mirror->getCachedPage( $this->mTitle );
		$revision = new RemoteRevisionRecord( $revData );

		// annoyingly setLastEdit is marked as private instead of protected in WikiPage...
		$reflection = new ReflectionClass( WikiPage::class );
		$setLastEdit = $reflection->getMethod( 'setLastEdit' );
		$setLastEdit->invoke( $this, $revision );
	}
}
