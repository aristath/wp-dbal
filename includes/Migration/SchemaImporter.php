<?php

/**
 * Schema Importer
 *
 * Imports table schemas to target database.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;

/**
 * Schema Importer class.
 */
class SchemaImporter
{
	/**
	 * Import schemas to target database.
	 *
	 * @param Connection $connection Target database connection.
	 * @param array<string, array<string, mixed>> $schemas Schema definitions.
	 * @param string $targetEngine Target database engine.
	 * @return void
	 */
	public function importSchemas(Connection $connection, array $schemas, string $targetEngine): void
	{
		$targetSchema = new Schema();
		$platform = $connection->getDatabasePlatform();
		$schemaManager = $connection->createSchemaManager();

		// Get list of existing tables in the target database.
		$existingTables = [];
		try {
			$existingTables = $schemaManager->listTableNames();
		} catch (\Exception $e) {
			// If we can't list tables, continue and let the error handling catch it.
			\error_log(\sprintf('SchemaImporter: Could not list existing tables: %s', $e->getMessage()));
		}

		$isSqliteBased = \in_array(\strtolower($targetEngine), ['sqlite', 'd1'], true);
		
		// Process each table individually: drop → create → move to next.
		// This ensures each table is fully processed before moving to the next one.
		foreach ($schemas as $tableName => $schemaDef) {
			\error_log(\sprintf('SchemaImporter: Processing table %s', $tableName));
			
			// Step 1: Check if table exists in target database and drop it if it does.
			if (\in_array($tableName, $existingTables, true)) {
				try {
					// Table exists - drop it completely before recreating.
					// Dropping a table automatically drops all its indexes, constraints, triggers, etc.
					$quotedTableName = $platform->quoteIdentifier($tableName);
					
					// For SQLite/D1, use DROP TABLE IF EXISTS (safe, drops everything).
					// For other databases, use DROP TABLE (IF EXISTS may not be supported).
					$dropSql = $isSqliteBased 
						? \sprintf('DROP TABLE IF EXISTS %s', $quotedTableName)
						: \sprintf('DROP TABLE %s', $quotedTableName);
					
					\error_log(\sprintf('SchemaImporter: Table %s exists in target DB, dropping it completely', $tableName));
					$connection->executeStatement($dropSql);
					\error_log(\sprintf('SchemaImporter: Successfully dropped table %s', $tableName));
					
					// Remove from existing tables list so we don't try to drop it again.
					$existingTables = \array_values(\array_filter($existingTables, function($name) use ($tableName) {
						return $name !== $tableName;
					}));
				} catch (\Doctrine\DBAL\Exception $e) {
					// If drop fails, log the error but continue.
					// We'll try to create the table anyway, and error handling will catch conflicts.
					\error_log(\sprintf('SchemaImporter: Failed to drop existing table %s: %s', $tableName, $e->getMessage()));
				}
			}
			
			// Step 2: Create a fresh schema object for this single table.
			$tableSchema = new Schema();
			$this->createTableFromDefinition($tableSchema, $schemaDef, $targetEngine);
			
			// Step 3: Generate SQL for this single table and execute it immediately.
			$queries = $tableSchema->toSql($platform);
			
			\error_log(\sprintf('SchemaImporter: Generated %d SQL statement(s) for table %s', \count($queries), $tableName));
			
			foreach ($queries as $query) {
				try {
					\error_log(\sprintf('SchemaImporter: Executing SQL for table %s: %s', $tableName, \substr($query, 0, 100)));
					$connection->executeStatement($query);
					\error_log(\sprintf('SchemaImporter: Successfully executed SQL for table %s', $tableName));
				} catch (\Doctrine\DBAL\Exception $e) {
					// For SQLite and D1 (which is SQLite-based), check if it's an "already exists" error and continue.
					// This is a fallback in case the table drop didn't work.
					// For other databases, re-throw the exception.
					$errorMessage = $e->getMessage();
					
					\error_log(\sprintf('SchemaImporter: Caught exception for table %s, query: %s | Error: %s', $tableName, \substr($query, 0, 100), $errorMessage));
					
					if ($isSqliteBased) {
						// Check for various "already exists" error patterns.
						// D1 API wraps errors, so check for both the wrapped format and direct SQLite errors.
						// Make pattern very permissive - if "already exists" appears anywhere, treat it as such.
						$isAlreadyExists = (
							\stripos($errorMessage, 'already exists') !== false ||
							\stripos($errorMessage, 'duplicate') !== false ||
							\stripos($errorMessage, 'SQLITE_ERROR') !== false
						);
						
						if ($isAlreadyExists) {
							// Index or table already exists, skip it.
							\error_log(\sprintf('SchemaImporter: Skipping already exists error for table %s (SQLite/D1): %s', $tableName, $errorMessage));
							continue;
						}
					}
					
					// Re-throw if it's not an "already exists" error or not SQLite-based.
					\error_log(\sprintf('SchemaImporter: Re-throwing exception for table %s (not already exists or not SQLite/D1): %s', $tableName, $errorMessage));
					throw $e;
				}
			}
			
			\error_log(\sprintf('SchemaImporter: Completed processing table %s', $tableName));
		}
	}

