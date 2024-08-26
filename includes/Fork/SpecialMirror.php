<?php

namespace WikiMirror\Fork;

use CommentStore;
use ErrorPageError;
use Exception;
use Html;
use ManualLogEntry;
use MediaWiki\Session\CsrfTokenSet;
use MWException;
use OOUI;
use ReadOnlyError;
use Title;
use UnlistedSpecialPage;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiMirror\Mirror\Mirror;

class SpecialMirror extends UnlistedSpecialPage {
	/** @var Title|null */
	protected ?Title $title;

	/** @var ILoadBalancer */
	protected ILoadBalancer $loadBalancer;

	/** @var Mirror */
	protected Mirror $mirror;

	/** @var string */
	private string $comment;

	/**
	 * Special page to un-fork a deleted page.
	 *
	 * @param ILoadBalancer $loadBalancer
	 * @param Mirror $mirror
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		Mirror $mirror
	) {
		parent::__construct( 'Mirror', 'delete' );
		$this->loadBalancer = $loadBalancer;
		$this->mirror = $mirror;
	}

	/**
	 * Display the form or perform the fork.
	 *
	 * @param string|null $subPage Title being forked
	 */
	public function execute( $subPage ) {
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

		// check if this title is currently forked and deleted
		// if either is false, we cannot re-mirror it
		if ( !$this->mirror->isForked( $this->title ) || $this->title->exists() ) {
			throw new ErrorPageError( 'wikimirror-nomirror-title', 'wikimirror-nomirror-text' );
		}

		$this->getSkin()->setRelevantTitle( $this->title );
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->setPageTitle( $this->msg( 'wikimirror-mirror-title', $this->title->getPrefixedText() ) );
		$out->addModuleStyles( [ 'mediawiki.special', 'ext.WikiMirror' ] );
		$out->addModules( 'mediawiki.misc-authed-ooui' );

		$request = $this->getRequest();
		$this->comment = $request->getText( 'wpComment' );

		$editTokenValid = $this->getContext()->getCsrfTokenSet()->matchTokenField();
		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'submit' && $editTokenValid ) {
			$this->doMirror();
			$out->addWikiMsg( 'wikimirror-mirror-success', $this->title->getPrefixedText() );
		} else {
			$this->showForm();
		}
	}

	/**
	 * Restore a page to being mirrored.
	 */
	protected function doMirror() {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );

		$config = $this->getConfig();
		$user = $this->getUser();

		// mark page as forked
		$dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );
		$dbw->delete( 'forked_titles', [
			'ft_namespace' => $this->title->getNamespace(),
			'ft_title' => $this->title->getDBkey(),
		], __METHOD__ );

		// add the log entry
		$logEntry = new ManualLogEntry( 'delete', 'mirror' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $this->title );
		$logEntry->setComment( $this->comment );
		$logEntry->setParameters( [
			'4:title-link:interwiki' =>
				$config->get( 'WikiMirrorRemote' ) . ':' . $this->title->getPrefixedText()
		] );
		$logId = $logEntry->insert( $dbw );
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
		$out->addWikiMsg( 'wikimirror-mirror-text' );
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

		$fields[] = new OOUI\FieldLayout( new OOUI\ButtonInputWidget( [
			'name' => 'wpMirror',
			'value' => $this->msg( 'mirror' )->text(),
			'label' => $this->msg( 'mirror' )->text(),
			'flags' => [ 'primary', 'progressive' ],
			'type' => 'submit',
		] ), [
			'align' => 'top'
		] );

		$fieldset = new OOUI\FieldsetLayout( [
			'id' => 'mw-mirror-table',
			'label' => $this->msg( 'mirror' )->text(),
			'items' => $fields
		] );

		$subpage = $this->title->getPrefixedText();
		$editToken = $this->getContext()->getCsrfTokenSet()->getToken();
		$editTokenFieldName = CsrfTokenSet::DEFAULT_FIELD_NAME;

		$form = new OOUI\FormLayout( [
			'method' => 'post',
			'action' => $this->getPageTitle( $subpage )->getLocalURL( 'action=submit' ),
			'id' => 'mirrorpage'
		] );

		$form->appendContent(
			$fieldset,
			new OOUI\HtmlSnippet(
				Html::hidden( $editTokenFieldName, $editToken )
			)
		);

		$out->addHTML( new OOUI\PanelLayout( [
			'classes' => [ 'mirror-wrapper' ],
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
