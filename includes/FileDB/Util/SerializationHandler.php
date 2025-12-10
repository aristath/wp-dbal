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
	 * Uses pattern matching instead of unserialize() for security.
	 * This avoids potential object instantiation vulnerabilities.
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
		// NULL serialized value.
		if ('N;' === $value) {
			return true;
		}

		// Boolean false.
		if ('b:0;' === $value) {
			return true;
		}

		// Boolean true.
		if ('b:1;' === $value) {
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

		// Match patterns for each serialized type without using unserialize().
		return match ($firstChar) {
			// String: s:length:"value";
			's' => (bool) \preg_match('/^s:\d+:".*";$/s', $value),
			// Integer: i:value;
			'i' => (bool) \preg_match('/^i:-?\d+;$/', $value),
			// Double/Float: d:value;
			'd' => (bool) \preg_match('/^d:-?\d+(\.\d+)?(E[+-]?\d+)?;$/i', $value),
			// Array: a:count:{...}
			'a' => $this->looksLikeSerializedArray($value),
			// Object: O:length:"classname":count:{...}
			'O' => $this->looksLikeSerializedObject($value),
			// Custom serialized object: C:length:"classname":length:{...}
			'C' => $this->looksLikeSerializedObject($value),
			default => false,
		};
	}

	/**
	 * Check if a string looks like a serialized array.
	 *
	 * @param string $value The string to check.
	 * @return bool True if it looks like a serialized array.
	 */
	protected function looksLikeSerializedArray(string $value): bool
	{
		// Pattern: a:count:{...}
		if (!\preg_match('/^a:(\d+):\{/', $value, $matches)) {
			return false;
		}

		// Must end with }
		if ('}' !== \substr($value, -1)) {
			return false;
		}

		// The count should be a reasonable number.
		$count = (int) $matches[1];
		if ($count > 10000) {
			// Suspiciously large array, reject.
			return false;
		}

		return true;
	}

	/**
	 * Check if a string looks like a serialized object.
	 *
	 * @param string $value The string to check.
	 * @return bool True if it looks like a serialized object.
	 */
	protected function looksLikeSerializedObject(string $value): bool
	{
		// Pattern: O:length:"classname":count:{...} or C:length:"classname":length:{...}
		if (!\preg_match('/^[OC]:(\d+):"([^"]+)":\d+:\{/', $value, $matches)) {
			return false;
		}

		// Must end with }
		if ('}' !== \substr($value, -1)) {
			return false;
		}

		// Validate class name length matches declared length.
		$declaredLength = (int) $matches[1];
		$className      = $matches[2];

		if (\strlen($className) !== $declaredLength) {
			return false;
		}

		// Validate class name characters (PHP class names).
		if (!\preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/', $className)) {
			return false;
		}

		return true;
	}

	/**
	 * Convert a serialized value to JSON-storable format.
	 *
	 * Uses unserialize() with allowed_classes restrictions for security.
	 * Only allows WordPress core classes and stdClass by default.
	 *
	 * @param string $serialized The serialized string.
	 * @return array{value: mixed, _serialized: true}|string The converted value or original string.
	 */
	public function toJson(string $serialized): array|string
	{
		if (!$this->isSerialized($serialized)) {
			return $serialized;
		}

		// Use safe unserialize with allowed_classes option.
		// This prevents arbitrary object instantiation attacks.
		$value = $this->safeUnserialize($serialized);

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
	 * Safely unserialize a string with class restrictions.
	 *
	 * By default, allows only stdClass. WordPress core classes and user-defined
	 * classes can be added via the allowed_classes filter or by extending this class.
	 *
	 * @param string $serialized The serialized string.
	 * @return mixed The unserialized value, or false on failure.
	 */
	protected function safeUnserialize(string $serialized): mixed
	{
		// Define allowed classes for unserialization.
		// Using stdClass as fallback allows data to be preserved even if
		// original classes don't exist.
		$allowedClasses = $this->getAllowedClasses();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return @\unserialize($serialized, [
			'allowed_classes' => $allowedClasses,
		]);
	}

	/**
	 * Get the list of allowed classes for unserialization.
	 *
	 * Override this method to customize allowed classes.
	 * By default, allows only stdClass to prevent arbitrary object instantiation.
	 *
	 * @return array<string>|bool List of allowed class names, or true to allow all (NOT RECOMMENDED).
	 */
	protected function getAllowedClasses(): array|bool
	{
		// By default, convert all objects to stdClass.
		// This is the safest option as it prevents code execution through
		// __wakeup() or __destruct() methods of arbitrary classes.
		//
		// Set to true if you need to preserve original class types (less secure).
		// You can also return an array of specific class names to allow.
		return [ \stdClass::class ];
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
