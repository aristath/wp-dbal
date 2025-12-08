<?php

/**
 * Delete Executor - Executes DELETE queries against file storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Executes DELETE statements against file-based storage.
 */
class DeleteExecutor
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
	 * Execute a DELETE statement.
	 *
	 * @param DeleteStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function execute(DeleteStatement $stmt): Result
	{
		// Get table.
		$table = $stmt->from[0]->table ?? '';
		if ('' === $table) {
			return new Result([], 0);
		}

		// Get all rows.
		$rows = $this->storage->getAllRows($table);

		// Get primary key columns.
		$pk = $this->storage->getSchemaManager()->getPrimaryKey($table);

		// Find rows to delete.
		$toDelete = [];
		$limit = $stmt->limit ? (int) $stmt->limit->rowCount : null;

		foreach ($rows as $row) {
			// Check limit.
			if (null !== $limit && \count($toDelete) >= $limit) {
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
			$toDelete[] = $primaryId;
		}

		// Delete the rows.
		$deletedCount = 0;
		foreach ($toDelete as $primaryId) {
			if ($this->storage->deleteRow($table, $primaryId)) {
				$deletedCount++;
			}
		}

		// Flush changes.
		$this->storage->flush();

		return new Result([], $deletedCount);
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
