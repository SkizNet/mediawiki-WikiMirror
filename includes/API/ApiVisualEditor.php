<?php

namespace WikiMirror\API;

use ApiMain;
use ApiUsageException;
use IBufferingStatsdDataFactory;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserOptionsLookup;
use MediaWiki\Watchlist\WatchlistManager;
use Parser;
use ReadOnlyMode;
use Title;
use WikiMirror\Mirror\Mirror;

class ApiVisualEditor extends \MediaWiki\Extension\VisualEditor\ApiVisualEditor {
	/** @var Mirror */
	private $mirror;

	/**
	 * @param ApiMain $main
	 * @param $name
	 * @param Mirror $mirror
	 * @param RevisionLookup $revisionLookup
	 * @param UserNameUtils $userNameUtils
	 * @param Parser $parser
	 * @param LinkRenderer $linkRenderer
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param WatchlistManager $watchlistManager
	 * @param ContentTransformer $contentTransformer
	 * @param SpecialPageFactory $specialPageFactory
	 * @param ReadOnlyMode $readOnlyMode
	 * @param RestrictionStore $restrictionStore
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param WikiPageFactory $wikiPageFactory
	 * @param HookContainer $hookContainer
	 * @param UserFactory $userFactory
	 * @param \MediaWiki\Extension\VisualEditor\VisualEditorParsoidClientFactory $visualEditorParsoidClientFactory 1.40+
	 */
	public function __construct(
		ApiMain $main,
		$name,
		Mirror $mirror,
		RevisionLookup $revisionLookup,
		UserNameUtils $userNameUtils,
		Parser $parser,
		LinkRenderer $linkRenderer,
		UserOptionsLookup $userOptionsLookup,
		WatchlistManager $watchlistManager,
		ContentTransformer $contentTransformer,
		SpecialPageFactory $specialPageFactory,
		ReadOnlyMode $readOnlyMode,
		RestrictionStore $restrictionStore,
		IBufferingStatsdDataFactory $statsdDataFactory,
		WikiPageFactory $wikiPageFactory,
		HookContainer $hookContainer,
		UserFactory $userFactory,
		$visualEditorParsoidClientFactory = null
	) {
		$this->mirror = $mirror;
		// Signatures changed in non-compatible ways between 1.39 and 1.40
		if ( version_compare( MW_VERSION, '1.40', '<' ) ) {
			parent::__construct(
				$main,
				$name,
				$userNameUtils,
				$parser,
				$linkRenderer,
				$userOptionsLookup,
				$watchlistManager,
				$contentTransformer,
				$specialPageFactory,
				$readOnlyMode,
				$restrictionStore,
				$wikiPageFactory,
				$hookContainer,
				$userFactory
			);
		} else {
			parent::__construct(
				$main,
				$name,
				$revisionLookup,
				$userNameUtils,
				$parser,
				$linkRenderer,
				$userOptionsLookup,
				$watchlistManager,
				$contentTransformer,
				$specialPageFactory,
				$readOnlyMode,
				$restrictionStore,
				$statsdDataFactory,
				$wikiPageFactory,
				$hookContainer,
				$userFactory,
				$visualEditorParsoidClientFactory
			);
		}
	}

	/**
	 * @inheritDoc
	 * @throws ApiUsageException
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$title = Title::newFromText( $params['page'] );
		if ( $title && $this->mirror->canMirror( $title ) ) {
			// mirrored page, hit remote API and pass through response
			$result = $this->mirror->getVisualEditorApi( $params );
			if ( $result === false ) {
				// have an error of some sort
				$this->dieWithError( 'apierror-visualeditor-docserver', 'wikimirror-visualeditor-docserver' );
			} else {
				$this->getResult()->addValue( null, $this->getModuleName(), $result );
			}
		} else {
			// not a mirrored page, run normal VE API
			parent::execute();
		}
	}
}
