<?php

namespace WikiMirror\API;

use ApiMain;
use ApiUsageException;
use MediaWiki\User\UserNameUtils;
use Title;
use WikiMirror\Mirror\Mirror;

class ApiVisualEditor extends \ApiVisualEditor {
	/** @var Mirror */
	private $mirror;

	/**
	 * @inheritDoc
	 */
	public function __construct( ApiMain $main, $name, UserNameUtils $userNameUtils, Mirror $mirror ) {
		$this->mirror = $mirror;
		// In 1.35, ApiVisualEditor constructor takes 2 args, however it's safe in PHP to pass
		// too many arguments to a thing, so suppress phan for 1.35 instead of doing a version_compare
		// @phan-suppress-next-line PhanParamTooMany
		parent::__construct( $main, $name, $userNameUtils );
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
