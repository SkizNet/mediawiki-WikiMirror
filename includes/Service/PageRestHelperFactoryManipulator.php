<?php

namespace WikiMirror\Service;

use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler\Helper\HtmlOutputRendererHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use ReflectionClass;

class PageRestHelperFactoryManipulator extends PageRestHelperFactory {
	public function __construct( PageRestHelperFactory $original ) {
		// not calling parent::__construct is very intentional here,
		// the following code makes us a clone of $original instead
		$reflection = new ReflectionClass( parent::class );
		foreach ( $reflection->getProperties() as $property ) {
			$property->setValue( $this, $property->getValue( $original ) );
		}
	}

	/** @inheritDoc */
	public function newHtmlOutputRendererHelper(
		$page = null,
		array $parameters = [],
		?Authority $authority = null,
		$revision = null,
		bool $lenientRevHandling = false
	): HtmlOutputRendererHelper {
		// in 1.41 and 1.42 this only takes one arg: $lenientRevHandling,
		// however that'd be passed to us as $page
		if ( version_compare( MW_VERSION, '1.43', '<' ) ) {
			// @phan-suppress-next-line PhanParamTooFew
			$helper = parent::newHtmlOutputRendererHelper( $page );
		} else {
			// @phan-suppress-next-line PhanParamTooMany
			$helper = parent::newHtmlOutputRendererHelper(
				$page,
				$parameters,
				$authority,
				$revision,
				$lenientRevHandling
			);
		}

		return new HtmlOutputRendererHelperManipulator( $helper );
	}
}
