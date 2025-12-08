<?php

/**
 * Index Manager - Manages auto-maintained indexes for fast lookups.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Storage;

use WP_DBAL\FileDB\Util\FormatHandler;

/**
 * Manages indexes for all table columns.
 *
 * Indexes map column values to primary key IDs for fast lookups.
 * Format: {"value1": [id1, id2], "value2": [id3, id4]}
 */
class IndexManager
{
	/**
	 * Base storage path.
	 *
	 * @var string
	 */
	protected string $basePath;

	/**
	 * Format handler instance.
	 *
	 * @var FormatHandler
	 */
	protected FormatHandler $format;

	/**
	 * Whether indexing is enabled.
	 *
	 * @var boolean
	 */
	protected bool $enabled;

	/**
	 * Cached indexes.
	 *
	 * @var array<string, array<string, array<string, list<int|string>>>>
	 */
	protected array $indexes = [];

	/**
	 * Dirty indexes (modified but not written).
	 *
	 * @var array<string, array<string, bool>>
	 */
	protected array $dirty = [];

	/**
	 * Constructor.
	 *
	 * @param string        $basePath The base storage path.
	 * @param FormatHandler $format   The format handler.
	 * @param bool          $enabled  Whether indexing is enabled.
	 */
	public function __construct(string $basePath, FormatHandler $format, bool $enabled = true)
	{
		$this->basePath = $basePath;
		$this->format   = $format;
		$this->enabled  = $enabled;

		if ($enabled) {
			$this->ensureDirectory();
		}
	}

	/**
	 * Ensure the indexes directory exists.
	 *
	 * @return void
	 */
	protected function ensureDirectory(): void
	{
		$dir = $this->basePath . '/_indexes';
		if (!\is_dir($dir)) {
			\mkdir($dir, 0755, true);
		}
	}

	/**
	 * Check if indexing is enabled.
	 *
	 * @return bool True if enabled.
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * Get an index for a table column.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @return array<string, list<int|string>> The index (value => [ids]).
	 */
	public function getIndex(string $table, string $column): array
	{
		if (!$this->enabled) {
			return [];
		}

		if (isset($this->indexes[$table][$column])) {
			return $this->indexes[$table][$column];
		}

		$path = $this->getIndexPath($table, $column);
		$index = $this->format->read($path);

		$this->indexes[$table][$column] = $index ?? [];

		return $this->indexes[$table][$column];
	}

	/**
	 * Find row IDs by column value using the index.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @param mixed  $value  The value to find.
	 * @return list<int|string> The matching row IDs.
	 */
	public function findByValue(string $table, string $column, mixed $value): array
	{
		if (!$this->enabled) {
			return [];
		}

		$index = $this->getIndex($table, $column);
		$key   = $this->normalizeValue($value);

		return $index[$key] ?? [];
	}

	/**
	 * Add an entry to indexes for a new row.
	 *
	 * @param string               $table     The table name.
	 * @param int|string           $primaryId The primary key value.
	 * @param array<string, mixed> $row       The row data.
	 * @return void
	 */
	public function addRow(string $table, int|string $primaryId, array $row): void
	{
		if (!$this->enabled) {
			return;
		}

		foreach ($row as $column => $value) {
			$this->addToIndex($table, $column, $value, $primaryId);
		}
	}

	/**
	 * Update indexes when a row is modified.
	 *
	 * @param string               $table     The table name.
	 * @param int|string           $primaryId The primary key value.
	 * @param array<string, mixed> $oldRow    The old row data.
	 * @param array<string, mixed> $newRow    The new row data.
	 * @return void
	 */
	public function updateRow(string $table, int|string $primaryId, array $oldRow, array $newRow): void
	{
		if (!$this->enabled) {
			return;
		}

		// Find changed columns.
		$allColumns = \array_unique(\array_merge(\array_keys($oldRow), \array_keys($newRow)));

		foreach ($allColumns as $column) {
			$oldValue = $oldRow[$column] ?? null;
			$newValue = $newRow[$column] ?? null;

			if ($oldValue !== $newValue) {
				// Remove from old index.
				$this->removeFromIndex($table, $column, $oldValue, $primaryId);
				// Add to new index.
				$this->addToIndex($table, $column, $newValue, $primaryId);
			}
		}
	}

	/**
	 * Remove all index entries for a row.
	 *
	 * @param string               $table     The table name.
	 * @param int|string           $primaryId The primary key value.
	 * @param array<string, mixed> $row       The row data.
	 * @return void
	 */
	public function removeRow(string $table, int|string $primaryId, array $row): void
	{
		if (!$this->enabled) {
			return;
		}

		foreach ($row as $column => $value) {
			$this->removeFromIndex($table, $column, $value, $primaryId);
		}
	}

