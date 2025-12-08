<?php

/**
 * Serialization Handler - Converts between PHP serialized strings and JSON.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Util;

/**
 * Handles conversion between PHP serialized data and JSON-storable arrays.
 *
 * WordPress stores arrays/objects as serialized PHP strings. This handler
 * converts them to JSON-friendly structures for human-readable storage,
 * and converts back when reading.
 */
class SerializationHandler
{
	/**
	 * Check if a value is a PHP serialized string.
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if the value is serialized.
	 */
	public function isSerialized(mixed $value): bool
	{
		if (!\is_string($value)) {
			return false;
		}

		$value = \trim($value);

		if ('' === $value) {
			return false;
		}

		// Check common serialized patterns.
		if ('N;' === $value) {
			return true;
		}

		if (\strlen($value) < 4) {
			return false;
		}

		if (':' !== $value[1]) {
			return false;
		}

		// Check first character for serialized type.
		$firstChar = $value[0];
		if (!\in_array($firstChar, ['s', 'a', 'O', 'b', 'i', 'd', 'C'], true)) {
			return false;
		}

		// Try to unserialize.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$test = @\unserialize($value);

		return false !== $test || 'b:0;' === $value;
	}

	/**
	 * Convert a serialized value to JSON-storable format.
	 *
	 * @param string $serialized The serialized string.
	 * @return array{value: mixed, _serialized: true}|string The converted value or original string.
	 */
	public function toJson(string $serialized): array|string
	{
		if (!$this->isSerialized($serialized)) {
			return $serialized;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$value = @\unserialize($serialized);

		if (false === $value && 'b:0;' !== $serialized) {
			// Failed to unserialize, return as-is.
			return $serialized;
		}

		// Check if the value contains objects that extend PHP built-in classes.
		// These cannot be reconstructed via Reflection (e.g., DateTime, Closure).
		// Keep them as serialized strings.
		if ($this->containsUnreconstructableObjects($value)) {
			return $serialized;
		}

		// Convert to JSON-safe structure.
		return [
			'value'       => $this->makeJsonSafe($value),
			'_serialized' => true,
		];
	}

	/**
	 * Check if a value contains objects that cannot be reconstructed via Reflection.
	 *
	 * PHP built-in classes (DateTime, Closure, etc.) require their constructors to run.
	 * They cannot be created with newInstanceWithoutConstructor() safely.
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if contains unreconstructable objects.
	 */
	protected function containsUnreconstructableObjects(mixed $value): bool
	{
		if (\is_object($value)) {
			// Check if this object extends a PHP internal (built-in) class.
			try {
				$reflection = new \ReflectionClass($value);

				// Check if this class or any parent is internal (built-in).
				$current = $reflection;
				while ($current) {
					if ($current->isInternal()) {
						return true;
					}
					$current = $current->getParentClass();
				}

				// Recursively check all properties.
				$properties = $this->getAllProperties($reflection);
				foreach ($properties as $prop) {
					$prop->setAccessible(true);

					// Skip uninitialized properties (PHP 7.4+ typed properties).
					if (!$prop->isInitialized($value)) {
						continue;
					}

					if ($this->containsUnreconstructableObjects($prop->getValue($value))) {
						return true;
					}
				}
			} catch (\ReflectionException $e) {
				// If we can't reflect, assume it's safe to serialize.
				return false;
			}
		}

		if (\is_array($value)) {
			foreach ($value as $item) {
				if ($this->containsUnreconstructableObjects($item)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Convert a JSON-stored value back to serialized format.
	 *
	 * @param mixed $value The stored value.
	 * @return mixed The original or serialized value.
	 */
	public function fromJson(mixed $value): mixed
	{
		// Check if this was a serialized value.
		if (\is_array($value) && isset($value['_serialized']) && true === $value['_serialized']) {
			$original = $this->restoreFromJson($value['value'] ?? null);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			return \serialize($original);
		}

		return $value;
	}

	/**
	 * Restore a value from JSON format, recreating objects if needed.
	 *
	 * @param mixed $value The JSON value to restore.
	 * @return mixed The restored value.
	 */
	protected function restoreFromJson(mixed $value): mixed
	{
		// Check if this represents a serialized object.
		if (\is_array($value) && isset($value['__class'])) {
			$class = $value['__class'];
			$data  = $value['__data'] ?? [];

			// Check if class exists.
			if (!\class_exists($class)) {
				// Return as stdClass if original class doesn't exist.
				$obj = new \stdClass();
				foreach ($data as $propName => $propValue) {
					$obj->$propName = $this->restoreFromJson($propValue);
				}
				return $obj;
			}

			try {
				// Create instance without calling constructor.
				$reflection = new \ReflectionClass($class);
				$instance   = $reflection->newInstanceWithoutConstructor();

				foreach ($data as $propName => $propValue) {
					// Try to get the property from the class hierarchy.
					$prop = $this->getPropertyFromHierarchy($reflection, $propName);

					if (null !== $prop) {
						$prop->setAccessible(true);
						$prop->setValue($instance, $this->restoreFromJson($propValue));
					}
				}

				return $instance;
			} catch (\ReflectionException $e) {
				// Fall back to stdClass.
				$obj = new \stdClass();
				foreach ($data as $propName => $propValue) {
					$obj->$propName = $this->restoreFromJson($propValue);
				}
				return $obj;
			}
		}

		// Recursively process arrays.
		if (\is_array($value)) {
			$result = [];
			foreach ($value as $key => $item) {
				$result[$key] = $this->restoreFromJson($item);
			}
			return $result;
		}

		return $value;
	}

	/**
	 * Get a property from a class or its parent classes.
	 *
	 * @param \ReflectionClass<object> $reflection The reflection class.
	 * @param string                   $propName   The property name.
	 * @return \ReflectionProperty|null The property or null if not found.
	 */
	protected function getPropertyFromHierarchy(\ReflectionClass $reflection, string $propName): ?\ReflectionProperty
	{
		// Try the current class first.
		if ($reflection->hasProperty($propName)) {
			return $reflection->getProperty($propName);
		}

		// Try parent classes (for inherited private properties).
		$parent = $reflection->getParentClass();
		while ($parent) {
			if ($parent->hasProperty($propName)) {
				return $parent->getProperty($propName);
			}
			$parent = $parent->getParentClass();
		}

		return null;
	}

	/**
	 * Process a row for storage (serialize → JSON).
	 *
	 * @param array<string, mixed> $row The row data.
	 * @return array<string, mixed> The processed row.
	 */
	public function processForStorage(array $row): array
	{
		$processed = [];

		foreach ($row as $key => $value) {
			if (\is_string($value) && $this->isSerialized($value)) {
				$processed[$key] = $this->toJson($value);
			} else {
				$processed[$key] = $value;
			}
		}

		return $processed;
	}

	/**
	 * Process a row for reading (JSON → serialize).
	 *
	 * @param array<string, mixed> $row The stored row data.
	 * @return array<string, mixed> The processed row.
	 */
	public function processForReading(array $row): array
	{
		$processed = [];

		foreach ($row as $key => $value) {
			$processed[$key] = $this->fromJson($value);
		}

		return $processed;
	}

	/**
	 * Make a value JSON-safe by converting objects to arrays.
	 *
	 * Uses Reflection to access ALL properties (public, protected, private)
	 * including inherited properties.
	 *
	 * @param mixed $value The value to convert.
	 * @return mixed The JSON-safe value.
	 */
	protected function makeJsonSafe(mixed $value): mixed
	{
		if (\is_object($value)) {
			$class = \get_class($value);
			$data  = [];

			try {
				// Use Reflection to get ALL properties (public, protected, private).
				$reflection = new \ReflectionClass($value);
				$properties = $this->getAllProperties($reflection);

				foreach ($properties as $prop) {
					$prop->setAccessible(true);
					$propName        = $prop->getName();
					$data[$propName] = $this->makeJsonSafe($prop->getValue($value));
				}
			} catch (\ReflectionException $e) {
				// Fall back to public properties only.
				$data = \get_object_vars($value);
			}

			return [
				'__class' => $class,
				'__data'  => $data,
			];
		}

		if (\is_array($value)) {
			$result = [];
			foreach ($value as $key => $item) {
				$result[$key] = $this->makeJsonSafe($item);
			}
			return $result;
		}

		// Handle resource types (can't be serialized properly).
		if (\is_resource($value)) {
			return null;
		}

		return $value;
	}

	/**
	 * Get all properties from a class including inherited ones.
	 *
	 * @param \ReflectionClass<object> $reflection The reflection class.
	 * @return array<\ReflectionProperty> All properties.
	 */
	protected function getAllProperties(\ReflectionClass $reflection): array
	{
		$properties = $reflection->getProperties();

		// Get properties from parent classes (private properties are not inherited).
		$parent = $reflection->getParentClass();
		while ($parent) {
			foreach ($parent->getProperties(\ReflectionProperty::IS_PRIVATE) as $prop) {
				$properties[] = $prop;
			}
			$parent = $parent->getParentClass();
		}

		return $properties;
	}
}
