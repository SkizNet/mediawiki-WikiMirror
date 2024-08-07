<?php

namespace WikiMirror\Service;

use MediaWiki\Rest\Handler\Helper\HtmlOutputRendererHelper;
use ReflectionClass;
use Wikimedia\Assert\Assert;
use WikiMirror\Mirror\MirrorPageRecord;

class HtmlOutputRendererHelperManipulator extends HtmlOutputRendererHelper {
	public function __construct( HtmlOutputRendererHelper $original ) {
		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		$pageProperty = null;
		$parserOutputProperty = null;
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
			if ( $property->getName() === 'page' ) {
				$pageProperty = $property;
			} elseif ( $property->getName() === 'parserOutput' ) {
				$parserOutputProperty = $property;
			}
		}

		// override the private parserOutput cache variable for mirrored pages
		Assert::invariant(
			$pageProperty !== null,
			'No page property discovered in HtmlOutputRendererHelper' );
		Assert::invariant(
			$parserOutputProperty !== null,
			'No parserOutput property discovered in HtmlOutputRendererHelper' );

		$page = $pageProperty->getValue( $this );
		if ( $page instanceof MirrorPageRecord ) {
			$parserOutputProperty->setValue( $this, $page->getParserOutput() );
		}
	}
}
