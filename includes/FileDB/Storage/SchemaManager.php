<?php

/**
 * Schema Manager - Manages table schemas and metadata.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Storage;

use WP_DBAL\FileDB\Util\FormatHandler;

/**
 * Manages table schemas and metadata for file-based storage.
 *
 * Handles:
 * - Table schema definitions (columns, types, constraints)
 * - Auto-increment values and other metadata
 * - Primary key information
 */
class SchemaManager
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
	 * Cached schemas.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $schemas = [];

	/**
	 * Cached metadata.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $metadata = [];

	/**
	 * Constructor.
	 *
	 * @param string        $basePath The base storage path.
	 * @param FormatHandler $format   The format handler.
	 */
	public function __construct(string $basePath, FormatHandler $format)
	{
		$this->basePath = $basePath;
		$this->format   = $format;

		$this->ensureDirectories();
	}

	/**
	 * Ensure required directories exist.
	 *
	 * @return void
	 */
	protected function ensureDirectories(): void
	{
		$dirs = [
			$this->basePath . '/_schema',
			$this->basePath . '/_meta',
		];

		foreach ($dirs as $dir) {
			if (!\is_dir($dir)) {
				\mkdir($dir, 0755, true);
			}
		}
	}

	/**
	 * Get a table's schema.
	 *
	 * @param string $table The table name.
	 * @return array<string, mixed>|null The schema or null if not found.
	 */
	public function getSchema(string $table): ?array
	{
		if (isset($this->schemas[$table])) {
			return $this->schemas[$table];
		}

		$path = $this->format->buildPath($this->basePath . '/_schema/' . $table);
		$schema = $this->format->read($path);

		if (null !== $schema) {
			$this->schemas[$table] = $schema;
		}

		return $schema;
	}

	/**
	 * Save a table's schema.
	 *
	 * @param string               $table  The table name.
	 * @param array<string, mixed> $schema The schema definition.
	 * @return bool True on success.
	 */
	public function saveSchema(string $table, array $schema): bool
	{
		$path = $this->format->buildPath($this->basePath . '/_schema/' . $table);
		$result = $this->format->write($path, $schema);

		if ($result) {
			$this->schemas[$table] = $schema;
		}

		return $result;
	}

	/**
	 * Delete a table's schema.
	 *
	 * @param string $table The table name.
	 * @return bool True on success.
	 */
	public function deleteSchema(string $table): bool
	{
		$path = $this->format->buildPath($this->basePath . '/_schema/' . $table);
		$result = $this->format->delete($path);

		if ($result) {
			unset($this->schemas[$table]);
		}

		return $result;
	}

	/**
	 * Get a table's metadata.
	 *
	 * @param string $table The table name.
	 * @return array<string, mixed> The metadata (empty array if not found).
	 */
	public function getMetadata(string $table): array
	{
		if (isset($this->metadata[$table])) {
			return $this->metadata[$table];
		}

		$path = $this->format->buildPath($this->basePath . '/_meta/' . $table);
		$meta = $this->format->read($path);

		$this->metadata[$table] = $meta ?? [
			'autoIncrement' => 0,
			'rowCount'      => 0,
		];

		return $this->metadata[$table];
	}

	/**
	 * Save a table's metadata.
	 *
	 * @param string               $table    The table name.
	 * @param array<string, mixed> $metadata The metadata.
	 * @return bool True on success.
	 */
	public function saveMetadata(string $table, array $metadata): bool
	{
		$path = $this->format->buildPath($this->basePath . '/_meta/' . $table);
		$result = $this->format->write($path, $metadata);

		if ($result) {
			$this->metadata[$table] = $metadata;
		}

		return $result;
	}

	/**
	 * Delete a table's metadata.
	 *
	 * @param string $table The table name.
	 * @return bool True on success.
	 */
	public function deleteMetadata(string $table): bool
	{
		$path = $this->format->buildPath($this->basePath . '/_meta/' . $table);
		$result = $this->format->delete($path);

		if ($result) {
			unset($this->metadata[$table]);
		}

		return $result;
	}

	/**
	 * Get the next auto-increment value for a table.
	 *
	 * @param string $table The table name.
	 * @return int The next auto-increment value.
	 */
	public function getNextAutoIncrement(string $table): int
	{
		$meta = $this->getMetadata($table);
		return (int) ($meta['autoIncrement'] ?? 0) + 1;
	}

	/**
	 * Update the auto-increment value for a table.
	 *
	 * @param string $table The table name.
	 * @param int    $value The new value.
	 * @return bool True on success.
	 */
	public function updateAutoIncrement(string $table, int $value): bool
	{
		$meta = $this->getMetadata($table);
		$meta['autoIncrement'] = $value;
		return $this->saveMetadata($table, $meta);
	}

	/**
	 * Get the primary key column(s) for a table.
	 *
	 * @param string $table The table name.
	 * @return list<string> The primary key column names.
	 */
	public function getPrimaryKey(string $table): array
	{
		$schema = $this->getSchema($table);

		if (null === $schema) {
			return $this->inferPrimaryKey($table);
		}

		$pk = $schema['primaryKey'] ?? [];

		// If schema exists but primaryKey is empty, fall back to inference.
		// This handles cases where schema was created without primaryKey info.
		if (empty($pk)) {
			return $this->inferPrimaryKey($table);
		}

		return $pk;
	}

	/**
	 * Infer primary key from common WordPress patterns.
	 *
	 * @param string $table The table name.
	 * @return list<string> The inferred primary key columns.
	 */
	protected function inferPrimaryKey(string $table): array
	{
		// Common WordPress and plugin primary keys.
		$patterns = [
			// WordPress core tables.
			'_posts'              => ['ID'],
			'_postmeta'           => ['meta_id'],
			'_users'              => ['ID'],
			'_usermeta'           => ['umeta_id'],
			'_options'            => ['option_id'],
			'_terms'              => ['term_id'],
			'_term_taxonomy'      => ['term_taxonomy_id'],
			'_term_relationships' => ['object_id', 'term_taxonomy_id'],
			'_comments'           => ['comment_ID'],
			'_commentmeta'        => ['meta_id'],
			'_links'              => ['link_id'],
			'_termmeta'           => ['meta_id'],

			// Action Scheduler tables.
			'_actionscheduler_actions' => ['action_id'],
			'_actionscheduler_claims'  => ['claim_id'],
			'_actionscheduler_groups'  => ['group_id'],
			'_actionscheduler_logs'    => ['log_id'],
		];

		foreach ($patterns as $suffix => $pk) {
			if (\str_ends_with($table, $suffix)) {
				return $pk;
			}
		}

		// Default to 'id' or 'ID'.
		return ['id'];
	}

	/**
	 * Check if a table exists.
	 *
	 * @param string $table The table name.
	 * @return bool True if table exists.
	 */
	public function tableExists(string $table): bool
	{
		$schemaPath = $this->format->buildPath($this->basePath . '/_schema/' . $table);
		$tablePath  = $this->basePath . '/tables/' . $table;

		return $this->format->exists($schemaPath) || \is_dir($tablePath);
	}

	/**
	 * List all tables.
	 *
	 * @return list<string> List of table names.
	 */
	public function listTables(): array
	{
		$tables = [];

		// Check schema directory.
		$schemaDir = $this->basePath . '/_schema';
		if (\is_dir($schemaDir)) {
			$files = \scandir($schemaDir);
			if (false !== $files) {
				foreach ($files as $file) {
					if ('.' !== $file && '..' !== $file) {
						// Remove extension.
						$table = \pathinfo($file, \PATHINFO_FILENAME);
						$tables[$table] = true;
					}
				}
			}
		}

		// Check tables directory.
		$tablesDir = $this->basePath . '/tables';
		if (\is_dir($tablesDir)) {
			$dirs = \scandir($tablesDir);
			if (false !== $dirs) {
				foreach ($dirs as $dir) {
					if ('.' !== $dir && '..' !== $dir && \is_dir($tablesDir . '/' . $dir)) {
						$tables[$dir] = true;
					}
				}
			}
		}

		return \array_keys($tables);
	}

	/**
	 * Flush cached data.
	 *
	 * @return void
	 */
	public function flush(): void
	{
		$this->schemas  = [];
		$this->metadata = [];
	}
}
