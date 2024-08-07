<?php

namespace WikiMirror\Service;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Parser\Parsoid\ParsoidOutputAccess;
use MediaWiki\Status\Status;
use ParserOptions;
use ReflectionClass;
use WikiMirror\Mirror\MirrorPageRecord;

class ParsoidOutputAccessManipulator extends ParsoidOutputAccess {
	public function __construct( ParsoidOutputAccess $original ) {
		// do crimes with Reflection since our parent is unstable
		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	/** @inheritDoc */
	public function parseUncacheable(
		PageIdentity $page,
		ParserOptions $parserOpts,
		$revision,
		bool $lenientRevHandling = false
	): Status {
		if ( $page instanceof MirrorPageRecord ) {
			// use remote HTML
			return Status::newGood( $page->getParserOutput() );
		}

		return parent::parseUncacheable( $page, $parserOpts, $revision, $lenientRevHandling );
	}
}
