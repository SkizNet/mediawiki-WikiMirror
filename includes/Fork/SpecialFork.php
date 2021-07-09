<?php

namespace WikiMirror\Fork;

use ContentHandler;
use ErrorPageError;
use ExternalUserNames;
use HashConfig;
use Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MWException;
use OOUI;
use ReadOnlyError;
use Title;
use UnlistedSpecialPage;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiMirror\API\PageInfoResponse;
use WikiMirror\API\ParseResponse;
use WikiMirror\Mirror\Mirror;
use WikiRevision;

class SpecialFork extends UnlistedSpecialPage {
	/** @var Title */
	protected $title;

	/** @var ILoadBalancer */
	protected $loadBalancer;

	/** @var Mirror */
	protected $mirror;

	/**
	 * Special page to fork a mirrored page's content locally.
	 *
	 * @param ILoadBalancer $loadBalancer
	 * @param Mirror $mirror
	 */
	public function __construct( ILoadBalancer $loadBalancer, Mirror $mirror ) {
		parent::__construct( 'Fork', 'fork' );
		$this->loadBalancer = $loadBalancer;
		$this->mirror = $mirror;
	}

	/**
	 * Display the form or perform the fork.
	 *
	 * @param string|null $subPage Title being forked
	 * @throws ReadOnlyError If wiki is read only
	 * @throws ErrorPageError If given title cannot be forked for any reason
	 * @throws MWException On internal error
	 */
	public function execute( $subPage ) {
		$this->useTransactionalTimeLimit();
		$this->checkReadOnly();
		$this->setHeaders();
		$this->outputHeader();

		$this->title = Title::newFromText( $subPage );
		if ( $this->title === null ) {
			throw new ErrorPageError( 'notargettitle', 'notargettext' );
		}

		if ( $this->title->hasFragment() ) {
			throw new ErrorPageError( 'badtitle', 'badtitletext' );
		}

		$this->getOutput()->addBacklinkSubtitle( $this->title );

		// check if this title is currently being mirrored;
		// if not we can't fork it
		if ( !$this->mirror->canMirror( $this->title ) ) {
			throw new ErrorPageError( 'wikimirror-nofork-title', 'wikimirror-nofork-text' );
		}

		$this->getSkin()->setRelevantTitle( $this->title );
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'wikimirror-fork-title', $this->title->getPrefixedText() ) );
		$out->addModuleStyles( 'mediawiki.special' );
		$out->addModules( 'mediawiki.misc-authed-ooui' );

		$request = $this->getRequest();

		if ( $request->wasPosted()
			&& $request->getVal( 'action' ) === 'submit'
			&& $this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) )
		) {
			$this->doFork();
		} else {
			$this->showForm();
		}
	}

	/**
	 * Perform the fork, bringing the most recent revision of the page
	 * over immediately and enqueueing jobs to transfer the rest of the history.
	 * Templates are not forked and will continue to use the mirrored version.
	 *
	 * @throws ErrorPageError On fork error
	 * @throws MWException On internal error
	 */
	protected function doFork() {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$pageStatus = $this->mirror->getCachedPage( $this->title );
		$textStatus = $this->mirror->getCachedText( $this->title );

		if ( !$pageStatus->isGood() || !$textStatus->isGood() ) {
			throw new ErrorPageError( 'wikimirror-nofork-title', 'wikimirror-nofork-text' );
		}

		/** @var PageInfoResponse $pageInfo */
		$pageInfo = $pageStatus->getValue();
		$revInfo = $pageInfo->lastRevision;

		/** @var ParseResponse $textInfo */
		$textInfo = $textStatus->getValue();

		// we only support importing the main slot at the moment; throw if the remote page has multiple slots
		if ( count( $revInfo->slots ) != 1 || $revInfo->slots[0] !== SlotRecord::MAIN ) {
			throw new ErrorPageError( 'wikimirror-nofork-title', 'wikimirror-nofork-text' );
		}

		// mark page as forked
		$dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );
		$dbw->insert( 'forked_titles', [
			'ft_namespace' => $this->title->getNamespace(),
			'ft_title' => $this->title->getDBkey(),
			'ft_remote_page' => $pageInfo->pageId,
			'ft_remote_revision' => $pageInfo->lastRevisionId,
			'ft_forked' => wfTimestampNow()
		], __METHOD__ );

		// FIXME: if we're forking the page as "deleted" then we're done

		// actually create the page, re-using the import machinery to avoid code duplication
		$content = ContentHandler::makeContent( $textInfo->wikitext, $this->title, $revInfo->mainContentModel );
		$config = $this->getConfig();
		$externalUserNames = new ExternalUserNames(
			$config->get( 'WikiMirrorRemote' ),
			$config->get( 'WikiMirrorAssignKnownUsers' )
		);

		$revision = new WikiRevision( new HashConfig() );
		$revision->setTitle( $this->title );
		$revision->setContent( SlotRecord::MAIN, $content );
		$revision->setTimestamp( $revInfo->timestamp );
		$revision->setComment( $revInfo->comment );
		if ( $revInfo->remoteUserId === 0 ) {
			$revision->setUserIP( $revInfo->user );
		} else {
			$revision->setUsername( $externalUserNames->applyPrefix( $revInfo->user ) );
		}

		$oldRevisionImporter = MediaWikiServices::getInstance()->getWikiRevisionOldRevisionImporter();
		$oldRevisionImporter->import( $revision );

		// FIXME: check if we should add this page to the user's watchlist
	}

	/**
	 * Display the form describing how forking works plus a button to let the user
	 * confirm the fork action.
	 *
	 * @throws OOUI\Exception
	 */
	protected function showForm() {
		$out = $this->getOutput();
		$out->addWikiMsg( 'wikimirror-fork-text' );
		$out->enableOOUI();

		// FIXME: add checkboxes to delete the page and watch the page, and wrap them all into a frameset

		$button = new OOUI\ButtonInputWidget( [
			'name' => 'wpFork',
			'value' => $this->msg( 'fork' )->text(),
			'label' => $this->msg( 'fork' )->text(),
			'flags' => [ 'primary', 'progressive' ],
			'type' => 'submit',
		] );

		$subpage = $this->title->getPrefixedText();
		$form = new OOUI\FormLayout( [
			'method' => 'post',
			'action' => $this->getPageTitle( $subpage )->getLocalURL( 'action=submit' ),
			'id' => 'forkpage',
		] );

		$form->appendContent(
			$button,
			new OOUI\HtmlSnippet(
				Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() )
			)
		);

		$out->addHTML( $form );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	public function getGroupName() {
		return 'pagetools';
	}
}
