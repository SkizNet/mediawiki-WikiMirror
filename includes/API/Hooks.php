<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\API;

use ApiBase;
use ApiModuleManager;
use ApiQuery;
use ApiQueryBacklinksprop;
use ApiQueryRevisions;
use ApiUsageException;
use ExtensionRegistry;
use IApiMessage;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Api\Hook\APIGetAllowedParamsHook;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use Message;
use MWException;
use Title;
use User;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiMirror\Compat\ReflectionHelper;
use WikiMirror\Mirror\Mirror;

class Hooks implements
	ApiCheckCanExecuteHook,
	APIGetAllowedParamsHook,
	ApiMain__moduleManagerHook,
	APIQueryAfterExecuteHook
{
	/** @var ILoadBalancer */
	private ILoadBalancer $loadBalancer;

	/** @var Mirror */
	private Mirror $mirror;

	/**
	 * Constructor for API hooks.
	 *
	 * @param ILoadBalancer $loadBalancer
	 * @param Mirror $mirror
	 */
	public function __construct( ILoadBalancer $loadBalancer, Mirror $mirror ) {
		$this->loadBalancer = $loadBalancer;
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
	 * @throws ApiUsageException
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
		if ( !is_array( $pages ) ) {
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
	 * @throws ApiUsageException On an invalid mirrorcontinue param
	 */
	private function addMirroredRedirectInfo( ApiQueryBacklinksprop $module ) {
		$params = $module->extractRequestParams();

		if ( !$params['mirror'] || $module->isInGeneratorMode() ) {
			// not including mirrored redirects or being used as a generator
			return;
		}

		$result = $module->getResult();
		$pages = $result->getResultData(
			[ 'query', 'pages' ],
			[
				// don't apply backwards-compat transformations to results
				'BC' => [ 'nobool', 'no*', 'nosub' ],
				// recursively strip all metadata
				'Strip' => 'all'
			]
		);

		if ( !is_array( $pages ) ) {
			return;
		}

		// target pages to include in our query, mapping of ns => [ name1, name2, ... ]
		$include = [];

		// clauses for the query
		$where = [];
		$orderby = [];

		// determine how many pieces mirrorcontinue should have (this matches rdcontinue's logic)
		$pageSet = $module->getQuery()->getPageSet();
		$pageTitles = $pageSet->getGoodAndMissingPages();
		$pageMap = $pageSet->getGoodAndMissingTitlesByNamespace();
		foreach ( $pageSet->getSpecialPages() as $id => $title ) {
			$pageMap[$title->getNamespace()][$title->getDBkey()] = $id;
			$pageTitles[] = $title;
		}

		if ( count( $pageMap ) > 1 ) {
			$orderby[] = 'rr_namespace';
			$orderby[] = 'rr_title';
		} elseif ( count( $pageTitles ) > 1 ) {
			$orderby[] = 'rr_title';
		}

		$orderby[] = 'rr_from';
		unset( $pageSet, $pageTitles, $pageMap );

		// continuation data, if specified, must be in the form target_ns|target_page|mirror_rd_id
		// process that here, or leave null if no continuation data was specified
		$continue = null;
		if ( isset( $params['mirrorcontinue'] ) ) {
			$continue = explode( '|', $params['mirrorcontinue'] );
			if ( count( $continue ) !== count( $orderby ) ) {
				$module->dieWithError( 'apierror-badcontinue' );
			}

			foreach ( $orderby as $i => $key ) {
				$continue[$key] = $continue[$i];
				if ( $key === 'rr_namespace' || $key === 'rr_from' ) {
					$continue[$key] = filter_var( $continue[$key], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
					if ( $continue[$key] === null ) {
						$module->dieWithError( 'apierror-badcontinue' );
					}
				}
			}
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$saved = [];
		$map = [];

		// Check for mirrored redirects to these pages and add them in.
		// Mirrored redirects are listed first (before all regular redirects are enumerated).
		foreach ( $pages as $i => $page ) {
			$pageNs = $page['ns'];
			$offset = strpos( $page['title'], ':' );
			if ( $offset === false ) {
				$offset = -1;
			}

			$pageTitle = substr( $page['title'], $offset + 1 );

			$title = Title::makeTitleSafe( $pageNs, $pageTitle );
			if ( $title === null ) {
				// invalid title somehow?
				wfDebugLog( 'WikiMirror', "Invalid Title ns=$pageNs title=$pageTitle" );
				continue;
			}

			// retrieve normalized data
			$pageNs = $title->getNamespace();
			$pageTitle = $title->getDBkey();

			$map["{$pageNs}#{$pageTitle}"] = $i;

			// save off and remove original redirect results
			if ( array_key_exists( 'redirects', $page ) ) {
				foreach ( $page['redirects'] as $redirect ) {
					$saved[] = [
						0 => $pageNs,
						1 => $pageTitle,
						2 => $redirect['pageid'],
						'rr_namespace' => $pageNs,
						'rr_title' => $pageTitle,
						'rr_from' => $redirect['pageid'],
						'data' => $redirect
					];
				}

				$result->removeValue( [ 'query', 'pages', $i, 'redirects' ], null );
			}

			// if we're doing a query continuation, check if this title should be excluded
			if ( $continue !== null ) {
				if (
					$pageNs < $continue['rr_namespace']
					|| ( $pageNs === $continue['rr_namespace'] && ( $pageTitle <=> $continue['rr_title'] ) < 0 )
				) {
					continue;
				} elseif ( $pageNs === $continue['rr_namespace'] && $pageTitle === $continue['rr_title'] ) {
					$redirectId = $continue['rr_from'];
					$where[] = $dbr->makeList( [
						'rr_namespace' => $pageNs,
						'rr_title' => $pageTitle,
						"rr_from >= $redirectId"
					], IDatabase::LIST_AND );
					continue;
				}
			}

			$include[$pageNs][] = $pageTitle;
		}

		foreach ( $include as $ns => $pages ) {
			$where[] = $dbr->makeList( [
				'rr_namespace' => $ns,
				'rr_title' => $pages
			], IDatabase::LIST_AND );
		}

		$res = $dbr->select(
			[ 'remote_redirect', 'remote_page' ],
			[ 'rr_from', 'rr_namespace', 'rr_title', 'rp_namespace', 'rp_title' ],
			$dbr->makeList( $where, IDatabase::LIST_OR ),
			__METHOD__,
			[
				'LIMIT' => $params['limit'] + 1,
				'ORDER BY' => $orderby
			],
			[ 'remote_page' => [ 'INNER JOIN', 'rr_from = rp_id' ] ]
		);

		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// hit our limit, add a continue
				$mc = [];
				foreach ( $orderby as $field ) {
					$mc[] = $row->$field;
				}

				$module->getContinuationManager()->addContinueParam( $module, 'mirrorcontinue', $mc );
				break;
			}

			$path = [ 'query', 'pages', $map["{$row->rr_namespace}#{$row->rr_title}"], 'redirects' ];
			$result->addValue( $path, null, [
				'ns' => (int)$row->rp_namespace,
				'title' => $row->rp_title,
				'missing' => true,
				'known' => true
			] );
		}

		usort( $saved, static function ( $left, $right ) {
			for ( $i = 0; $i < 3; ++$i ) {
				$c = $left[$i] <=> $right[$i];
				if ( $c !== 0 ) {
					return $c;
				}
			}

			return 0;
		} );

		while ( $count < $params['limit'] ) {
			$next = array_shift( $saved );
			if ( $next === null ) {
				break;
			}

			++$count;
			$path = [ 'query', 'pages', $map["{$next[0]}#{$next[1]}"], 'redirects' ];
			$result->addValue( $path, null, $next['data'] );
		}

		$next = array_shift( $saved );
		if ( $next !== null ) {
			// update rdcontinue to begin with this next item
			$rc = [];
			foreach ( $orderby as $key ) {
				$rc[] = $next[$key];
			}

			$module->getContinuationManager()->addContinueParam( $module, 'continue', $rc );
		}
	}

	/**
	 * Add custom continuation parameters for action=query&prop=redirects
	 *
	 * @param ApiBase $module
	 * @param array &$params Array of parameters
	 * @param int $flags Zero or OR-ed flags like ApiBase::GET_VALUES_FOR_HELP
	 */
	public function onAPIGetAllowedParams( $module, &$params, $flags ) {
		if ( $module instanceof ApiQueryBacklinksprop && $module->getModuleName() === 'redirects' ) {
			$params += [
				'mirror' => [
					ParamValidator::PARAM_TYPE => 'boolean'
				],
				'mirrorcontinue' => [
					ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
				]
			];
		}
	}
}
