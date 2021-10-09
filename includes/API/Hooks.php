<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\API;

use ApiBase;
use ApiModuleManager;
use ApiQuery;
use ApiQueryBacklinksprop;
use ApiQueryRevisions;
use ExtensionRegistry;
use IApiMessage;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use Message;
use MWException;
use Title;
use User;
use WikiMirror\Compat\ReflectionHelper;
use WikiMirror\Mirror\Mirror;

class Hooks implements
	ApiCheckCanExecuteHook,
	ApiMain__moduleManagerHook,
	APIQueryAfterExecuteHook
{
	/** @var Mirror */
	private $mirror;

	/**
	 * Constructor for API hooks.
	 *
	 * @param Mirror $mirror
	 */
	public function __construct( Mirror $mirror ) {
		$this->mirror = $mirror;
	}

	/**
	 * Replace the ApiPageSet with our custom implementation in the ApiQuery module.
	 * This has nothing to do with permissions, but this is the only hook that is
	 * reliably executed after the module is constructed but before execute is called on it.
	 *
	 * @param ApiBase $module
	 * @param User $user Current user
	 * @param IApiMessage|Message|string|array &$message API message to die with:
	 *  ApiMessage, Message, string message key, or key+parameters array to
	 *  pass to ApiBase::dieWithError().
	 * @return void To continue hook execution
	 * @throws MWException On error
	 */
	public function onApiCheckCanExecute( $module, $user, &$message ) {
		if ( $module instanceof ApiQuery ) {
			$pageSet = new ApiPageSet( $module, $this->mirror );
			ReflectionHelper::setPrivateProperty( ApiQuery::class, 'mPageSet', $module, $pageSet );
		}
	}

	/**
	 * Override API action=visualeditor to accommodate mirrored pages
	 *
	 * @param ApiModuleManager $moduleManager
	 * @return void
	 */
	public function onApiMain__moduleManager( $moduleManager ) {
		$extensionRegistry = ExtensionRegistry::getInstance();
		if ( $extensionRegistry->isLoaded( 'VisualEditor' )
			&& $moduleManager->isDefined( 'visualeditor', 'action' )
		) {
			// ObjectFactory spec here should be kept in sync with VE extension.json
			// with an additional Mirror service tacked on the end
			$moduleManager->addModule( 'visualeditor', 'action', [
				'class' => 'WikiMirror\API\ApiVisualEditor',
				'services' => [
					'UserNameUtils',
					'Parser',
					'LinkRenderer',
					'UserOptionsLookup',
					'Mirror'
				],
				'optional_services' => [
					'WatchlistManager',
					'ContentTransformer'
				]
			] );
		}
	}

	/**
	 * Populate some information for mirrored pages in action=query to allow for
	 * ZIM creation. This is *not* meant to be for general-purpose API use, although
	 * more things could be supported in the future if necessary.
	 *
	 * @param ApiBase $module
	 * @return void
	 */
	public function onAPIQueryAfterExecute( $module ) {
		if ( $module instanceof ApiQueryRevisions ) {
			$this->addMirroredRevisionInfo( $module );
		} elseif ( $module instanceof ApiQueryBacklinksprop && $module->getModuleName() === 'redirects' ) {
			$this->addMirroredRedirectInfo( $module );
		}
	}

	/**
	 * Add information about mirrored revisions to action=query&prop=revisions.
	 *
	 * @param ApiQueryRevisions $module
	 */
	private function addMirroredRevisionInfo( ApiQueryRevisions $module ) {
		$result = $module->getResult();
		$pages = $result->getResultData( [ 'query', 'pages' ] );
		if ( $pages === null || !is_array( $pages ) ) {
			return;
		}

		foreach ( $pages as $i => $page ) {
			if ( isset( $page['missing'] ) && isset( $page['known'] ) && $page['missing'] && $page['known'] ) {
				// page both missing and known means it could be a mirrored page
				// title is already prefixed, so strip the prefix if we have a namespace
				$pageTitle = $page['title'];
				if ( $page['ns'] !== 0 ) {
					$pageTitle = substr( $pageTitle, strpos( $pageTitle, ':' ) + 1 );
				}

				$title = Title::makeTitleSafe( $page['ns'], $pageTitle );
				if ( $title === null || !$this->mirror->canMirror( $title ) ) {
					continue;
				}

				// this is a mirrored page, grab revision information
				$status = $this->mirror->getCachedPage( $title );
				if ( !$status->isOK() ) {
					continue;
				}

				/** @var PageInfoResponse $pageInfo */
				$pageInfo = $status->getValue();
				$revInfo = $pageInfo->lastRevision;
				if ( $revInfo === null ) {
					// page doesn't exist remotely
					continue;
				}

				// expose revision info, letting user know this is a mirrored page
				// omit revid/pageid values because we don't want to confuse the API consumer
				$result->addValue( [ 'query', 'pages', $i ], 'revisions', [
					[
						'mirrored' => true,
						'revid' => 0,
						'parentid' => 0,
						'user' => $revInfo->user,
						'timestamp' => $revInfo->timestamp,
						'comment' => $revInfo->comment
					]
				] );
			}
		}
	}

	/**
	 * Add information about mirrored redirects to action=query&prop=redirects.
	 *
	 * @param ApiQueryBacklinksprop $module
	 */
	private function addMirroredRedirectInfo( ApiQueryBacklinksprop $module ) {
		$result = $module->getResult();
		$pages = $result->getResultData( [ 'query', 'pages' ] );
		if ( $pages === null || !is_array( $pages ) ) {
			return;
		}

		// Check for mirrored redirects to these pages and add them in.
		// We somehow need to honor query limits/continuations as well.
		// Mirrored redirects are listed at the end (after all regular redirects are enumerated).
		// This information is not currently cached since it applies to every page,
		// not just mirrored pages, so we can't use the existing mirror cache.
		foreach ( $pages as $i => $page ) {
			$pageNs = $page['ns'];
			$pageTitle = substr( $page['title'], strpos( $page['title'], ':' ) + 1 );
			$title = Title::makeTitleSafe( $pageNs, $pageTitle );
			$redirects = $this->mirror->getRedirects( $title );
		}
	}
}
