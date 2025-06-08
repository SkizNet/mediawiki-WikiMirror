<?php

namespace WikiMirror\Mirror;

use MediaWiki\MediaWikiServices;
use MWException;
use WikiMirror\API\PageInfoResponse;

class HistoryAction extends \HistoryAction {
	/**
	 * @inheritDoc
	 * @throws MWException
	 */
	public function onView() {
		$out = $this->getOutput();

		/** @var Mirror $mirror */
		$mirror = MediaWikiServices::getInstance()->get( 'Mirror' );
		$status = $mirror->getCachedPage( $this->getTitle() );
		if ( !$status->isOK() ) {
			throw new MWException( $status->getMessage() );
		}

		/** @var ?PageInfoResponse $pageInfo */
		$pageInfo = $status->getValue();
		if ( $pageInfo === null ) {
			// page doesn't exist remotely, nothing to do here
			return;
		}

		$url = wfAppendQuery( $pageInfo->getUrl(), [ 'action' => 'history' ] );

		$out->wrapWikiMsg( '<div class="mw-parser-output">$1</div>',
			[ 'wikimirror-mirror-history', $url ]
		);
	}
}
