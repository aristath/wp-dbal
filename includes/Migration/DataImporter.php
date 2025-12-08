<?php

/**
 * Data Importer
 *
 * Imports data to target database in batches.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

use Doctrine\DBAL\Connection;

/**
 * Data Importer class.
 */
class DataImporter
{
	/**
	 * Import a chunk of data to a table.
	 *
	 * @param Connection $connection Target database connection.
	 * @param string $table Table name.
	 * @param array<int, array<string, mixed>> $rows Rows to import.
	 * @return void
	 */
	public function importTableChunk(Connection $connection, string $table, array $rows): void
	{
		if (empty($rows)) {
			return;
		}

		// Get table schema to identify auto-increment and NOT NULL columns.
		$schemaManager = $connection->createSchemaManager();
		$schema = $schemaManager->introspectSchema();
		$autoIncrementColumns = [];
		$notNullColumns = [];
		$primaryKeyColumns = [];
		$columnTypes = [];
		
		if ($schema->hasTable($table)) {
			$tableObj = $schema->getTable($table);
			
			// Get primary key columns from indexes.
			foreach ($tableObj->getIndexes() as $index) {
				if ($index->isPrimary()) {
					$primaryKeyColumns = $index->getColumns();
					break;
				}
			}
			
			\error_log(\sprintf('DEBUG DataImporter: Table %s - Primary key columns: %s', $table, \var_export($primaryKeyColumns, true)));
			
			foreach ($tableObj->getColumns() as $column) {
				$columnName = $column->getName();
				
				// Get column type once.
				$type = $column->getType();
				try {
					$typeName = \Doctrine\DBAL\Types\Type::getTypeRegistry()->lookupName($type);
				} catch (\Exception $e) {
					// Fallback: extract from class name.
					$className = \get_class($type);
					$typeName = \strtolower(\substr($className, \strrpos($className, '\\') + 1, -4));
				}
				$columnTypes[$columnName] = $typeName;
				
				if ($column->getAutoincrement()) {
					$autoIncrementColumns[] = $columnName;
					\error_log(\sprintf('DEBUG DataImporter: Column %s.%s is auto-increment (explicit)', $table, $columnName));
				} elseif (\in_array($columnName, $primaryKeyColumns, true)) {
					// Also check if it's a primary key with integer type (SQLite INTEGER PRIMARY KEY is auto-increment).
					$lowerTypeName = \strtolower($typeName);
					if (\str_contains($lowerTypeName, 'int') || \str_contains($lowerTypeName, 'integer')) {
						$autoIncrementColumns[] = $columnName;
						\error_log(\sprintf('DEBUG DataImporter: Column %s.%s is auto-increment (INTEGER PRIMARY KEY), type: %s', $table, $columnName, $typeName));
					} else {
						\error_log(\sprintf('DEBUG DataImporter: Column %s.%s is PRIMARY KEY (non-integer), type: %s', $table, $columnName, $typeName));
					}
				}
				
				// Track NOT NULL columns without defaults.
				if ($column->getNotnull() && $column->getDefault() === null) {
					$notNullColumns[] = $columnName;
					\error_log(\sprintf('DEBUG DataImporter: Column %s.%s is NOT NULL without default', $table, $columnName));
				}
			}
			
			\error_log(\sprintf('DEBUG DataImporter: Table %s - Auto-increment columns: %s', $table, \var_export($autoIncrementColumns, true)));
			\error_log(\sprintf('DEBUG DataImporter: Table %s - NOT NULL columns: %s', $table, \var_export($notNullColumns, true)));
		}

		// Get all possible columns from all rows.
		$allColumns = [];
		foreach ($rows as $row) {
			$allColumns = \array_merge($allColumns, \array_keys($row));
		}
		$allColumns = \array_unique($allColumns);
		
		// Ensure PRIMARY KEY columns are always included, even if missing from source data.
		foreach ($primaryKeyColumns as $pkColumn) {
			if (!\in_array($pkColumn, $allColumns, true)) {
				$allColumns[] = $pkColumn;
			}
		}
		
		// Ensure NOT NULL columns are always included, even if missing from source data.
		foreach ($notNullColumns as $nnColumn) {
			if (!\in_array($nnColumn, $allColumns, true)) {
				$allColumns[] = $nnColumn;
			}
		}

		// Insert rows in a transaction.
		$connection->beginTransaction();
		
		try {
			foreach ($rows as $row) {
				// For each row, determine which columns to include.
				// Exclude auto-increment columns that are NULL.
				// Handle NOT NULL columns without defaults.
				$columns = [];
				$values = [];
				
				foreach ($allColumns as $column) {
					$value = $row[$column] ?? null;
					
					// Skip auto-increment columns with NULL values (let DB generate them).
					if (\in_array($column, $autoIncrementColumns, true) && $value === null) {
						\error_log(\sprintf('DEBUG DataImporter: Skipping auto-increment column %s.%s (NULL value)', $table, $column));
						continue;
					}
					
					// Handle PRIMARY KEY columns with NULL values.
					if (\in_array($column, $primaryKeyColumns, true) && $value === null) {
						// For INTEGER PRIMARY KEY, skip (already handled as auto-increment).
						// For VARCHAR/STRING PRIMARY KEY, generate unique identifier.
						$typeName = $columnTypes[$column] ?? 'string';
						$lowerTypeName = \strtolower($typeName);
						if (! \str_contains($lowerTypeName, 'int') && ! \str_contains($lowerTypeName, 'integer')) {
							// String/VARCHAR PRIMARY KEY - generate UUID.
							$value = $this->generateUniqueId();
							\error_log(\sprintf('DEBUG DataImporter: Generated UUID for PRIMARY KEY column %s.%s: %s (type: %s)', $table, $column, $value, $typeName));
						} else {
							// Integer PRIMARY KEY should be auto-increment, skip it.
							\error_log(\sprintf('DEBUG DataImporter: Skipping INTEGER PRIMARY KEY column %s.%s (auto-increment)', $table, $column));
							continue;
						}
					}
					
					// Handle NOT NULL columns without defaults (non-PRIMARY KEY).
					if (\in_array($column, $notNullColumns, true) && ! \in_array($column, $primaryKeyColumns, true) && $value === null) {
						// Provide default value based on column type.
						$typeName = $columnTypes[$column] ?? 'string';
						$value = $this->getDefaultValueForType($typeName);
						\error_log(\sprintf('DEBUG DataImporter: Provided default value for NOT NULL column %s.%s: %s (type: %s)', $table, $column, \var_export($value, true), $typeName));
					}
					
					// Validate: Never include a NOT NULL column with NULL value.
					if (\in_array($column, $notNullColumns, true) && $value === null) {
						\error_log(\sprintf('ERROR DataImporter: Column %s.%s is NOT NULL but has NULL value after processing. PK: %s, AutoInc: %s, Type: %s', 
							$table, $column, 
							\in_array($column, $primaryKeyColumns, true) ? 'yes' : 'no',
							\in_array($column, $autoIncrementColumns, true) ? 'yes' : 'no',
							$columnTypes[$column] ?? 'unknown'
						));
						throw new \RuntimeException(
							\sprintf(
								'Column "%s" in table "%s" is NOT NULL but has NULL value. This should not happen.',
								$column,
								$table
							)
						);
					}
					
					$columns[] = $column;
					$values[] = $value;
				}
				
				\error_log(\sprintf('DEBUG DataImporter: Row for table %s - Columns: %s, Values: %s', $table, \var_export($columns, true), \var_export($values, true)));
				
				if (empty($columns)) {
					// All columns are auto-increment with NULL, skip this row.
					continue;
				}
				
				// Build INSERT query manually to ensure correct parameter binding.
				$platform = $connection->getDatabasePlatform();
				$quotedTable = $platform->quoteIdentifier($table);
				$quotedColumns = \array_map(
					fn($col) => $platform->quoteIdentifier($col),
					$columns
				);
				$placeholders = \implode(', ', \array_fill(0, \count($columns), '?'));
				
				// Detect platform name by checking the class name.
				$platformClass = \get_class($platform);
				$isSqlite = \str_contains($platformClass, 'SQLite');
				
				// Use INSERT OR IGNORE for SQLite to handle duplicate key errors gracefully.
				$insertKeyword = 'INSERT';
				if ($isSqlite) {
					$insertKeyword = 'INSERT OR IGNORE';
				}
				
				$sql = \sprintf(
					'%s INTO %s (%s) VALUES (%s)',
					$insertKeyword,
					$quotedTable,
					\implode(', ', $quotedColumns),
					$placeholders
				);
				
				\error_log(\sprintf('DEBUG DataImporter: SQL for table %s: %s', $table, $sql));
				\error_log(\sprintf('DEBUG DataImporter: Binding %d values to %d placeholders', \count($values), \count($columns)));
				
				// Log each value being bound for debugging.
				foreach ($columns as $idx => $col) {
					$valueToLog = $values[$idx] ?? 'MISSING';
					if (\is_string($valueToLog) && \strlen($valueToLog) > 100) {
						$valueToLog = \substr($valueToLog, 0, 100) . '... (truncated)';
					}
					\error_log(\sprintf('DEBUG DataImporter: Column %s (index %d) = %s', $col, $idx, \var_export($valueToLog, true)));
				}
				
				// Use executeStatement with parameters array - DBAL will handle binding.
				$connection->executeStatement($sql, $values);
			}
			
			$connection->commit();
		} catch (\Exception $e) {
			$connection->rollBack();
			throw $e;
		}
	}

