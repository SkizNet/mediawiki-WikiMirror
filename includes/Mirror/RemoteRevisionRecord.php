<?php

namespace WikiMirror\Mirror;

use Content;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\SlotRecord;
use MWException;
use Wikimedia\Rdbms\LoadBalancer;
use WikiMirror\API\PageInfoResponse;

class RemoteRevisionRecord extends RevisionRecord {
	/** @var PageInfoResponse */
	private $remoteData;
	/** @var LoadBalancer */
	private $loadBalancer;
	/** @var array */
	private $slotRoles = null;

	/**
	 * RemoteRevisionRecord constructor.
	 *
	 * @param PageInfoResponse $remoteData
	 * @throws MWException
	 */
	public function __construct( PageInfoResponse $remoteData, LoadBalancer $loadBalancer ) {
		$this->remoteData = $remoteData;
		$this->loadBalancer = $loadBalancer;

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

		if ( $this->slotRoles === null ) {
			$this->loadSlotRoles();
		}

		foreach ( $revision->slots as $slot ) {
			if ( !array_key_exists( $slot, $this->slotRoles ) ) {
				// unrecognized slot role, skip over it
				continue;
			}

			$row = (object)[
				'slot_revision_id' => $revision->revisionId,
				'slot_role_id' => $this->slotRoles[$slot],
				'slot_content_id' => null,
				'slot_origin' => $revision->revisionId,
				'content_size' => $revision->getSlotSize( $slot ),
				'content_sha1' => $revision->getSlotSha1( $slot ),
				'model_name' => $revision->getSlotContentModel( $slot ),
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
	 */
	public function getRemoteSlotContent( SlotRecord $record ) {
		// TODO: FINISH
		// We should probably create a new AbstractContent subclass and then lie about
		// the content model in the SlotRecord entry to point at our subclass.
		// Then our custom ContentHandler class can correctly handle everything.
		return null;
	}

	/**
	 * Loads data from the slot_roles table into a cache for future reference.
	 */
	private function loadSlotRoles() {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select( 'slot_roles', [ 'role_id', 'role_name' ], '', __METHOD__ );
		$this->slotRoles = [];

		foreach ( $res as $row ) {
			$this->slotRoles[$row->role_name] = $row->role_id;
		}
	}
}
