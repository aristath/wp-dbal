<?php

/**
 * Format Handler - Reads and writes data in JSON or PHP format.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Util;

/**
 * Handles reading and writing data files in different formats.
 *
 * Supports JSON (human-readable, git-friendly) and PHP (opcache-optimized).
 */
class FormatHandler
{
	/**
	 * The storage format ('json' or 'php').
	 *
	 * @var string
	 */
	protected string $format;

	/**
	 * Constructor.
	 *
	 * @param string $format The storage format ('json' or 'php').
	 */
	public function __construct(string $format = 'json')
	{
		$this->format = \in_array($format, ['json', 'php'], true) ? $format : 'json';
	}

	/**
	 * Get the file extension for the current format.
	 *
	 * @return string The file extension.
	 */
	public function getExtension(): string
	{
		return $this->format;
	}

	/**
	 * Read data from a file.
	 *
	 * @param string $path The file path.
	 * @return array<string, mixed>|null The data or null if file doesn't exist.
	 */
	public function read(string $path): ?array
	{
		if (!\file_exists($path)) {
			return null;
		}

		if ('php' === $this->format) {
			return $this->readPhp($path);
		}

		return $this->readJson($path);
	}

	/**
	 * Write data to a file.
	 *
	 * @param string               $path The file path.
	 * @param array<string, mixed> $data The data to write.
	 * @return bool True on success.
	 */
	public function write(string $path, array $data): bool
	{
		// Ensure directory exists.
		$dir = \dirname($path);
		if (!\is_dir($dir)) {
			\mkdir($dir, 0755, true);
		}

		if ('php' === $this->format) {
			return $this->writePhp($path, $data);
		}

		return $this->writeJson($path, $data);
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path The file path.
	 * @return bool True on success.
	 */
	public function delete(string $path): bool
	{
		if (\file_exists($path)) {
			return \unlink($path);
		}

		return true;
	}

	/**
	 * Check if a file exists.
	 *
	 * @param string $path The file path.
	 * @return bool True if file exists.
	 */
	public function exists(string $path): bool
	{
		return \file_exists($path);
	}

	/**
	 * Read JSON file.
	 *
	 * @param string $path The file path.
	 * @return array<string, mixed>|null The data or null on error.
	 */
	protected function readJson(string $path): ?array
	{
		$content = \file_get_contents($path);

		if (false === $content) {
			return null;
		}

		$data = \json_decode($content, true);

		if (!\is_array($data)) {
			return null;
		}

		return $data;
	}

	/**
	 * Write JSON file with atomic write and file locking.
	 *
	 * Uses a temp file + rename for atomicity and exclusive lock to prevent
	 * race conditions in concurrent access scenarios.
	 *
	 * @param string               $path The file path.
	 * @param array<string, mixed> $data The data to write.
	 * @return bool True on success.
	 */
	protected function writeJson(string $path, array $data): bool
	{
		$json = \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		if (false === $json) {
			return false;
		}

		return $this->atomicWrite($path, $json);
	}

	/**
	 * Write content atomically with file locking.
	 *
	 * Writes to a temp file first, then renames for atomicity.
	 * Uses LOCK_EX for exclusive locking during write.
	 *
	 * @param string $path    The target file path.
	 * @param string $content The content to write.
	 * @return bool True on success.
	 */
	protected function atomicWrite(string $path, string $content): bool
	{
		// Write to temp file first.
		$tempPath = $path . '.tmp.' . \getmypid() . '.' . \mt_rand();

		// Use LOCK_EX for exclusive lock during write.
		$result = \file_put_contents($tempPath, $content, \LOCK_EX);

		if (false === $result) {
			return false;
		}

		// Atomic rename (on POSIX systems).
		if (!\rename($tempPath, $path)) {
			// Cleanup temp file if rename fails.
			@\unlink($tempPath);
			return false;
		}

		return true;
	}

	/**
	 * Read PHP file.
	 *
	 * @param string $path The file path.
	 * @return array<string, mixed>|null The data or null on error.
	 */
	protected function readPhp(string $path): ?array
	{
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$data = include $path;

		if (!\is_array($data)) {
			return null;
		}

		return $data;
	}

	/**
	 * Write PHP file with atomic write and file locking.
	 *
	 * Uses a temp file + rename for atomicity and exclusive lock to prevent
	 * race conditions in concurrent access scenarios.
	 *
	 * @param string               $path The file path.
	 * @param array<string, mixed> $data The data to write.
	 * @return bool True on success.
	 */
	protected function writePhp(string $path, array $data): bool
	{
		$content = "<?php\n\nreturn " . $this->exportArray($data) . ";\n";

		return $this->atomicWrite($path, $content);
	}

	/**
	 * Export an array as a PHP string.
	 *
	 * @param array<string|int, mixed> $array The array to export.
	 * @param int                      $depth The indentation depth.
	 * @return string The PHP array string.
	 */
	protected function exportArray(array $array, int $depth = 0): string
	{
		$indent     = \str_repeat('    ', $depth);
		$nextIndent = \str_repeat('    ', $depth + 1);

		// Check if this is a sequential array.
		$isSequential = \array_keys($array) === \range(0, \count($array) - 1);

		$lines = [];

		foreach ($array as $key => $value) {
			$keyStr = $isSequential ? '' : (\is_string($key) ? "'" . \addslashes($key) . "' => " : $key . ' => ');

			if (\is_array($value)) {
				$lines[] = $nextIndent . $keyStr . $this->exportArray($value, $depth + 1);
			} elseif (\is_string($value)) {
				$lines[] = $nextIndent . $keyStr . "'" . \addslashes($value) . "'";
			} elseif (\is_bool($value)) {
				$lines[] = $nextIndent . $keyStr . ($value ? 'true' : 'false');
			} elseif (null === $value) {
				$lines[] = $nextIndent . $keyStr . 'null';
			} else {
				$lines[] = $nextIndent . $keyStr . $value;
			}
		}

		if (empty($lines)) {
			return '[]';
		}

		return "[\n" . \implode(",\n", $lines) . ",\n" . $indent . ']';
	}

	/**
	 * Build a file path with the correct extension.
	 *
	 * @param string $basePath The base path without extension.
	 * @return string The full path with extension.
	 */
	public function buildPath(string $basePath): string
	{
		return $basePath . '.' . $this->format;
	}
}