	/**
	 * Create a Table object from schema definition.
	 *
	 * @param Schema $schema Schema object.
	 * @param array<string, mixed> $schemaDef Schema definition.
	 * @param string $targetEngine Target database engine.
	 * @return Table Table object.
	 */
	private function createTableFromDefinition(Schema $schema, array $schemaDef, string $targetEngine): Table
	{
		$table = $schema->createTable($schemaDef['name']);
		$isSqliteBased = \in_array(\strtolower($targetEngine), ['sqlite', 'd1'], true);

		// Add columns.
		foreach ($schemaDef['columns'] as $columnDef) {
			$typeName = $this->mapColumnType($columnDef['type'], $targetEngine, $columnDef['name'] ?? '');
			
			// Build column options.
			$options = [
				'notnull' => $columnDef['notnull'] ?? true,
				'default' => $columnDef['default'] ?? null,
				'autoincrement' => $this->shouldAutoIncrement($columnDef, $targetEngine),
			];
			
			// Handle length for string types - provide default if null.
			$length = $columnDef['length'] ?? null;
			if ('string' === $typeName) {
				if (null === $length) {
					// Default length for string columns without explicit length.
					$length = 255;
				}
				$options['length'] = $length;
			} else {
				// For non-string types, only add length if explicitly provided.
				if (null !== $length) {
					$options['length'] = $length;
				}
			}
			
			// Add precision and scale if provided.
			if (null !== ($columnDef['precision'] ?? null)) {
				$options['precision'] = $columnDef['precision'];
			}
			if (null !== ($columnDef['scale'] ?? null)) {
				$options['scale'] = $columnDef['scale'];
			}
			
			// Add comment if provided.
			if (null !== ($columnDef['comment'] ?? null)) {
				$options['comment'] = $columnDef['comment'];
			}
			
			$column = $table->addColumn(
				$columnDef['name'],
				$typeName,
				$options
			);
		}

		// Add indexes.
		foreach ($schemaDef['indexes'] as $indexDef) {
			if ($indexDef['primary']) {
				$table->setPrimaryKey($indexDef['columns']);
			} else {
				// For SQLite/D1, index names must be unique across the entire database.
				// Prefix the index name with the table name to ensure uniqueness.
				$indexName = $indexDef['name'];
				if ($isSqliteBased && ! \str_starts_with($indexName, $schemaDef['name'] . '_')) {
					// Prefix with table name if not already prefixed.
					$indexName = $schemaDef['name'] . '_' . $indexName;
				}
				
				if ($indexDef['unique']) {
					$table->addUniqueIndex($indexDef['columns'], $indexName);
				} else {
					$table->addIndex($indexDef['columns'], $indexName);
				}
			}
		}

		// Add foreign keys (if supported by target engine).
		if ($this->supportsForeignKeys($targetEngine)) {
			foreach ($schemaDef['foreignKeys'] as $fkDef) {
				$table->addForeignKeyConstraint(
					$fkDef['foreignTable'],
					$fkDef['localColumns'],
					$fkDef['foreignColumns'],
					[
						'onUpdate' => $fkDef['onUpdate'] ?? 'RESTRICT',
						'onDelete' => $fkDef['onDelete'] ?? 'RESTRICT',
					],
					$fkDef['name']
				);
			}
		}

		return $table;
	}

