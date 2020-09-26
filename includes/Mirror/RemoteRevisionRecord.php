<?php

namespace WikiMirror\Mirror;

use Content;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\SlotRecord;
use MWException;
use WikiMirror\API\PageInfoResponse;

class RemoteRevisionRecord extends RevisionRecord {
	/** @var PageInfoResponse */
	private $remoteData;

	/**
	 * RemoteRevisionRecord constructor.
	 *
	 * @param PageInfoResponse $remoteData
	 * @throws MWException
	 */
	public function __construct( PageInfoResponse $remoteData ) {
		$this->remoteData = $remoteData;

		$title = $remoteData->getTitle();
		$slots = $this->getRemoteSlotRecords();
		// we lie and say this is from the local wiki; can change this in the future if needed
		// but then MW might expect that the db specified here is accessible, so I'm not sure
		// there's really any winning play short of writing a layer that mocks the Database API
		// and translates that to foreign API requests, which is a lot of work for probably not much benefit
		$dbDomain = false;
		parent::__construct( $title, new RevisionSlots( $slots ), $dbDomain );
	}

	/**
	 * @inheritDoc
	 */
	public function getSize() {
		return $this->remoteData->lastRevision->size;
	}

	/**
	 * @inheritDoc
	 */
	public function getSha1() {
		return $this->remoteData->lastRevision->sha1;
	}

	/**
	 * Populate lazy-loaded SlotRecords to read data from remote.
	 *
	 * @return SlotRecord[] Slot records
	 */
	public function getRemoteSlotRecords() {
		$slots = [];
		$revision = $this->remoteData->lastRevision;

		foreach ( $revision->slots as $slot ) {
			$row = (object)[
				'slot_revision_id' => $revision->revisionId,
				'slot_role_id' => 0,
				'slot_content_id' => 0,
				'slot_origin' => $revision->revisionId,
				'content_id' => 0,
				'content_size' => $revision->getSlotSize( $slot ),
				'content_sha1' => $revision->getSlotSha1( $slot ),
				'content_model' => 0,
				'content_address' => null,
				'model_name' => $revision->getSlotContentModel( $slot ),
				'role_id' => 0,
				'role_name' => $slot
			];

			$slots[] = new SlotRecord( $row, [ $this, 'getRemoteSlotContent' ] );
		}

		return $slots;
	}

	/**
	 * Retrieve content from remote for a given slot.
	 *
	 * @param SlotRecord $record Remote slot record
	 * @return Content Content of slot
	 * @throws MWException
	 */
	public function getRemoteSlotContent( SlotRecord $record ) {
		return new MirrorContent( $this->remoteData );
	}
}