	/**
	 * Get default value for a NOT NULL column based on its type.
	 *
	 * @param string $typeName Column type name.
	 * @return mixed Default value.
	 */
	private function getDefaultValueForType(string $typeName): mixed
	{
		// Normalize type name to lowercase.
		$typeName = \strtolower($typeName);
		
		// Map type names to default values.
		return match (true) {
			// Integer types.
			\str_contains($typeName, 'int') || \str_contains($typeName, 'integer') => 0,
			
			// Floating point types.
			\str_contains($typeName, 'float') || \str_contains($typeName, 'double') || \str_contains($typeName, 'decimal') || \str_contains($typeName, 'numeric') => 0.0,
			
			// Boolean types.
			\str_contains($typeName, 'bool') => false,
			
			// Date/time types.
			\str_contains($typeName, 'date') || \str_contains($typeName, 'time') || \str_contains($typeName, 'timestamp') => '0000-00-00 00:00:00',
			
			// String types (default).
			default => '',
		};
	}

	/**
	 * Generate a unique identifier for PRIMARY KEY columns.
	 *
	 * @return string Unique identifier.
	 */
	private function generateUniqueId(): string
	{
		// Use uniqid with more entropy for better uniqueness.
		// Format: prefix + uniqid + random bytes (hex).
		return \uniqid('', true) . \bin2hex(\random_bytes(4));
	}
}

