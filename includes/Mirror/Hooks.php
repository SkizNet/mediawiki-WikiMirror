<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Mirror;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Permissions\PermissionManager;
use MessageSpecifier;
use MWException;
use SkinTemplate;
use SpecialPage;
use Title;
use User;
use WikiPage;

class Hooks implements
	TitleIsAlwaysKnownHook,
	GetUserPermissionsErrorsHook,
	WikiPageFactoryHook,
	SkinTemplateNavigation__UniversalHook
{
	/** @var Mirror */
	private $mirror;
	/** @var PermissionManager */
	private $permManager;

	/**
	 * Hooks constructor.
	 *
	 * @param Mirror $mirror
	 * @param PermissionManager $permManager
	 */
	public function __construct( Mirror $mirror, PermissionManager $permManager ) {
		$this->mirror = $mirror;
		$this->permManager = $permManager;
	}

	/**
	 * Determine whether this title exists on the remote wiki
	 *
	 * @param Title $title Title being checked
	 * @param bool|null &$isKnown Set to null to use default logic or a bool to skip default logic
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		// if we can mirror the title, we force the title as known
		// canMirror returns false if the title already exists locally
		if ( $this->mirror->canMirror( $title ) ) {
			$isKnown = true;
		}
	}

	/**
	 * Ensure that users can't perform modification actions on mirrored pages until they're forked
	 *
	 * @param Title $title Title being checked
	 * @param User $user User being checked
	 * @param string $action Action to check
	 * @param array|string|MessageSpecifier|false &$result Result of check
	 * @return bool True to use default logic, false to abort hook processing and use our result
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		// read: lets people read the mirrored page
		// fork: lets people fork the page
		$allowedActions = [ 'read', 'fork' ];

		if ( $this->mirror->canMirror( $title ) && !in_array( $action, $allowedActions ) ) {
			// user doesn't have the ability to perform this action with this page
			$result = wfMessageFallback( 'wikimirror-no-' . $action, 'wikimirror-no-action' );
			return false;
		}

		return true;
	}

	/**
	 * Get a WikiPage object for mirrored pages that fetches data from the remote instead of the db
	 *
	 * @param Title $title Title to get WikiPage of
	 * @param WikiPage|null &$page Page to return, or null to use default logic
	 * @return bool True to use default logic, false to abort hook processing and use our page
	 */
	public function onWikiPageFactory( $title, &$page ) {
		if ( $this->mirror->canMirror( $title ) ) {
			$page = new WikiRemotePage( $title );
			return false;
		}

		return true;
	}

	/**
	 * This hook is called on both content and special pages
	 * after variants have been added.
	 *
	 * @param SkinTemplate $template
	 * @param array &$links Structured navigation links. This is used to alter the navigation for
	 *   skins which use buildNavigationUrls such as Vector.
	 * @return void This hook must not abort, it must return no value
	 * @throws MWException
	 */
	public function onSkinTemplateNavigation__Universal( $template, &$links ) : void {
		$title = $template->getRelevantTitle();
		$user = $template->getUser();
		$skinName = $template->getSkinName();

		if ( $this->mirror->canMirror( $title ) ) {
			if ( $this->permManager->quickUserCan( 'fork', $user, $title ) ) {
				$forkTitle = SpecialPage::getTitleFor( 'Fork', $title->getPrefixedDBkey() );
				$links['actions']['fork'] = [
					'class' => $template->getTitle()->isSpecial( 'Fork' ) ? 'selected' : false,
					'href' => $forkTitle->getLocalURL(),
					'text' => wfMessageFallback( "$skinName-action-fork", 'wikimirror-action-fork' )
						->setContext( $template->getContext() )->text()
				];
			}
		}
	}
}