	/**
	 * Map column type for target engine.
	 *
	 * @param string $sourceType Source column type.
	 * @param string $targetEngine Target engine.
	 * @param string $columnName Column name (for context, e.g., datetime detection).
	 * @return string Mapped type.
	 */
	private function mapColumnType(string $sourceType, string $targetEngine, string $columnName = ''): string
	{
		// Basic type mapping - can be expanded.
		$typeMap = [
			'mysql' => [
				'bigint' => 'bigint',
				'int' => 'integer',
				'smallint' => 'smallint',
				'tinyint' => 'smallint',
				'varchar' => 'string',
				'text' => 'text',
				'longtext' => 'text',
				'datetime' => 'datetime',
				'timestamp' => 'datetime',
			],
			'sqlite' => [
				'bigint' => 'integer',
				'int' => 'integer',
				'smallint' => 'integer',
				'tinyint' => 'integer',
				'varchar' => 'string',
				'text' => 'text',
				'longtext' => 'text',
				'datetime' => 'string',
				'timestamp' => 'string',
			],
			'd1' => [
				// D1 is SQLite-based, so it uses the same type mappings as SQLite.
				'bigint' => 'integer',
				'int' => 'integer',
				'integer' => 'integer',
				'smallint' => 'integer',
				'tinyint' => 'integer',
				'varchar' => 'string',
				'char' => 'string',
				'text' => 'text',
				'longtext' => 'text',
				'mediumtext' => 'text',
				'tinytext' => 'text',
				'datetime' => 'string',
				'timestamp' => 'string',
				'date' => 'string',
				'time' => 'string',
				'float' => 'float',
				'double' => 'float',
				'decimal' => 'string',
				'numeric' => 'string',
				'boolean' => 'integer',
				'bool' => 'integer',
				'json' => 'text',
				'blob' => 'blob',
			],
			'filedb' => [
				// FileDB extends MySQL platform, so it supports MySQL-compatible types.
				'bigint' => 'bigint',
				'int' => 'integer',
				'integer' => 'integer',
				'smallint' => 'smallint',
				'tinyint' => 'smallint',
				'varchar' => 'string',
				'char' => 'string',
				'text' => 'text',
				'longtext' => 'text',
				'mediumtext' => 'text',
				'tinytext' => 'text',
				'datetime' => 'datetime',
				'timestamp' => 'datetime',
				'date' => 'date',
				'time' => 'time',
				'float' => 'float',
				'double' => 'float',
				'decimal' => 'decimal',
				'numeric' => 'decimal',
				'boolean' => 'boolean',
				'bool' => 'boolean',
				'json' => 'json',
				'blob' => 'blob',
				'longblob' => 'blob',
				'mediumblob' => 'blob',
				'tinyblob' => 'blob',
			],
		];

		$normalizedType = \strtolower($sourceType);
		$normalizedColumnName = \strtolower($columnName);
		
		// Special handling: SQLite stores datetime as string, but column names can indicate datetime.
		// If migrating from SQLite and column name suggests datetime, map to datetime for MySQL-compatible engines.
		if (
			'string' === $normalizedType &&
			'' !== $normalizedColumnName &&
			\in_array($targetEngine, ['filedb', 'mysql'], true) &&
			(
				\str_ends_with($normalizedColumnName, '_at') ||
				\str_ends_with($normalizedColumnName, '_date') ||
				\str_ends_with($normalizedColumnName, '_time') ||
				\str_contains($normalizedColumnName, 'created') ||
				\str_contains($normalizedColumnName, 'updated') ||
				\str_contains($normalizedColumnName, 'deleted')
			)
		) {
			return 'datetime';
		}
		
		if (isset($typeMap[$targetEngine][$normalizedType])) {
			return $typeMap[$targetEngine][$normalizedType];
		}

		// Default fallback.
		return 'string';
	}

	/**
	 * Check if column should auto-increment.
	 *
	 * @param array<string, mixed> $columnDef Column definition.
	 * @param string $targetEngine Target engine.
	 * @return bool Whether to auto-increment.
	 */
	private function shouldAutoIncrement(array $columnDef, string $targetEngine): bool
	{
		if (! ($columnDef['autoincrement'] ?? false)) {
			return false;
		}

		// SQLite and D1 (SQLite-based) use INTEGER PRIMARY KEY for auto-increment.
		if (\in_array($targetEngine, ['sqlite', 'd1'], true)) {
			return true;
		}

		// PostgreSQL uses SERIAL.
		if ('pgsql' === $targetEngine || 'postgresql' === $targetEngine) {
			return true;
		}

		return true;
	}

	/**
	 * Check if target engine supports foreign keys.
	 *
	 * @param string $targetEngine Target engine.
	 * @return bool Whether foreign keys are supported.
	 */
	private function supportsForeignKeys(string $targetEngine): bool
	{
		// FileDB and D1 may not support foreign keys.
		return ! \in_array($targetEngine, [ 'filedb', 'd1' ], true);
	}
}

