<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\API;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiModuleManager;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryRevisions;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Api\Hook\ApiMakeParserOptionsHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use MediaWiki\Api\IApiMessage;
use MediaWiki\Message\Message;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\ScopedCallback;
use WikiMirror\Compat\ReflectionHelper;
use WikiMirror\Mirror\Mirror;

class Hooks implements
	ApiCheckCanExecuteHook,
	ApiMain__moduleManagerHook,
	ApiMakeParserOptionsHook,
	APIQueryAfterExecuteHook
{
	/** @var Mirror */
	private Mirror $mirror;

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
			// with an additional Mirror service tacked on the front
			$moduleManager->addModule( 'visualeditor', 'action', [
				'class' => 'WikiMirror\API\ApiVisualEditor',
				'services' => [
					"Mirror",
					"RevisionLookup",
					"TempUserCreator",
					"UserFactory",
					"UserOptionsLookup",
					"WatchlistManager",
					"ContentTransformer",
					"StatsdDataFactory",
					"WikiPageFactory",
					"IntroMessageBuilder",
					"PreloadedContentBuilder",
					"SpecialPageFactory",
					"VisualEditor.ParsoidClientFactory",
				],
			] );
		}

		$moduleManager->addModule( 'redirects', 'prop', [
			'class' => 'WikiMirror\API\ApiQueryRedirects',
			'services' => [
				"LinksMigration",
			]
		] );
	}

	/**
	 * Disable parser cache on API calls for mirrored pages
	 *
	 * @param ParserOptions $options
	 * @param Title $title Title to be parsed
	 * @param array $params Parameter array for the API module
	 * @param ApiBase $module API module (which is also a ContextSource)
	 * @param ScopedCallback|null &$reset Set to a ScopedCallback used to reset any hooks after
	 *  the parse is done
	 * @param bool &$suppressCache Set true if cache should be suppressed
	 * @return void
	 */
	public function onApiMakeParserOptions( $options, $title, $params, $module, &$reset, &$suppressCache ) {
		if ( !$this->mirror->isForked( $title ) ) {
			$suppressCache = true;
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
		if ( !is_array( $pages ) || $module->isInGeneratorMode() ) {
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
				if ( $title === null || !$this->mirror->canMirror( $title, true ) ) {
					continue;
				}

				// this is a mirrored page, grab revision information
				$status = $this->mirror->getCachedPage( $title );
				if ( !$status->isOK() ) {
					continue;
				}

				/** @var ?PageInfoResponse $pageInfo */
				$pageInfo = $status->getValue();
				$revInfo = $pageInfo?->lastRevision;
				if ( $revInfo === null ) {
					// page doesn't exist remotely
					continue;
				}

				$params = $module->extractRequestParams();

				$data = [
					'*' => [
						'mirrored' => true,
					],
					'ids' => [
						'revid' => $revInfo->revisionId,
						'parentid' => $revInfo->parentId,
					],
					'flags' => [
						'minor' => $revInfo->minor
					],
					'timestamp' => [
						'timestamp' => $revInfo->timestamp,
					],
					'user' => [
						'user' => $revInfo->user,
					],
					'userid' => [
						'userid' => $revInfo->remoteUserId,
					],
					'size' => [
						'size' => $revInfo->size
					],
					'sha1' => ( $revInfo->sha1 !== null )
						? [ 'sha1' => $revInfo->sha1 ]
						: [ 'sha1hidden' => true ],
					'comment' => ( $revInfo->comment !== null )
						? [ 'comment' => $revInfo->comment ]
						: [ 'commenthidden' => true ],
					'parsedcomment' => ( $revInfo->parsedComment !== null )
						? [ 'parsedcomment' => $revInfo->parsedComment ]
						: [ 'commenthidden' => true ],
					'tags' => [
						'tags' => $revInfo->tags
					],
					'roles' => [
						'roles' => $revInfo->roles
					]
				];

				$info = $data['*'];
				foreach ( $params['prop'] as $prop ) {
					if ( isset( $data[$prop] ) ) {
						$info += $data[$prop];
					}
				}

				if ( array_intersect( $params['prop'], [ 'slotsize', 'slotsha1', 'contentmodel', 'content' ] ) ) {
					$legacy = false;
					if ( !$params['slots'] ) {
						// legacy format
						$legacy = true;
						$params['slots'] = [ 'main' ];
						$encParam = $module->encodeParamName( 'slots' );
						$name = $module->getModuleName();
						$parent = $module->getParent();
						$parentParam = $parent->encodeParamName( $parent->getModuleManager()->getModuleGroup( $name ) );
						$module->addDeprecation(
							[ 'apiwarn-deprecation-missingparam', $encParam ],
							"action=query&{$parentParam}={$name}&!{$encParam}"
						);
					}

					foreach ( $params['slots'] as $slot ) {
						$slotdata = [
							'slotsize' => [ 'size' => $revInfo->getSlotSize( $slot ) ],
							'slotsha1' => ( $revInfo->getSlotSha1( $slot ) !== null )
								? [ 'sha1' => $revInfo->getSlotSha1( $slot ) ]
								: [ 'sha1hidden' => true ],
							'contentmodel' => [ 'contentmodel' => $revInfo->getSlotContentModel( $slot, true ) ],
							'content' => ( $revInfo->getSlotContentFormat( $slot ) !== null )
								? [
									'contentformat' => $revInfo->getSlotContentFormat( $slot ),
									'content' => $revInfo->getSlotContent( $slot )
								]
								: [
									'texthidden' => true
								]
						];

						$slotInfo = [];
						foreach ( $params['prop'] as $prop ) {
							if ( isset( $slotdata[$prop] ) ) {
								$slotInfo += $slotdata[$prop];
							}
						}

						$info['slots'][$slot] = $slotInfo;
					}

					if ( $legacy ) {
						$info += $info['slots']['main'];
						unset( $info['slots'] );
					}
				}

				// expose revision info, letting user know this is a mirrored page
				// omit revid/pageid values because we don't want to confuse the API consumer
				$result->addValue( [ 'query', 'pages', $i ], 'revisions', [ $info ] );
			}
		}
	}
}
