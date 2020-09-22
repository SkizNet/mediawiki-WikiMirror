<?php

namespace WikiMirror\Mirror;

use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MessageSpecifier;
use Title;
use User;
use WikiPage;

class Hooks implements
	TitleIsAlwaysKnownHook,
	GetUserPermissionsErrorsHook,
	WikiPageFactoryHook
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
		// by default, only allow the read action against mirrored (non-forked) pages
		// users that can import are also allowed to create such pages.
		$allowedActions = [ 'read' ];

		if ( $this->mirror->canMirror( $title ) && !in_array( $action, $allowedActions ) ) {
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
		if ( $this->mirror->canMirror( $title ) ) {
			$page = new WikiRemotePage( $title );
			return false;
		}

		return true;
	}
}
