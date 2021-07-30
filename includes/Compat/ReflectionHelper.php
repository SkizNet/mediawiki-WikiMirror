<?php

namespace WikiMirror\Compat;

use MWException;
use ReflectionClass;
use ReflectionException;

class ReflectionHelper {
	/**
	 * Call a private method via Reflection
	 *
	 * @param string $class Class name
	 * @param string $method Method name
	 * @phpcs:ignore MediaWiki.Commenting.FunctionComment.ObjectTypeHintParam
	 * @param object|null $object Object to call method on, or null for a static method
	 * @param array $args Arguments to method
	 * @return mixed Return value of method called
	 * @throws MWException On error
	 */
	public static function callPrivateMethod( string $class, string $method, ?object $object, array $args = [] ) {
		try {
			$reflection = new ReflectionClass( $class );
			$method = $reflection->getMethod( $method );
			$method->setAccessible( true );
			$value = $method->invokeArgs( $object, $args );
			return $value;
		} catch ( ReflectionException $e ) {
			// wrap the ReflectionException into a MWException for friendlier error display
			throw new MWException(
				'The WikiMirror extension is not compatible with your MediaWiki version', 0, $e );
		}
	}

	/**
	 * Retrieves the value of a private property via Reflection
	 *
	 * @param string $class Class name
	 * @param string $property Property Name
	 * @phpcs:ignore MediaWiki.Commenting.FunctionComment.ObjectTypeHintParam
	 * @param object|null $object Object to get property value of, or null for a static property
	 * @return mixed Value of property
	 * @throws MWException
	 */
	public static function getPrivateProperty( string $class, string $property, ?object $object ) {
		try {
			$reflection = new ReflectionClass( $class );
			$property = $reflection->getProperty( $property );
			$property->setAccessible( true );
			$value = $property->getValue( $object );
			return $value;
		} catch ( ReflectionException $e ) {
			// wrap the ReflectionException into a MWException for friendlier error display
			throw new MWException(
				'The WikiMirror extension is not compatible with your MediaWiki version', 0, $e );
		}
	}

	/**
	 * Stores the value of a private property via Reflection
	 *
	 * @param string $class Class name
	 * @param string $property Property Name
	 * @phpcs:ignore MediaWiki.Commenting.FunctionComment.ObjectTypeHintParam
	 * @param object|null $object Object to set property value for, or null for a static property
	 * @param mixed $value Value of property to set
	 * @throws MWException
	 */
	public static function setPrivateProperty( string $class, string $property, ?object $object, $value ) {
		try {
			$reflection = new ReflectionClass( $class );
			$property = $reflection->getProperty( $property );
			$property->setAccessible( true );
			$property->setValue( $object, $value );
		} catch ( ReflectionException $e ) {
			// wrap the ReflectionException into a MWException for friendlier error display
			throw new MWException(
				'The WikiMirror extension is not compatible with your MediaWiki version', 0, $e );
		}
	}
}
