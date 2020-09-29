<?php

namespace WikiMirror\Mirror;

use MediaWiki\MediaWikiServices;
use MWException;
use ReflectionClass;
use ReflectionException;
use WikiMirror\API\PageInfoResponse;
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

		$this->loadFromRow( $row, self::READ_LATEST );
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
		$this->callPrivateMethod( WikiPage::class, 'setLastEdit', $this, [ $revision ] );
	}

	/**
	 * Call a private method via Reflection
	 *
	 * @param string $class Class name
	 * @param string $method Method name
	 * @param object|null $object Object to call method on, or null for a static method
	 * @param array $args Arguments to method
	 * @return mixed Return value of method called
	 * @throws MWException On error
	 */
	private function callPrivateMethod( string $class, string $method, ?object $object, $args = [] ) {
		try {
			$reflection = new ReflectionClass( $class );
			$method = $reflection->getMethod( $method );
			$method->setAccessible( true );
			$value = $method->invokeArgs( $object, $args );
			$method->setAccessible( false );
			return $value;
		} catch ( ReflectionException $e ) {
			// wrap the ReflectionException into a MWException for friendlier error display
			throw new MWException(
				'The WikiMirror extension is not compatible with your MediaWiki version', 0, $e );
		}
	}
}
