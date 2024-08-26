<?php

namespace WikiMirror\Fork;

use CommentStore;
use ContentHandler;
use ErrorPageError;
use Exception;
use ExternalUserNames;
use Html;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\User\UserOptionsLookup;
use MWException;
use OldRevisionImporter;
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
	/** @var Title|null */
	protected ?Title $title;

	/** @var ILoadBalancer */
	protected ILoadBalancer $loadBalancer;

	/** @var Mirror */
	protected Mirror $mirror;

	/** @var OldRevisionImporter */
	protected OldRevisionImporter $importer;

	/** @var UserOptionsLookup */
	protected UserOptionsLookup $lookup;

	/** @var bool */
	private bool $watch;

	/** @var bool */
	private bool $import;

	/** @var string */
	private string $comment;

	/**
	 * Special page to fork a mirrored page's content locally.
	 *
	 * @param ILoadBalancer $loadBalancer
	 * @param Mirror $mirror
	 * @param OldRevisionImporter $importer
	 * @param UserOptionsLookup $lookup
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		Mirror $mirror,
		OldRevisionImporter $importer,
		UserOptionsLookup $lookup
	) {
		parent::__construct( 'Fork', 'fork' );
		$this->loadBalancer = $loadBalancer;
		$this->mirror = $mirror;
		$this->importer = $importer;
		$this->lookup = $lookup;
	}

	/**
	 * Display the form or perform the fork.
	 *
	 * @param string|null $subPage Title being forked
	 * @throws ReadOnlyError If wiki is read only
	 * @throws ErrorPageError If given title cannot be forked for any reason
	 * @throws MWException On internal error
	 * @throws OOUI\Exception If a programming error occurs
	 */
	public function execute( $subPage ) {
		// do permission checks, etc.
		parent::execute( $subPage );
		$this->useTransactionalTimeLimit();
		$this->checkReadOnly();

		$this->title = Title::newFromText( $subPage );
		if ( $this->title === null ) {
			throw new ErrorPageError( 'notargettitle', 'notargettext' );
		}

		if ( $this->title->hasFragment() ) {
			throw new ErrorPageError( 'badtitle', 'badtitletext' );
		}

		$out = $this->getOutput();
		$out->addBacklinkSubtitle( $this->title );

		// check if this title is currently being mirrored;
		// if not we can't fork it
		if ( !$this->mirror->canMirror( $this->title ) ) {
			throw new ErrorPageError( 'wikimirror-nofork-title', 'wikimirror-nofork-text' );
		}

		$this->getSkin()->setRelevantTitle( $this->title );
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->setPageTitle( $this->msg( 'wikimirror-fork-title', $this->title->getPrefixedText() ) );
		$out->addModuleStyles( [ 'mediawiki.special', 'ext.WikiMirror' ] );
		$out->addModules( 'mediawiki.misc-authed-ooui' );

		$request = $this->getRequest();
		$user = $this->getUser();

		// Use getBool() instead of getCheck() so we can set default values
		// and so that it can be overridden via query string in the initial GET request.
		// If we were POSTed, do not default anything to true so that we can properly detect when the
		// user deselected the checkbox.
		$this->watch = $request->getBool( 'wpWatch',
			!$request->wasPosted() && $this->lookup->getBoolOption( $user, 'watchcreations' ) );
		$this->import = $request->getBool( 'wpImport', !$request->wasPosted() );
		$this->comment = $request->getText( 'wpComment' );

		$editTokenValid = $this->getContext()->getCsrfTokenSet()->matchTokenField();
		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'submit' && $editTokenValid ) {
			$this->doFork();
			$messageArgs = [ wfEscapeWikiText( $this->title->getPrefixedText() ) ];
			if ( $this->import ) {
				$message = 'wikimirror-import-success';
			} else {
				$message = 'wikimirror-delete-success';
				$messageArgs[] = '[[Special:Log/delete|' . $this->msg( 'deletionlog' )->text() . ']]';
			}

			$out->addWikiMsgArray( $message, $messageArgs );
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
	 * @throws Exception When failing to publish log entries
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

		$config = $this->getConfig();
		$user = $this->getUser();

		// mark page as forked
		$dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );
		$dbw->insert( 'forked_titles', [
			'ft_namespace' => $this->title->getNamespace(),
			'ft_title' => $this->title->getDBkey(),
			'ft_remote_page' => $pageInfo->pageId,
			'ft_remote_revision' => $pageInfo->lastRevisionId,
			'ft_forked' => wfTimestampNow(),
			// if not importing revisions, consider this page fully imported
			'ft_imported' => !$this->import
		], __METHOD__ );

		if ( !$this->import ) {
			// we are not importing, so marking the page as deleted is sufficient
			// add an entry to the deletion log with the user's comment
			$logType = 'delete';
		} else {
			// actually create the page, re-using the import machinery to avoid code duplication
			// also mark the title as being imported so our mirroring doesn't interfere with the import
			$this->mirror->markForImport( $this->title );
			$content = ContentHandler::makeContent( $textInfo->wikitext, $this->title, $revInfo->mainContentModel );
			$externalUserNames = new ExternalUserNames(
				$config->get( 'WikiMirrorRemote' ),
				$config->get( 'WikiMirrorAssignKnownUsers' )
			);

			$revision = new WikiRevision();
			$revision->setTitle( $this->title );
			$revision->setContent( SlotRecord::MAIN, $content );
			$revision->setTimestamp( $revInfo->timestamp );
			$revision->setComment( $revInfo->comment );
			if ( $revInfo->remoteUserId === 0 ) {
				$revision->setUsername( $revInfo->user );
			} else {
				$revision->setUsername( $externalUserNames->applyPrefix( $revInfo->user ) );
			}

			if ( !$this->importer->import( $revision ) ) {
				$dbw->cancelAtomic( __METHOD__ );
				throw new ErrorPageError( 'wikimirror-nofork-title', 'wikimirror-nofork-text' );
			}
			$logType = 'import';
		}

		// add the log entry
		$logEntry = new ManualLogEntry( $logType, 'fork' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $this->title );
		$logEntry->setComment( $this->comment );
		$logEntry->setParameters( [
			'4:title-link:interwiki' =>
				$config->get( 'WikiMirrorRemote' ) . ':' . $this->title->getPrefixedText()
		] );
		$logId = $logEntry->insert( $dbw );

		if ( $this->watch ) {
			// add the title to the user's watchlist
			// clear Title cache first since we now have a page id
			Title::clearCaches();
			$newTitle = Title::newFromDBkey( $this->title->getDBkey() );
			$watchlistManager = MediaWikiServices::getInstance()->getWatchlistManager();
			$watchlistManager->addWatch( $user, $newTitle );
		}

		$dbw->endAtomic( __METHOD__ );

		// send log entry to feeds now that we've committed the transaction
		$logEntry->publish( $logId );
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

		$fields = [];

		$fields[] = new OOUI\FieldLayout( new OOUI\TextInputWidget( [
			'name' => 'wpComment',
			'id' => 'wpComment',
			'maxLength' => CommentStore::COMMENT_CHARACTER_LIMIT,
			'value' => $this->comment
		] ), [
			'label' => $this->msg( 'wikimirror-fork-comment' )->text(),
			'align' => 'top'
		] );

		$fields[] = new OOUI\FieldLayout( new OOUI\CheckboxInputWidget( [
			'name' => 'wpImport',
			'id' => 'wpImport',
			'value' => '1',
			'selected' => $this->import
		] ), [
			'label' => $this->msg( 'wikimirror-fork-import' )->text(),
			'align' => 'inline'
		] );

		$fields[] = new OOUI\FieldLayout( new OOUI\CheckboxInputWidget( [
			'name' => 'wpWatch',
			'id' => 'wpWatch',
			'value' => '1',
			'selected' => $this->watch
		] ), [
			'label' => $this->msg( 'watchthis' )->text(),
			'align' => 'inline'
		] );

		$fields[] = new OOUI\FieldLayout( new OOUI\ButtonInputWidget( [
			'name' => 'wpFork',
			'value' => $this->msg( 'fork' )->text(),
			'label' => $this->msg( 'fork' )->text(),
			'flags' => [ 'primary', 'progressive' ],
			'type' => 'submit',
		] ), [
			'align' => 'top'
		] );

		$fieldset = new OOUI\FieldsetLayout( [
			'id' => 'mw-fork-table',
			'label' => $this->msg( 'fork' )->text(),
			'items' => $fields
		] );

		$subpage = $this->title->getPrefixedText();
		$editToken = $this->getContext()->getCsrfTokenSet()->getToken();
		$editTokenFieldName = CsrfTokenSet::DEFAULT_FIELD_NAME;

		$form = new OOUI\FormLayout( [
			'method' => 'post',
			'action' => $this->getPageTitle( $subpage )->getLocalURL( 'action=submit' ),
			'id' => 'forkpage'
		] );

		$form->appendContent(
			$fieldset,
			new OOUI\HtmlSnippet(
				Html::hidden( $editTokenFieldName, $editToken )
			)
		);

		$out->addHTML( new OOUI\PanelLayout( [
			'classes' => [ 'fork-wrapper' ],
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'content' => $form
		] ) );
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
