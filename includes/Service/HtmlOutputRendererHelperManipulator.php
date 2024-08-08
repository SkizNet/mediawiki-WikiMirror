<?php

namespace WikiMirror\Service;

use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler\Helper\HtmlOutputRendererHelper;
use MediaWiki\Revision\RevisionRecord;
use ReflectionClass;
use ReflectionProperty;
use Wikimedia\Assert\Assert;
use Wikimedia\Bcp47Code\Bcp47Code;
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

	/**
	 * 1.41/1.42 compatibility shim
	 *
	 * @param PageIdentity $page
	 * @param array $parameters
	 * @param Authority $authority
	 * @param RevisionRecord|int|null $revision
	 * @param Bcp47Code|null $pageLanguage
	 * @return void
	 */
	public function init(
		PageIdentity $page,
		array $parameters,
		Authority $authority,
		$revision = null,
		?Bcp47Code $pageLanguage = null
	) {
		// @phan-suppress-next-line PhanParamTooMany, PhanTypeMismatchArgumentSuperType
		parent::init( $page, $parameters, $authority, $revision, $pageLanguage );

		if ( $page instanceof MirrorPageRecord ) {
			$parserOutputProperty = new ReflectionProperty( parent::class, 'parserOutput' );
			$parserOutputProperty->setValue( $this, $page->getParserOutput() );
		}
	}
}
