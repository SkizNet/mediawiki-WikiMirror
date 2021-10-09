<?php

namespace WikiMirror\API;

use ApiMain;
use ApiUsageException;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserOptionsLookup;
use Parser;
use Title;
use WikiMirror\Mirror\Mirror;

class ApiVisualEditor extends \ApiVisualEditor {
	/** @var Mirror */
	private $mirror;

	/**
	 * @inheritDoc
	 * @noinspection PhpMethodParametersCountMismatchInspection
	 */
	public function __construct(
		ApiMain $main,
		$name,
		UserNameUtils $userNameUtils,
		Parser $parser,
		LinkRenderer $linkRenderer,
		UserOptionsLookup $userOptionsLookup,
		Mirror $mirror,
		$watchlistManager = null,
		$contentTransformer = null
	) {
		$this->mirror = $mirror;
		// In 1.35, ApiVisualEditor constructor takes 2 args, however it's safe in PHP to pass
		// too many arguments to a thing, so suppress phan for 1.35 instead of doing a version_compare
		// Also in 1.37/38, it takes 8 args, where ContentTransformer is only available in 1.37/38
		// TODO: update above comment once we determine which version is accurate
		// @phan-suppress-next-line PhanParamTooMany
		parent::__construct(
			$main,
			$name,
			$userNameUtils,
			$parser,
			$linkRenderer,
			$userOptionsLookup,
			$watchlistManager,
			$contentTransformer
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
