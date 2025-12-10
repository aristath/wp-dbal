<?php

/**
 * FileDB Connection - Doctrine DBAL Driver Connection for file-based storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use WP_DBAL\FileDB\SQL\QueryExecutor;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * FileDB Connection implementation.
 *
 * Manages the connection to file-based storage and routes queries
 * to the appropriate executor.
 */
class Connection implements ConnectionInterface
{
	/**
	 * Storage manager instance.
	 *
	 * @var StorageManager
	 */
	protected StorageManager $storage;

	/**
	 * Query executor instance.
	 *
	 * @var QueryExecutor
	 */
	protected QueryExecutor $executor;

	/**
	 * Connection parameters.
	 *
	 * @var array<string, mixed>
	 */
	protected array $params;

	/**
	 * Transaction nesting level.
	 *
	 * @var integer
	 */
	protected int $transactionLevel = 0;

	/**
	 * Last insert ID.
	 *
	 * @var integer|string
	 */
	protected int|string $lastInsertId = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params Connection parameters.
	 */
	public function __construct(array $params)
	{
		$this->params  = $params;
		$this->storage = new StorageManager($this->getStoragePath(), $this->getStorageFormat());
		$this->executor = new QueryExecutor($this->storage, $this);
	}

	/**
	 * Get the storage path from params or config.
	 *
	 * @return string The storage path.
	 */
	protected function getStoragePath(): string
	{
		if (isset($this->params['path'])) {
			return $this->params['path'];
		}

		if (\defined('DB_FILEDB_PATH')) {
			return DB_FILEDB_PATH;
		}

		// Default: wp-content/file-db
		if (\defined('WP_CONTENT_DIR')) {
			return WP_CONTENT_DIR . '/file-db';
		}

		return \dirname(__DIR__, 4) . '/file-db';
	}

	/**
	 * Get the storage format from params or config.
	 *
	 * @return string The storage format ('json' or 'php').
	 */
	protected function getStorageFormat(): string
	{
		if (isset($this->params['format'])) {
			return $this->params['format'];
		}

		if (\defined('DB_FILEDB_FORMAT')) {
			return DB_FILEDB_FORMAT;
		}

		return 'json';
	}

	/**
	 * Prepare a statement for execution.
	 *
	 * @param string $sql The SQL query.
	 * @return StatementInterface The prepared statement.
	 */
	public function prepare(string $sql): StatementInterface
	{
		return new Statement($sql, $this);
	}

	/**
	 * Execute a query directly and return results.
	 *
	 * @param string $sql The SQL query.
	 * @return ResultInterface The query result.
	 */
	public function query(string $sql): ResultInterface
	{
		return $this->executor->execute($sql);
	}

	/**
	 * Quote a string for use in a query.
	 *
	 * Uses SQLite-style escaping (double single quotes) which is safer
	 * and more consistent than addslashes for SQL contexts.
	 *
	 * @param string $value The value to quote.
	 * @return string The quoted value.
	 */
	public function quote(string $value): string
	{
		// Use SQLite-style escaping: single quotes are escaped by doubling them.
		// This is safer than addslashes which can have encoding issues.
		$escaped = \str_replace("'", "''", $value);

		// Also escape backslashes for consistency.
		$escaped = \str_replace('\\', '\\\\', $escaped);

		return "'" . $escaped . "'";
	}

	/**
	 * Execute a statement and return the number of affected rows.
	 *
	 * @param string $sql The SQL statement.
	 * @return int|string The number of affected rows.
	 */
	public function exec(string $sql): int|string
	{
		$result = $this->executor->execute($sql);
		return $result->rowCount();
	}

	/**
	 * Get the ID of the last inserted row.
	 *
	 * @return int|string The last insert ID.
	 */
	public function lastInsertId(): int|string
	{
		return $this->lastInsertId;
	}

	/**
	 * Set the last insert ID.
	 *
	 * @param int|string $id The ID to set.
	 * @return void
	 */
	public function setLastInsertId(int|string $id): void
	{
		$this->lastInsertId = $id;
	}

	/**
	 * Begin a transaction.
	 *
	 * Note: File-based storage has limited transaction support.
	 * We track nesting level for compatibility but don't provide
	 * true ACID transactions.
	 *
	 * @return void
	 */
	public function beginTransaction(): void
	{
		$this->transactionLevel++;
	}

	/**
	 * Commit a transaction.
	 *
	 * @return void
	 */
	public function commit(): void
	{
		if ($this->transactionLevel > 0) {
			$this->transactionLevel--;
		}

		// Flush any pending writes.
		if (0 === $this->transactionLevel) {
			$this->storage->flush();
		}
	}

	/**
	 * Roll back a transaction.
	 *
	 * Note: True rollback is not supported in file-based storage.
	 * This decrements the transaction level for compatibility.
	 *
	 * @return void
	 */
	public function rollBack(): void
	{
		if ($this->transactionLevel > 0) {
			$this->transactionLevel--;
		}
	}

	/**
	 * Get the server version.
	 *
	 * Returns a MySQL-compatible version string for WordPress compatibility.
	 * WordPress checks for MySQL 5.5.5+ during installation.
	 *
	 * @return string The server version string.
	 */
	public function getServerVersion(): string
	{
		return '8.0.0-FileDB';
	}

	/**
	 * Get the native connection.
	 *
	 * Returns the storage manager as the "native" connection.
	 *
	 * @return StorageManager The storage manager.
	 */
	public function getNativeConnection(): StorageManager
	{
		return $this->storage;
	}

	/**
	 * Get the storage manager.
	 *
	 * @return StorageManager The storage manager instance.
	 */
	public function getStorage(): StorageManager
	{
		return $this->storage;
	}

	/**
	 * Get the query executor.
	 *
	 * @return QueryExecutor The query executor instance.
	 */
	public function getExecutor(): QueryExecutor
	{
		return $this->executor;
	}

	/**
	 * Check if the connection is established.
	 *
	 * For file-based storage, this checks if the storage directory exists
	 * and is writable.
	 *
	 * @return bool True if the connection is ready to use.
	 */
	public function isConnected(): bool
	{
		// FileDB is "connected" if the storage manager is initialized
		// and the storage directory exists and is writable.
		if (!isset($this->storage)) {
			return false;
		}

		$basePath = $this->storage->getBasePath();
		return \is_dir($basePath) && \is_writable($basePath);
	}

	/**
	 * Test the connection by attempting a simple operation.
	 *
	 * @return bool True if the connection is working.
	 */
	public function testConnection(): bool
	{
		try {
			// Try to list tables (this tests read access).
			$this->storage->getSchemaManager()->listTables();
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}
}
