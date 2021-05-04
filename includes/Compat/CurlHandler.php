<?php

namespace WikiMirror\Compat;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

class CurlHandler extends \GuzzleHttp\Handler\CurlHandler {
	/** @var array Array of options passed to curl_setopt_array() */
	private $curlOptions;

	/**
	 * CurlHandler constructor.
	 *
	 * @param array $curlOptions Options passed to cURL (keys of cURL constants)
	 * @param array $factoryOptions Accepts 'handle_factory' key to override the factory used to create this handler
	 */
	public function __construct( array $curlOptions = [], array $factoryOptions = [] ) {
		$this->curlOptions = $curlOptions;
		parent::__construct( $factoryOptions );
	}

	/**
	 * Pass curl options up to Guzzle.
	 *
	 * @param RequestInterface $request
	 * @param array $options
	 * @return PromiseInterface
	 */
	public function __invoke( RequestInterface $request, array $options ): PromiseInterface {
		$requestOptions = [];
		if ( array_key_exists( 'curl', $options ) ) {
			$requestOptions = $options['curl'];
		}

		$options['curl'] = array_replace( $this->curlOptions, $requestOptions );

		return parent::__invoke( $request, $options );
	}
}
