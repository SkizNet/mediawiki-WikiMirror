<?php

namespace WikiMirror\Service;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use ReflectionClass;
use WikiMirror\Mirror\Mirror;

class PageStoreFactoryManipulator extends PageStoreFactory {
	/** @var Mirror|callable Lazy-loaded Mirror service */
	private $mirror;

	public function __construct( PageStoreFactory $original, callable $lazyMirror ) {
		$this->mirror = $lazyMirror;

		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	public function getPageStore( $wikiId = WikiAwareEntity::LOCAL ): PageStore {
		if ( !( $this->mirror instanceof Mirror ) ) {
			$this->mirror = ($this->mirror)();
		}

		$store = parent::getPageStore( $wikiId );
		return new PageStoreManipulator( $store, $this->mirror );
	}
}
