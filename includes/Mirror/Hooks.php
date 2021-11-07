<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Mirror;

use HtmlArmor;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererEndHook;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Permissions\PermissionManager;
use MessageSpecifier;
use MWException;
use RequestContext;
use SkinTemplate;
use SpecialPage;
use Title;
use User;
use WikiPage;

class Hooks implements
	GetUserPermissionsErrorsHook,
	GetUserPermissionsErrorsExpensiveHook,
	HtmlPageLinkRendererEndHook,
	SkinTemplateNavigation__UniversalHook,
	TitleIsAlwaysKnownHook,
	WikiPageFactoryHook
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
		if ( $isKnown !== null ) {
			// some other extension already set this
			return;
		}

		if ( $this->mirror->canMirror( $title, true ) ) {
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

		if ( !in_array( $action, $allowedActions ) && $this->mirror->canMirror( $title, true ) ) {
			// user doesn't have the ability to perform this action with this page
			$result = wfMessageFallback( 'wikimirror-no-' . $action, 'wikimirror-no-action' );
			return false;
		}

		return true;
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
	public function onGetUserPermissionsErrorsExpensive( $title, $user, $action, &$result ) {
		// read: lets people read the mirrored page
		// fork: lets people fork the page
		$allowedActions = [ 'read', 'fork' ];

		if ( !in_array( $action, $allowedActions ) && $this->mirror->canMirror( $title, false ) ) {
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
		// Don't display mirrored pages to crawlers since they aren't supposed to index/follow these anyway
		// This doesn't detect every bot in existence, but it does get most of the prominent ones
		$userAgent = RequestContext::getMain()->getRequest()->getHeader( 'User-Agent' );
		$isBot = $userAgent !== false
			&& strpos( $userAgent, '+http' ) !== false
			&& strpos( $userAgent, 'bot' ) !== false;

		if ( !$isBot && $this->mirror->canMirror( $title ) ) {
			$page = new WikiRemotePage( $title );
			return false;
		}

		return true;
	}

	/**
	 * Add a fork tab to relevant pages
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Structured navigation links. This is used to alter the navigation for
	 *   skins which use buildNavigationUrls such as Vector.
	 * @return void This hook must not abort, it must return no value
	 * @throws MWException
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$title = $sktemplate->getRelevantTitle();
		$user = $sktemplate->getUser();
		$skinName = $sktemplate->getSkinName();

		if ( $this->mirror->canMirror( $title ) ) {
			if ( $this->permManager->quickUserCan( 'fork', $user, $title ) ) {
				$forkTitle = SpecialPage::getTitleFor( 'Fork', $title->getPrefixedDBkey() );
				$links['views']['fork'] = [
					'class' => $sktemplate->getTitle()->isSpecial( 'Fork' ) ? 'selected' : false,
					'href' => $forkTitle->getLocalURL(),
					'text' => wfMessageFallback( "$skinName-action-fork", 'wikimirror-action-fork' )
						->setContext( $sktemplate->getContext() )->text()
				];

				// if the user can watch the page, ensure Fork is before the watchlist star
				if ( array_key_exists( 'watch', $links['views'] ) ) {
					$watch = $links['views']['watch'];
					unset( $links['views']['watch'] );
					$links['views']['watch'] = $watch;
				}
			}
		}
	}

	/**
	 * Mark links to mirrored pages as rel=nofollow and give a custom class name for CSS styling
	 *
	 * @param LinkRenderer $linkRenderer
	 * @param LinkTarget $target LinkTarget object that the link is pointing to
	 * @param bool $isKnown Whether the page is known or not
	 * @param string|HtmlArmor &$text Contents that the `<a>` tag should have; either a plain,
	 *   unescaped string or an HtmlArmor object
	 * @param string[] &$attribs Final HTML attributes of the `<a>` tag, after processing, in
	 *   associative array form
	 * @param string &$ret Value to return if the hook returns false
	 * @return bool True to return an `<a>` element with HTML attributes $attribs and contents $html,
	 * 	 false to return $ret.
	 */
	public function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ) {
		if ( $isKnown && !$target->isExternal() ) {
			$title = Title::newFromLinkTarget( $target );
			if ( $this->mirror->canMirror( $title, true ) ) {
				if ( array_key_exists( 'class', $attribs ) ) {
					$classes = $attribs['class'] . ' ';
				} else {
					$classes = '';
				}

				$attribs['rel'] = 'nofollow';
				$attribs['class'] = $classes . 'mirror-link';
			}
		}

		return true;
	}
}
