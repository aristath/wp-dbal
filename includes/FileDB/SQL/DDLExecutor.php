<?php

/**
 * DDL Executor - Executes DDL (CREATE, ALTER, DROP, TRUNCATE) queries.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\SqlParser\Statements\TruncateStatement;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Executes DDL statements against file-based storage.
 */
class DDLExecutor
{
	/**
	 * Storage manager instance.
	 *
	 * @var StorageManager
	 */
	protected StorageManager $storage;

	/**
	 * Constructor.
	 *
	 * @param StorageManager $storage The storage manager.
	 */
	public function __construct(StorageManager $storage)
	{
		$this->storage = $storage;
	}

	/**
	 * Execute a CREATE statement.
	 *
	 * @param CreateStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function executeCreate(CreateStatement $stmt): Result
	{
		// Only handle CREATE TABLE.
		if (!isset($stmt->name) || !$stmt->name->table) {
			return new Result([], 0);
		}

		$table = $stmt->name->table;

		// Check if IF NOT EXISTS.
		$ifNotExists = !empty($stmt->options) && $stmt->options->has('IF NOT EXISTS');

		// Check if table already exists.
		$tableExists = $this->storage->getSchemaManager()->tableExists($table);
		if ($tableExists) {
			if ($ifNotExists) {
				return new Result([], 0);
			}
			// Table already exists - in real scenario would throw error.
			return new Result([], 0);
		}

		// Build schema from column definitions.
		$schema = $this->buildSchema($stmt);

		// Create the table.
		$this->storage->createTable($table, $schema);

		return new Result([], 1);
	}

	/**
	 * Build schema from CREATE statement.
	 *
	 * @param CreateStatement $stmt The statement.
	 * @return array<string, mixed> The schema definition.
	 */
	protected function buildSchema(CreateStatement $stmt): array
	{
		$columns    = [];
		$primaryKey = [];

		if (!empty($stmt->fields)) {
			foreach ($stmt->fields as $field) {
				// Handle column definitions (has both name and type).
				if (isset($field->name) && isset($field->type)) {
					$colDef = [
						'name' => $field->name,
						'type' => $this->getColumnType($field->type),
					];

					// Check for options.
					if (!empty($field->options)) {
						if ($field->options->has('NOT NULL')) {
							$colDef['nullable'] = false;
						}
						if ($field->options->has('NULL')) {
							$colDef['nullable'] = true;
						}
						if ($field->options->has('AUTO_INCREMENT')) {
							$colDef['autoIncrement'] = true;
						}
						if ($field->options->has('DEFAULT')) {
							$colDef['default'] = $this->getDefaultValue($field->options);
						}
					}

					$columns[$field->name] = $colDef;
				}

				// Handle KEY constraints (standalone entries with key property).
				// These are separate entries in the fields array for:
				// PRIMARY KEY, KEY, INDEX, UNIQUE, etc.
				if (isset($field->key) && 'PRIMARY KEY' === \strtoupper($field->key->type ?? '')) {
					if (!empty($field->key->columns)) {
						foreach ($field->key->columns as $col) {
							$colName = $col['name'] ?? '';
							if ('' !== $colName) {
								$primaryKey[] = $colName;
							}
						}
					}
				}
			}
		}

		// Fallback: if no PRIMARY KEY constraint found, check for AUTO_INCREMENT column.
		if (empty($primaryKey)) {
			foreach ($columns as $name => $def) {
				if (!empty($def['autoIncrement'])) {
					$primaryKey[] = $name;
					break;
				}
			}
		}

		return [
			'columns'    => $columns,
			'primaryKey' => $primaryKey,
		];
	}

	/**
	 * Get column type from field type.
	 *
	 * @param mixed $type The field type.
	 * @return string The normalized type.
	 */
	protected function getColumnType(mixed $type): string
	{
		if (!\is_object($type)) {
			return 'varchar';
		}

		$name = \strtolower($type->name ?? 'varchar');

		// Map MySQL types to simple types.
		return match (true) {
			\in_array($name, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => 'integer',
			\in_array($name, ['float', 'double', 'decimal', 'numeric'], true) => 'float',
			\in_array($name, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true) => 'string',
			\in_array($name, ['date', 'datetime', 'timestamp', 'time', 'year'], true) => 'datetime',
			\in_array($name, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true) => 'binary',
			'boolean' === $name || 'bool' === $name => 'boolean',
			'json' === $name => 'json',
			default => 'string',
		};
	}

	/**
	 * Get default value from options.
	 *
	 * @param mixed $options The field options.
	 * @return mixed The default value.
	 */
	protected function getDefaultValue(mixed $options): mixed
	{
		// Parse options to find DEFAULT value.
		// This is simplified - real implementation would need to parse the options array.
		return null;
	}

	/**
	 * Execute an ALTER statement.
	 *
	 * @param AlterStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function executeAlter(AlterStatement $stmt): Result
	{
		$table = $stmt->table->table ?? '';
		if ('' === $table) {
			return new Result([], 0);
		}

		// Get existing schema - if table doesn't exist, skip ALTER.
		// This prevents creating empty schemas when ALTER is called before CREATE.
		$schema = $this->storage->getSchemaManager()->getSchema($table);
		if (null === $schema) {
			// Table doesn't exist yet - nothing to alter.
			return new Result([], 0);
		}

		// Process altered fields.
		if (!empty($stmt->altered)) {
			foreach ($stmt->altered as $altered) {
				$operation = \strtoupper($altered->options->options[0] ?? '');

				switch ($operation) {
					case 'ADD':
						if (isset($altered->field->name, $altered->field->type)) {
							$schema['columns'][$altered->field->name] = [
								'name' => $altered->field->name,
								'type' => $this->getColumnType($altered->field->type),
							];
						}
						break;

					case 'DROP':
						if (isset($altered->field->name)) {
							unset($schema['columns'][$altered->field->name]);
						}
						break;

					case 'MODIFY':
					case 'CHANGE':
						if (isset($altered->field->name, $altered->field->type)) {
							$schema['columns'][$altered->field->name] = [
								'name' => $altered->field->name,
								'type' => $this->getColumnType($altered->field->type),
							];
						}
						break;
				}
			}
		}

		$this->storage->getSchemaManager()->saveSchema($table, $schema);

		return new Result([], 1);
	}

	/**
	 * Execute a DROP statement.
	 *
	 * @param DropStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function executeDrop(DropStatement $stmt): Result
	{
		$droppedCount = 0;

		// Check for IF EXISTS.
		$ifExists = !empty($stmt->options) && $stmt->options->has('IF EXISTS');

		if (!empty($stmt->fields)) {
			foreach ($stmt->fields as $field) {
				$table = $field->table ?? '';
				if ('' === $table) {
					continue;
				}

				// Check if table exists.
				if (!$this->storage->getSchemaManager()->tableExists($table)) {
					if ($ifExists) {
						continue;
					}
					// Table doesn't exist - in real scenario would throw error.
					continue;
				}

				if ($this->storage->dropTable($table)) {
					$droppedCount++;
				}
			}
		}

		return new Result([], $droppedCount);
	}

	/**
	 * Execute a TRUNCATE statement.
	 *
	 * @param TruncateStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function executeTruncate(TruncateStatement $stmt): Result
	{
		$table = $stmt->table->table ?? '';
		if ('' === $table) {
			return new Result([], 0);
		}

		$this->storage->truncateTable($table);

		return new Result([], 1);
	}
}
