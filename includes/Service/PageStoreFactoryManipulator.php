<?php

namespace WikiMirror\Service;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreFactory;
use ReflectionClass;
use WikiMirror\Mirror\LazyMirror;

class PageStoreFactoryManipulator extends PageStoreFactory {
	/** @var LazyMirror Lazy-loaded Mirror service */
	private LazyMirror $mirror;

	public function __construct( PageStoreFactory $original, LazyMirror $lazyMirror ) {
		$this->mirror = $lazyMirror;

		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	public function getPageStore( $wikiId = WikiAwareEntity::LOCAL ): PageStore {
		$store = parent::getPageStore( $wikiId );
		return new PageStoreManipulator( $store, $this->mirror );
	}
}
