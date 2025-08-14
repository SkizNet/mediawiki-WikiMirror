<?php

namespace WikiMirror\Compat;

use ReflectionClass;

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
	 */
	public static function callPrivateMethod( string $class, string $method, ?object $object, array $args = [] ) {
		$reflection = new ReflectionClass( $class );
		$method = $reflection->getMethod( $method );
		return $method->invokeArgs( $object, $args );
	}

	/**
	 * Retrieves the value of a private property via Reflection
	 *
	 * @param string $class Class name
	 * @param string $property Property Name
	 * @phpcs:ignore MediaWiki.Commenting.FunctionComment.ObjectTypeHintParam
	 * @param object|null $object Object to get property value of, or null for a static property
	 * @return mixed Value of property
	 */
	public static function getPrivateProperty( string $class, string $property, ?object $object ) {
		$reflection = new ReflectionClass( $class );
		$property = $reflection->getProperty( $property );
		return $property->getValue( $object );
	}

	/**
	 * Stores the value of a private property via Reflection
	 *
	 * @param string $class Class name
	 * @param string $property Property Name
	 * @phpcs:ignore MediaWiki.Commenting.FunctionComment.ObjectTypeHintParam
	 * @param object|null $object Object to set property value for, or null for a static property
	 * @param mixed $value Value of property to set
	 */
	public static function setPrivateProperty( string $class, string $property, ?object $object, $value ) {
		$reflection = new ReflectionClass( $class );
		$property = $reflection->getProperty( $property );
		$property->setValue( $object, $value );
	}
}