	/**
	 * Add an entry to an index.
	 *
	 * @param string     $table     The table name.
	 * @param string     $column    The column name.
	 * @param mixed      $value     The column value.
	 * @param int|string $primaryId The primary key value.
	 * @return void
	 */
	protected function addToIndex(string $table, string $column, mixed $value, int|string $primaryId): void
	{
		$index = $this->getIndex($table, $column);
		$key   = $this->normalizeValue($value);

		if (!isset($index[$key])) {
			$index[$key] = [];
		}

		// Avoid duplicates.
		if (!\in_array($primaryId, $index[$key], true)) {
			$index[$key][] = $primaryId;
		}

		$this->indexes[$table][$column] = $index;
		$this->dirty[$table][$column]   = true;
	}

	/**
	 * Remove an entry from an index.
	 *
	 * @param string     $table     The table name.
	 * @param string     $column    The column name.
	 * @param mixed      $value     The column value.
	 * @param int|string $primaryId The primary key value.
	 * @return void
	 */
	protected function removeFromIndex(string $table, string $column, mixed $value, int|string $primaryId): void
	{
		$index = $this->getIndex($table, $column);
		$key   = $this->normalizeValue($value);

		if (!isset($index[$key])) {
			return;
		}

		$index[$key] = \array_values(\array_filter(
			$index[$key],
			fn($id) => $id !== $primaryId
		));

		// Remove empty entries.
		if (empty($index[$key])) {
			unset($index[$key]);
		}

		$this->indexes[$table][$column] = $index;
		$this->dirty[$table][$column]   = true;
	}

	/**
	 * Normalize a value for use as an index key.
	 *
	 * @param mixed $value The value to normalize.
	 * @return string The normalized key.
	 */
	protected function normalizeValue(mixed $value): string
	{
		if (null === $value) {
			return '__NULL__';
		}

		if (\is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (\is_array($value) || \is_object($value)) {
			return \md5(\serialize($value));
		}

		return (string) $value;
	}

	/**
	 * Get the file path for an index.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @return string The file path.
	 */
	protected function getIndexPath(string $table, string $column): string
	{
		$dir = $this->basePath . '/_indexes/' . $table;
		if (!\is_dir($dir)) {
			\mkdir($dir, 0755, true);
		}

		return $this->format->buildPath($dir . '/' . $column);
	}

	/**
	 * Flush all dirty indexes to disk.
	 *
	 * @return void
	 */
	public function flush(): void
	{
		if (!$this->enabled) {
			return;
		}

		foreach ($this->dirty as $table => $columns) {
			foreach (\array_keys($columns) as $column) {
				$path  = $this->getIndexPath($table, $column);
				$index = $this->indexes[$table][$column] ?? [];
				$this->format->write($path, $index);
			}
		}

		$this->dirty = [];
	}

	/**
	 * Rebuild all indexes for a table.
	 *
	 * @param string                        $table The table name.
	 * @param list<array<string, mixed>>    $rows  All rows in the table.
	 * @param list<string>                  $pk    Primary key columns.
	 * @return void
	 */
	public function rebuildTable(string $table, array $rows, array $pk): void
	{
		if (!$this->enabled) {
			return;
		}

		// Clear existing indexes for this table.
		$this->indexes[$table] = [];

		foreach ($rows as $row) {
			// Get primary key value.
			$pkValue = $this->getPrimaryKeyValue($row, $pk);
			$this->addRow($table, $pkValue, $row);
		}

		$this->flush();
	}

	/**
	 * Get the primary key value from a row.
	 *
	 * @param array<string, mixed> $row The row data.
	 * @param list<string>         $pk  Primary key columns.
	 * @return int|string The primary key value.
	 */
	protected function getPrimaryKeyValue(array $row, array $pk): int|string
	{
		if (1 === \count($pk)) {
			return $row[$pk[0]] ?? 0;
		}

		// Composite key.
		$parts = [];
		foreach ($pk as $col) {
			$parts[] = $row[$col] ?? '';
		}

		return \implode('_', $parts);
	}

	/**
	 * Drop all indexes for a table.
	 *
	 * @param string $table The table name.
	 * @return void
	 */
	public function dropTable(string $table): void
	{
		if (!$this->enabled) {
			return;
		}

		$dir = $this->basePath . '/_indexes/' . $table;
		if (\is_dir($dir)) {
			$files = \scandir($dir);
			if (false !== $files) {
				foreach ($files as $file) {
					if ('.' !== $file && '..' !== $file) {
						\unlink($dir . '/' . $file);
					}
				}
			}
			\rmdir($dir);
		}

		unset($this->indexes[$table]);
		unset($this->dirty[$table]);
	}
}
