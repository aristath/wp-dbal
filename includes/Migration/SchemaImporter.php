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

		foreach ($schemas as $tableName => $schemaDef) {
			$this->createTableFromDefinition($targetSchema, $schemaDef, $targetEngine);
			// Note: createTable() automatically adds the table to the schema in DBAL 3.x
		}

		// Generate SQL to create tables.
		$queries = $targetSchema->toSql($platform);
		
		foreach ($queries as $query) {
			try {
				$connection->executeStatement($query);
			} catch (\Doctrine\DBAL\Exception $e) {
				// For SQLite, check if it's an "already exists" error and continue.
				// For other databases, re-throw the exception.
				$errorMessage = $e->getMessage();
				if (
					'sqlite' === \strtolower($targetEngine) &&
					(
						\strpos($errorMessage, 'already exists') !== false ||
						\strpos($errorMessage, 'duplicate') !== false
					)
				) {
					// Index or table already exists, skip it.
					continue;
				}
				// Re-throw if it's not an "already exists" error.
				throw $e;
			}
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
			} elseif ($indexDef['unique']) {
				$table->addUniqueIndex($indexDef['columns'], $indexDef['name']);
			} else {
				$table->addIndex($indexDef['columns'], $indexDef['name']);
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

		// SQLite uses INTEGER PRIMARY KEY for auto-increment.
		if ('sqlite' === $targetEngine) {
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

