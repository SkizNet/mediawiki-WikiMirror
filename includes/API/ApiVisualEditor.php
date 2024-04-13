<?php

namespace WikiMirror\API;

use ApiMain;
use ApiUsageException;
use IBufferingStatsdDataFactory;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\EditPage\IntroMessageBuilder;
use MediaWiki\EditPage\PreloadedContentBuilder;
use MediaWiki\Extension\VisualEditor\VisualEditorParsoidClientFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\TempUser\TempUserCreator;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsLookup;
use MediaWiki\Watchlist\WatchlistManager;
use Title;
use WikiMirror\Mirror\Mirror;

class ApiVisualEditor extends \MediaWiki\Extension\VisualEditor\ApiVisualEditor {
	/** @var Mirror */
	private $mirror;

	/**
	 * @param ApiMain $main
	 * @param string $name
	 * @param Mirror $mirror
	 * @param RevisionLookup $revisionLookup
	 * @param TempUserCreator $tempUserCreator
	 * @param UserFactory $userFactory
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param WatchlistManager $watchlistManager
	 * @param ContentTransformer $contentTransformer
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param WikiPageFactory $wikiPageFactory
	 * @param IntroMessageBuilder $introMessageBuilder
	 * @param PreloadedContentBuilder $preloadedContentBuilder
	 * @param SpecialPageFactory $specialPageFactory
	 * @param VisualEditorParsoidClientFactory $parsoidClientFactory
	 */
	public function __construct(
		ApiMain $main,
		string $name,
		Mirror $mirror,
		RevisionLookup $revisionLookup,
		TempUserCreator $tempUserCreator,
		UserFactory $userFactory,
		UserOptionsLookup $userOptionsLookup,
		WatchlistManager $watchlistManager,
		ContentTransformer $contentTransformer,
		IBufferingStatsdDataFactory $statsdDataFactory,
		WikiPageFactory $wikiPageFactory,
		IntroMessageBuilder $introMessageBuilder,
		PreloadedContentBuilder $preloadedContentBuilder,
		SpecialPageFactory $specialPageFactory,
		VisualEditorParsoidClientFactory $parsoidClientFactory
	) {
		$this->mirror = $mirror;
		parent::__construct(
			$main,
			$name,
			$revisionLookup,
			$tempUserCreator,
			$userFactory,
			$userOptionsLookup,
			$watchlistManager,
			$contentTransformer,
			$statsdDataFactory,
			$wikiPageFactory,
			$introMessageBuilder,
			$preloadedContentBuilder,
			$specialPageFactory,
			$parsoidClientFactory
		);
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
