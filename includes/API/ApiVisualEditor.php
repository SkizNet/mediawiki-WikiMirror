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
