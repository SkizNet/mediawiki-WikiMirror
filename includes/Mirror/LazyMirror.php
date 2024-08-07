<?php

namespace WikiMirror\Mirror;

use Wikimedia\Assert\Assert;

/**
 * Lazy-loaded Mirror service to break dependency loops
 */
class LazyMirror {
	/** @var Mirror|null */
	private ?Mirror $mirror = null;

	/** @var callable */
	private $mirrorFactory;

	public function __construct( callable $mirrorFactory ) {
		$this->mirrorFactory = $mirrorFactory;
	}

	public function getMirror(): Mirror {
		if ( $this->mirror === null ) {
			$this->mirror = ( $this->mirrorFactory )();
			Assert::postcondition(
				$this->mirror instanceof Mirror,
				'Mirror factory did not return a Mirror instance.'
			);
		}

		return $this->mirror;
	}
}
