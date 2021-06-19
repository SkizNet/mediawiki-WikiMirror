<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\API;

use ApiBase;
use ApiModuleManager;
use ApiQueryRevisions;
use ExtensionRegistry;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use Title;
use WikiMirror\Mirror\Mirror;

class Hooks implements
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
	 * Override API action=visualeditor to accomodate mirrored pages
	 *
	 * @param ApiModuleManager $moduleManager
	 * @return void
	 */
	public function onApiMain__moduleManager( $moduleManager ) {
		// If VE isn't loaded, bail out early
		$extensionRegistry = ExtensionRegistry::getInstance();
		if ( !$extensionRegistry->isLoaded( 'VisualEditor' )
			|| !$moduleManager->isDefined( 'visualeditor', 'action' )
		) {
			return;
		}

		// ObjectFactory spec here should be kept in sync with VE extension.json
		// with an additional Mirror service tacked on the end
		$moduleManager->addModule( 'visualeditor', 'action', [
			'class' => 'WikiMirror\API\ApiVisualEditor',
			'services' => [
				'UserNameUtils',
				'Mirror'
			]
		] );
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
		if ( !( $module instanceof ApiQueryRevisions ) ) {
			return;
		}

		$result = $module->getResult();
		$pages = $result->getResultData( [ 'query', 'pages' ] );
		if ( $pages === null || !is_array( $pages ) ) {
			return;
		}

		foreach ( $pages as $i => $page ) {
			if ( isset( $page['missing'] ) && isset( $page['known'] ) && $page['missing'] && $page['known'] ) {
				// page both missing and known means it could be a mirrored page
				$title = Title::makeTitleSafe( $page['ns'], $page['title'] );
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
}
