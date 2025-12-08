<?php

/**
 * Schema Exporter
 *
 * Exports table schemas from source database.
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
 * Schema Exporter class.
 */
class SchemaExporter
{
	/**
	 * Export schemas for tables.
	 *
	 * @param Connection $connection Source database connection.
	 * @param array<int, string> $tables Table names.
	 * @return array<string, array<string, mixed>> Array of schema definitions keyed by table name.
	 */
	public function exportSchemas(Connection $connection, array $tables): array
	{
		$schemas = [];
		$schemaManager = $connection->createSchemaManager();
		$schema = $schemaManager->introspectSchema();

		foreach ($tables as $tableName) {
			if (! $schema->hasTable($tableName)) {
				continue;
			}

			$table = $schema->getTable($tableName);
			$schemas[$tableName] = $this->exportTableSchema($table);
		}

		return $schemas;
	}

	/**
	 * Export a single table schema to portable format.
	 *
	 * @param Table $table Table schema.
	 * @return array<string, mixed> Schema definition.
	 */
	private function exportTableSchema(Table $table): array
	{
		$schema = [
			'name' => $table->getName(),
			'columns' => [],
			'indexes' => [],
			'foreignKeys' => [],
		];

		// Export columns.
		foreach ($table->getColumns() as $column) {
			$type = $column->getType();
			// Get type name - in DBAL 3.x, use TypeRegistry to lookup name.
			$typeName = 'string'; // Default fallback.
			try {
				$typeName = Type::getTypeRegistry()->lookupName($type);
			} catch (\Exception $e) {
				// Fallback: try to get name from class if available.
				if (\method_exists($type, 'getName')) {
					$typeName = $type->getName();
				} else {
					// Extract type name from class name as last resort.
					$className = \get_class($type);
					$typeName = \strtolower(\substr($className, \strrpos($className, '\\') + 1, -4)); // Remove namespace and 'Type' suffix.
				}
			}

			$schema['columns'][$column->getName()] = [
				'name' => $column->getName(),
				'type' => $typeName,
				'length' => $column->getLength(),
				'precision' => $column->getPrecision(),
				'scale' => $column->getScale(),
				'notnull' => $column->getNotnull(),
				'default' => $column->getDefault(),
				'autoincrement' => $column->getAutoincrement(),
				'comment' => $column->getComment(),
			];
		}

		// Export indexes.
		foreach ($table->getIndexes() as $index) {
			$schema['indexes'][$index->getName()] = [
				'name' => $index->getName(),
				'columns' => $index->getColumns(),
				'unique' => $index->isUnique(),
				'primary' => $index->isPrimary(),
			];
		}

		// Export foreign keys.
		foreach ($table->getForeignKeys() as $fk) {
			$schema['foreignKeys'][$fk->getName()] = [
				'name' => $fk->getName(),
				'localColumns' => $fk->getLocalColumns(),
				'foreignTable' => $fk->getForeignTableName(),
				'foreignColumns' => $fk->getForeignColumns(),
				'onUpdate' => $fk->onUpdate(),
				'onDelete' => $fk->onDelete(),
			];
		}

		return $schema;
	}
}

