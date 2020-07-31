<?php

namespace WikiMirror\Mirror;

use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Page\Hook\ArticleRevisionViewCustomHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Revision\RevisionRecord;
use MessageSpecifier;
use OutputPage;
use Title;
use User;
use WikiPage;

class Hooks implements
	TitleIsAlwaysKnownHook,
	GetUserPermissionsErrorsHook,
	WikiPageFactoryHook,
	ArticleRevisionViewCustomHook
{
	/** @var Mirror */
	private $mirror;

	/**
	 * Hooks constructor.
	 *
	 * @param Mirror $mirror
	 */
	public function __construct( Mirror $mirror ) {
		$this->mirror = $mirror;
	}

	/**
	 * Determine whether this title exists on the remote wiki
	 *
	 * @param Title $title Title being checked
	 * @param bool|null &$isKnown Set to null to use default logic or a bool to skip default logic
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		// if we can mirror the title and it isn't currently being forked, we force the title as known
		// TODO: check forked status
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
		// by default, only allow the read action against mirrored (non-forked) pages
		// users that can import are also allowed to create such pages.
		$allowedActions = [ 'read' ];

		if ( !$title->exists() && $this->mirror->canMirror( $title ) && !in_array( $action, $allowedActions ) ) {
			// user doesn't have the ability to perform this action with this page
			// TODO: give a useful error message (false just falls back to badacccess-group0)
			$result = false;
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
		if ( !$title->exists() && $this->mirror->canMirror( $title ) ) {
			$page = new WikiRemotePage( $title );
			return false;
		}

		return true;
	}

	/**
	 * Render a remote mirror page, bypassing the local parser
	 *
	 * @param RevisionRecord|null $rev RevisionRecord being rendered, or null if the rev is not found
	 * @param Title $title Title being rendered
	 * @param int|null $oldid If defined, render the given revision id instead of the latest rev
	 * 		This is not supported for remote pages, so if set we always try to render a local page
	 * @param OutputPage $out
	 * @return bool True to use default logic, false to abort hook processing and use only things
	 * 		we have explicitly written to $out
	 */
	public function onArticleRevisionViewCustom( $rev, $title, $oldid, $out ) {
		// sanity check: ensure that this is something we're even able to do a remote render for
		if ( $rev === null || $oldid !== null ) {
			return true;
		}

		// if the title was forked or we can't mirror it, don't do custom stuff
		if ( $title->exists() || !$this->mirror->canMirror( $title ) ) {
			return true;
		}

		// set page metadata like displaytitle based on what remote had
		// this is a safe operation, setPageTitle() and getDisplayTitle() both sanitize the passed-in value
		// (although setDisplayTitle() does not sanitize, it can only be read via getDisplayTitle())
		$remoteData = $this->mirror->getCached( $title );
		$out->setPageTitle( $remoteData->displayTitle );
		$out->setDisplayTitle( $remoteData->displayTitle );

		// grab the rendered version of this page from the remote (action=render)

		// sanitize the rendered HTML for display on our site:
		// 1. Replace remote wiki URLs with local ones
		// 2. Strip out any javascript that snuck its way into the page
		// TODO: see if there's a better way to sanitize the html to remove scripts (e.g. img onerror)

		return true;
	}
}
