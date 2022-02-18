<?php

namespace WikiMirror\API;

use InvalidArgumentException;

/**
 * Wrapper around MediaWiki's API action=query&prop=revisions response
 */
class RevisionInfoResponse {
	/** @var int */
	public $revisionId;
	/** @var int */
	public $parentId;
	/** @var ?string Username on remote wiki (not prefixed) */
	public $user;
	/** @var ?int User id on remote wiki */
	public $remoteUserId;
	/** @var string ISO timestamp */
	public $timestamp;
	/** @var int Size of all slots */
	public $size;
	/** @var ?string Lowercase hex SHA-1 hash of all slots */
	public $sha1;
	/** @var ?string */
	public $comment;
	/** @var ?string */
	public $parsedComment;
	/** @var int Size of main slot */
	public $mainSize;
	/** @var ?string Lowercase hex SHA-1 hash of main slot */
	public $mainSha1;
	/** @var string Content model of main slot */
	public $mainContentModel;
	/** @var string[] List of slot names, use accessor methods to get slot details */
	public $slots;
	/** @var string[] */
	public $roles;
	/** @var string[] */
	public $tags;
	/** @var string */
	public $content;
	/** @var bool */
	public $minor;

	/** @var array */
	private $slotInfo;

	/**
	 * RevisionInfoResponse constructor.
	 *
	 * @param array $response Associative array of API response
	 */
	public function __construct( array $response ) {
		$this->revisionId = $response['revid'];
		$this->parentId = $response['parentid'];

		if ( !isset( $response['userhidden'] ) ) {
			$this->user = $response['user'];
			$this->remoteUserId = $response['userid'];
		} else {
			$this->user = null;
			$this->remoteUserId = null;
		}

		$this->timestamp = $response['timestamp'];
		$this->size = $response['size'];

		if ( !isset( $response['sha1hidden'] ) ) {
			$this->sha1 = $response['sha1'];
		} else {
			$this->sha1 = null;
		}

		if ( !isset( $response['commenthidden'] ) ) {
			$this->comment = $response['comment'];
			$this->parsedComment = $response['parsedcomment'];
		} else {
			$this->comment = null;
			$this->parsedComment = null;
		}

		$this->roles = $response['roles'];
		$this->tags = $response['tags'];
		$this->minor = $response['minor'];

		/** @var string[] $slots */
		$slots = array_keys( $response['slots'] );
		$this->slots = $slots;
		$this->slotInfo = $response['slots'];
		$this->mainSize = $response['slots']['main']['size'];
		$this->mainContentModel = $response['slots']['main']['contentmodel'];

		if ( !isset( $response['slots']['main']['sha1hidden'] ) ) {
			$this->mainSha1 = $response['slots']['main']['sha1'];
		} else {
			$this->mainSha1 = null;
		}
	}

	/**
	 * Retrieves the size for the specified slot
	 *
	 * @param string $slot Slot name
	 * @return int
	 */
	public function getSlotSize( $slot ) {
		if ( !array_key_exists( $slot, $this->slotInfo ) ) {
			throw new InvalidArgumentException( 'The specified slot does not exist: ' . $slot );
		}

		return $this->slotInfo[$slot]['size'];
	}

	/**
	 * Retrieves the sha-1 hash for the specified slot
	 *
	 * @param string $slot Slot name
	 * @return ?string Lowercase hex SHA-1 hash, or null if the slot's content is revision deleted
	 */
	public function getSlotSha1( $slot ) {
		if ( !array_key_exists( $slot, $this->slotInfo ) ) {
			throw new InvalidArgumentException( 'The specified slot does not exist: ' . $slot );
		}

		if ( isset( $this->slotInfo[$slot]['sha1hidden'] ) ) {
			return null;
		}

		return $this->slotInfo[$slot]['sha1'];
	}

	/**
	 * Retrieves the content model for the specified slot
	 *
	 * @param string $slot Slot name
	 * @param bool $fetchRealModel If true, fetches real content model from slotInfo.
	 * 		If false, return value is a mirrored content model.
	 * @return string Content model
	 */
	public function getSlotContentModel( $slot, $fetchRealModel = false ) {
		if ( !array_key_exists( $slot, $this->slotInfo ) ) {
			throw new InvalidArgumentException( 'The specified slot does not exist: ' . $slot );
		}

		if ( $fetchRealModel ) {
			return $this->slotInfo[$slot]['contentmodel'];
		}

		return CONTENT_MODEL_MIRROR;
	}

	/**
	 * Retrieves the content format for the specified slot
	 *
	 * @param string $slot Slot name
	 * @return ?string Content format, or null if the slot's content is revision deleted
	 */
	public function getSlotContentFormat( $slot ) {
		if ( !array_key_exists( $slot, $this->slotInfo ) ) {
			throw new InvalidArgumentException( 'The specified slot does not exist: ' . $slot );
		}

		if ( isset( $this->slotInfo[$slot]['texthidden'] ) ) {
			return null;
		}

		return $this->slotInfo[$slot]['contentformat'];
	}

	/**
	 * Retrieves the content for the specified slot
	 *
	 * @param string $slot Slot name
	 * @return ?string Slot's content, or null if the slot's content is revision deleted
	 */
	public function getSlotContent( $slot ) {
		if ( !array_key_exists( $slot, $this->slotInfo ) ) {
			throw new InvalidArgumentException( 'The specified slot does not exist: ' . $slot );
		}

		if ( isset( $this->slotInfo[$slot]['texthidden'] ) ) {
			return null;
		}

		return $this->slotInfo[$slot]['content'];
	}
}
