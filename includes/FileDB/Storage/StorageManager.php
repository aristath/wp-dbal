<?php

/**
 * Storage Manager - Orchestrates all file I/O operations.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Storage;

use WP_DBAL\FileDB\Util\FormatHandler;
use WP_DBAL\FileDB\Util\SerializationHandler;

/**
 * Central storage manager for file-based database operations.
 *
 * Handles:
 * - Reading and writing rows to files
 * - Managing table directories
 * - Coordinating with SchemaManager and IndexManager
 */
class StorageManager
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
	 * Serialization handler instance.
	 *
	 * @var SerializationHandler
	 */
	protected SerializationHandler $serialization;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	protected SchemaManager $schema;

	/**
	 * Index manager instance.
	 *
	 * @var IndexManager
	 */
	protected IndexManager $index;

	/**
	 * Cached rows by table.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	protected array $cache = [];

	/**
	 * Dirty rows (modified but not written).
	 *
	 * @var array<string, array<string, bool>>
	 */
	protected array $dirty = [];

	/**
	 * Deleted rows.
	 *
	 * @var array<string, array<string, bool>>
	 */
	protected array $deleted = [];

	/**
	 * Constructor.
	 *
	 * @param string $basePath The base storage path.
	 * @param string $format   The storage format ('json' or 'php').
	 */
	public function __construct(string $basePath, string $format = 'json')
	{
		$this->basePath      = $basePath;
		$this->format        = new FormatHandler($format);
		$this->serialization = new SerializationHandler();
		$this->schema        = new SchemaManager($basePath, $this->format);
		$this->index         = new IndexManager(
			$basePath,
			$this->format,
			$this->isIndexingEnabled()
		);

		$this->ensureDirectories();
	}

	/**
	 * Check if indexing is enabled.
	 *
	 * @return bool True if indexing is enabled.
	 */
	protected function isIndexingEnabled(): bool
	{
		if (\defined('DB_FILEDB_INDEXES')) {
			return (bool) DB_FILEDB_INDEXES;
		}

		return true;
	}

	/**
	 * Ensure required directories exist.
	 *
	 * @return void
	 */
	protected function ensureDirectories(): void
	{
		$dirs = [
			$this->basePath,
			$this->basePath . '/tables',
		];

		foreach ($dirs as $dir) {
			if (!\is_dir($dir)) {
				\mkdir($dir, 0755, true);
			}
		}
	}

	/**
	 * Get the schema manager.
	 *
	 * @return SchemaManager The schema manager.
	 */
	public function getSchemaManager(): SchemaManager
	{
		return $this->schema;
	}

	/**
	 * Get the index manager.
	 *
	 * @return IndexManager The index manager.
	 */
	public function getIndexManager(): IndexManager
	{
		return $this->index;
	}

	/**
	 * Get the base storage path.
	 *
	 * @return string The base path.
	 */
	public function getBasePath(): string
	{
		return $this->basePath;
	}

	/**
	 * Get a single row by primary key.
	 *
	 * @param string     $table     The table name.
	 * @param int|string $primaryId The primary key value.
	 * @return array<string, mixed>|null The row data or null if not found.
	 */
	public function getRow(string $table, int|string $primaryId): ?array
	{
		$key = (string) $primaryId;

		// Check cache first.
		if (isset($this->cache[$table][$key])) {
			return $this->cache[$table][$key];
		}

		// Check if deleted.
		if (isset($this->deleted[$table][$key])) {
			return null;
		}

		// Load from file.
		$path = $this->getRowPath($table, $primaryId);
		$data = $this->format->read($path);

		if (null === $data) {
			return null;
		}

		// Process serialized data.
		$row = $this->serialization->processForReading($data);

		// Cache it.
		$this->cache[$table][$key] = $row;

		return $row;
	}

	/**
	 * Get all rows from a table.
	 *
	 * @param string $table The table name.
	 * @return list<array<string, mixed>> All rows.
	 */
	public function getAllRows(string $table): array
	{
		$rows = [];
		$dir  = $this->getTablePath($table);

		if (!\is_dir($dir)) {
			return $rows;
		}

		$files = \scandir($dir);
		if (false === $files) {
			return $rows;
		}

		$extension = '.' . $this->format->getExtension();

		foreach ($files as $file) {
			if ('.' === $file || '..' === $file) {
				continue;
			}

			if (!\str_ends_with($file, $extension)) {
				continue;
			}

			// Extract primary key from filename.
			$primaryId = \basename($file, $extension);

			// Check if deleted.
			if (isset($this->deleted[$table][$primaryId])) {
				continue;
			}

			$row = $this->getRow($table, $primaryId);
			if (null !== $row) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Get rows by primary key IDs.
	 *
	 * @param string             $table The table name.
	 * @param list<int|string>   $ids   The primary key values.
	 * @return list<array<string, mixed>> The matching rows.
	 */
	public function getRowsByIds(string $table, array $ids): array
	{
		$rows = [];

		foreach ($ids as $id) {
			$row = $this->getRow($table, $id);
			if (null !== $row) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Insert a new row.
	 *
	 * @param string               $table The table name.
	 * @param array<string, mixed> $row   The row data.
	 * @return int|string The primary key value.
	 */
	public function insertRow(string $table, array $row): int|string
	{
		$pk = $this->schema->getPrimaryKey($table);

		// Handle auto-increment.
		if (1 === \count($pk)) {
			$pkColumn = $pk[0];
			if (!isset($row[$pkColumn]) || empty($row[$pkColumn])) {
				$row[$pkColumn] = $this->schema->getNextAutoIncrement($table);
				$this->schema->updateAutoIncrement($table, (int) $row[$pkColumn]);
			} else {
				// Update auto-increment if inserting a higher value.
				$currentMax = (int) ($this->schema->getMetadata($table)['autoIncrement'] ?? 0);
				if ((int) $row[$pkColumn] > $currentMax) {
					$this->schema->updateAutoIncrement($table, (int) $row[$pkColumn]);
				}
			}
		}

		$primaryId = $this->getPrimaryKeyValue($row, $pk);
		$key       = (string) $primaryId;

		// Process for storage.
		$storedRow = $this->serialization->processForStorage($row);

		// Cache it.
		$this->cache[$table][$key] = $row;
		$this->dirty[$table][$key] = true;

		// Remove from deleted if was previously deleted.
		unset($this->deleted[$table][$key]);

		// Update indexes.
		$this->index->addRow($table, $primaryId, $row);

		return $primaryId;
	}

	/**
	 * Update an existing row.
	 *
	 * @param string               $table     The table name.
	 * @param int|string           $primaryId The primary key value.
	 * @param array<string, mixed> $updates   The columns to update.
	 * @return bool True if row was updated.
	 */
	public function updateRow(string $table, int|string $primaryId, array $updates): bool
	{
		$key    = (string) $primaryId;
		$oldRow = $this->getRow($table, $primaryId);

		if (null === $oldRow) {
			return false;
		}

		// Merge updates.
		$newRow = \array_merge($oldRow, $updates);

		// Cache it.
		$this->cache[$table][$key] = $newRow;
		$this->dirty[$table][$key] = true;

		// Update indexes.
		$this->index->updateRow($table, $primaryId, $oldRow, $newRow);

		return true;
	}

	/**
	 * Delete a row.
	 *
	 * @param string     $table     The table name.
	 * @param int|string $primaryId The primary key value.
	 * @return bool True if row was deleted.
	 */
	public function deleteRow(string $table, int|string $primaryId): bool
	{
		$key = (string) $primaryId;
		$row = $this->getRow($table, $primaryId);

		if (null === $row) {
			return false;
		}

		// Mark as deleted.
		$this->deleted[$table][$key] = true;

		// Remove from cache.
		unset($this->cache[$table][$key]);
		unset($this->dirty[$table][$key]);

		// Update indexes.
		$this->index->removeRow($table, $primaryId, $row);

		return true;
	}

	/**
	 * Create a new table.
	 *
	 * @param string               $table  The table name.
	 * @param array<string, mixed> $schema The table schema.
	 * @return bool True on success.
	 */
	public function createTable(string $table, array $schema): bool
	{
		// Create table directory.
		$dir = $this->getTablePath($table);
		if (!\is_dir($dir)) {
			\mkdir($dir, 0755, true);
		}

		// Save schema.
		return $this->schema->saveSchema($table, $schema);
	}

	/**
	 * Drop a table.
	 *
	 * @param string $table The table name.
	 * @return bool True on success.
	 */
	public function dropTable(string $table): bool
	{
		// Delete all rows.
		$dir = $this->getTablePath($table);
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

		// Delete schema and metadata.
		$this->schema->deleteSchema($table);
		$this->schema->deleteMetadata($table);

		// Drop indexes.
		$this->index->dropTable($table);

		// Clear cache.
		unset($this->cache[$table]);
		unset($this->dirty[$table]);
		unset($this->deleted[$table]);

		return true;
	}

	/**
	 * Truncate a table (delete all rows but keep schema).
	 *
	 * @param string $table The table name.
	 * @return bool True on success.
	 */
	public function truncateTable(string $table): bool
	{
		// Delete all rows.
		$dir = $this->getTablePath($table);
		if (\is_dir($dir)) {
			$files = \scandir($dir);
			if (false !== $files) {
				foreach ($files as $file) {
					if ('.' !== $file && '..' !== $file) {
						\unlink($dir . '/' . $file);
					}
				}
			}
		}

		// Reset auto-increment.
		$this->schema->updateAutoIncrement($table, 0);

		// Drop indexes.
		$this->index->dropTable($table);

		// Clear cache.
		unset($this->cache[$table]);
		unset($this->dirty[$table]);
		unset($this->deleted[$table]);

		return true;
	}

	/**
	 * Flush all pending changes to disk.
	 *
	 * @return void
	 */
	public function flush(): void
	{
		// Write dirty rows.
		foreach ($this->dirty as $table => $rows) {
			foreach (\array_keys($rows) as $key) {
				if (isset($this->cache[$table][$key])) {
					$row      = $this->cache[$table][$key];
					$stored   = $this->serialization->processForStorage($row);
					$path     = $this->getRowPath($table, $key);
					$this->format->write($path, $stored);
				}
			}
		}

		// Delete rows.
		foreach ($this->deleted as $table => $rows) {
			foreach (\array_keys($rows) as $key) {
				$path = $this->getRowPath($table, $key);
				$this->format->delete($path);
			}
		}

		// Flush indexes.
		$this->index->flush();

		// Clear dirty/deleted flags.
		$this->dirty   = [];
		$this->deleted = [];
	}

	/**
	 * Get the path to a table's directory.
	 *
	 * @param string $table The table name.
	 * @return string The directory path.
	 */
	protected function getTablePath(string $table): string
	{
		return $this->basePath . '/tables/' . $table;
	}

	/**
	 * Get the path to a row file.
	 *
	 * @param string     $table     The table name.
	 * @param int|string $primaryId The primary key value.
	 * @return string The file path.
	 */
	protected function getRowPath(string $table, int|string $primaryId): string
	{
		$dir = $this->getTablePath($table);
		if (!\is_dir($dir)) {
			\mkdir($dir, 0755, true);
		}

		return $this->format->buildPath($dir . '/' . $primaryId);
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
	 * Clear the in-memory cache.
	 *
	 * @return void
	 */
	public function clearCache(): void
	{
		$this->cache   = [];
		$this->dirty   = [];
		$this->deleted = [];
	}
}
