<?php

/**
 * Update Executor - Executes UPDATE queries against file storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Executes UPDATE statements against file-based storage.
 */
class UpdateExecutor
{
	/**
	 * Storage manager instance.
	 *
	 * @var StorageManager
	 */
	protected StorageManager $storage;

	/**
	 * Expression evaluator instance.
	 *
	 * @var ExpressionEvaluator
	 */
	protected ExpressionEvaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param StorageManager      $storage   The storage manager.
	 * @param ExpressionEvaluator $evaluator The expression evaluator.
	 */
	public function __construct(StorageManager $storage, ExpressionEvaluator $evaluator)
	{
		$this->storage   = $storage;
		$this->evaluator = $evaluator;
	}

	/**
	 * Execute an UPDATE statement.
	 *
	 * @param UpdateStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function execute(UpdateStatement $stmt): Result
	{
		// Get table.
		$table = $stmt->tables[0]->table ?? '';
		if ('' === $table) {
			return new Result([], 0);
		}

		// Get SET values.
		$updates = $this->parseSet($stmt->set ?? []);

		// Get all rows.
		$rows = $this->storage->getAllRows($table);

		// Get primary key columns.
		$pk = $this->storage->getSchemaManager()->getPrimaryKey($table);

		// Apply WHERE filter and update matching rows.
		$updatedCount = 0;
		$limit = $stmt->limit ? (int) $stmt->limit->rowCount : null;

		foreach ($rows as $row) {
			// Check limit.
			if (null !== $limit && $updatedCount >= $limit) {
				break;
			}

			// Check WHERE conditions.
			if (!empty($stmt->where)) {
				if (!$this->evaluator->evaluateWhere($stmt->where, $row, [])) {
					continue;
				}
			}

			// Get primary key value.
			$primaryId = $this->getPrimaryKeyValue($row, $pk);

			// Apply updates (evaluate expressions).
			$evaluatedUpdates = [];
			foreach ($updates as $column => $value) {
				$evaluatedUpdates[$column] = $this->evaluator->evaluateValue($value, $row, []);
			}

			// Update the row.
			if ($this->storage->updateRow($table, $primaryId, $evaluatedUpdates)) {
				$updatedCount++;
			}
		}

		// Flush changes.
		$this->storage->flush();

		return new Result([], $updatedCount);
	}

	/**
	 * Parse SET clause.
	 *
	 * @param list<mixed> $set The SET operations.
	 * @return array<string, string> Column => value expression mapping.
	 */
	protected function parseSet(array $set): array
	{
		$updates = [];

		foreach ($set as $item) {
			$column = $item->column ?? '';
			$value  = $item->value ?? '';

			if ('' !== $column) {
				// Strip backticks from column name.
				$column = \str_replace('`', '', $column);
				$updates[$column] = $value;
			}
		}

		return $updates;
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
}
