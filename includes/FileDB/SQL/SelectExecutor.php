<?php

/**
 * Select Executor - Executes SELECT queries against file storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Executes SELECT statements against file-based storage.
 */
class SelectExecutor
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
	 * Execute a SELECT statement.
	 *
	 * @param SelectStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function execute(SelectStatement $stmt): Result
	{
		// Get table(s) and aliases.
		$tables  = $this->getTables($stmt);
		$aliases = $this->getAliases($stmt);

		if (empty($tables)) {
			return new Result([]);
		}

		// Get rows from primary table.
		$primaryTable = $tables[0];
		$rows = $this->storage->getAllRows($primaryTable);

		// Handle JOINs.
		if (!empty($stmt->join)) {
			$rows = $this->handleJoins($rows, $stmt, $aliases);
		}

		// Apply WHERE.
		if (!empty($stmt->where)) {
			$rows = $this->applyWhere($rows, $stmt->where, $aliases);
		}

		// Apply GROUP BY.
		if (!empty($stmt->group)) {
			$rows = $this->applyGroupBy($rows, $stmt);
		}

		// Apply HAVING.
		if (!empty($stmt->having)) {
			$rows = $this->applyWhere($rows, $stmt->having, $aliases);
		}

		// Apply ORDER BY.
		if (!empty($stmt->order)) {
			$rows = $this->applyOrderBy($rows, $stmt->order);
		}

		// Handle DISTINCT.
		if ($this->isDistinct($stmt)) {
			$rows = $this->applyDistinct($rows, $stmt);
		}

		// Apply LIMIT/OFFSET.
		if (null !== $stmt->limit) {
			$rows = $this->applyLimit($rows, $stmt->limit);
		}

		// Project columns.
		$rows = $this->projectColumns($rows, $stmt, $aliases);

		return new Result($rows);
	}

	/**
	 * Get table names from the statement.
	 *
	 * @param SelectStatement $stmt The statement.
	 * @return list<string> The table names.
	 */
	protected function getTables(SelectStatement $stmt): array
	{
		$tables = [];

		if (!empty($stmt->from)) {
			foreach ($stmt->from as $from) {
				if (isset($from->table)) {
					$tables[] = $from->table;
				}
			}
		}

		return $tables;
	}

	/**
	 * Get table aliases from the statement.
	 *
	 * @param SelectStatement $stmt The statement.
	 * @return array<string, string> Alias => table name mapping.
	 */
	protected function getAliases(SelectStatement $stmt): array
	{
		$aliases = [];

		if (!empty($stmt->from)) {
			foreach ($stmt->from as $from) {
				if (isset($from->table)) {
					$alias = $from->alias ?? $from->table;
					$aliases[$alias] = $from->table;
				}
			}
		}

		if (!empty($stmt->join)) {
			foreach ($stmt->join as $join) {
				if (isset($join->expr->table)) {
					$alias = $join->expr->alias ?? $join->expr->table;
					$aliases[$alias] = $join->expr->table;
				}
			}
		}

		return $aliases;
	}

	/**
	 * Handle JOIN clauses.
	 *
	 * @param list<array<string, mixed>> $rows    The primary table rows.
	 * @param SelectStatement            $stmt    The statement.
	 * @param array<string, string>      $aliases Table aliases.
	 * @return list<array<string, mixed>> The joined rows.
	 */
	protected function handleJoins(array $rows, SelectStatement $stmt, array $aliases): array
	{
		// Get the primary table alias.
		$primaryAlias = null;
		if (!empty($stmt->from)) {
			$primaryAlias = $stmt->from[0]->alias ?? $stmt->from[0]->table ?? null;
		}

		// Prefix primary table rows with alias on first join (if not already prefixed).
		if (null !== $primaryAlias && !empty($rows) && !isset($rows[0][$primaryAlias . '.'])) {
			$prefixedRows = [];
			foreach ($rows as $row) {
				$prefixed = [];
				foreach ($row as $col => $val) {
					// Add prefixed key.
					$prefixed[$primaryAlias . '.' . $col] = $val;
					// Also keep unprefixed for backwards compatibility.
					$prefixed[$col] = $val;
				}
				$prefixedRows[] = $prefixed;
			}
			$rows = $prefixedRows;
		}

		foreach ($stmt->join as $join) {
			$joinTable = $join->expr->table ?? null;
			if (null === $joinTable) {
				continue;
			}

			$joinType = \strtoupper($join->type ?: 'INNER');
			$joinRows = $this->storage->getAllRows($joinTable);
			$joinAlias = $join->expr->alias ?? $joinTable;

			// Build join condition.
			$onConditions = $join->on ?: [];

			$newRows = [];
			foreach ($rows as $leftRow) {
				$matched = false;

				foreach ($joinRows as $rightRow) {
					// Prefix right row columns with alias.
					$prefixedRight = [];
					foreach ($rightRow as $col => $val) {
						$prefixedRight[$joinAlias . '.' . $col] = $val;
						// Note: Don't add unprefixed keys here to avoid overwriting left table columns!
					}

					// Merge for evaluation (left row already has prefixed keys).
					$merged = \array_merge($leftRow, $prefixedRight);

					// Check ON conditions.
					if ($this->evaluator->evaluateWhere($onConditions, $merged, $aliases)) {
						$newRows[] = $merged;
						$matched   = true;
					}
				}

				// For LEFT JOIN, include unmatched left rows.
				if (!$matched && \str_contains($joinType, 'LEFT')) {
					// Add NULL values for right table columns.
					$nullRight = [];
					if (!empty($joinRows)) {
						foreach (\array_keys($joinRows[0]) as $col) {
							$nullRight[$joinAlias . '.' . $col] = null;
						}
					}
					$newRows[] = \array_merge($leftRow, $nullRight);
				}
			}

			$rows = $newRows;
		}

		return $rows;
	}

	/**
	 * Apply WHERE conditions.
	 *
	 * @param list<array<string, mixed>> $rows       The rows to filter.
	 * @param list<mixed>                $conditions The WHERE conditions.
	 * @param array<string, string>      $aliases    Table aliases.
	 * @return list<array<string, mixed>> The filtered rows.
	 */
	protected function applyWhere(array $rows, array $conditions, array $aliases): array
	{
		$result = [];

		foreach ($rows as $row) {
			if ($this->evaluator->evaluateWhere($conditions, $row, $aliases)) {
				$result[] = $row;
			}
		}

		return $result;
	}

	/**
	 * Apply GROUP BY.
	 *
	 * @param list<array<string, mixed>> $rows The rows to group.
	 * @param SelectStatement            $stmt The statement.
	 * @return list<array<string, mixed>> The grouped rows.
	 */
	protected function applyGroupBy(array $rows, SelectStatement $stmt): array
	{
		$groups = [];

		// Group rows.
		foreach ($rows as $row) {
			$keyParts = [];
			foreach ($stmt->group as $group) {
				$col = $group->expr->expr ?? '';
				$col = \str_replace('`', '', $col);
				$keyParts[] = $row[$col] ?? '';
			}
			$key = \implode('|', $keyParts);
			$groups[$key][] = $row;
		}

		// Apply aggregates.
		$result = [];
		foreach ($groups as $groupRows) {
			$aggregated = $this->applyAggregates($groupRows, $stmt);
			$result[] = $aggregated;
		}

		return $result;
	}

	/**
	 * Apply aggregate functions to grouped rows.
	 *
	 * @param list<array<string, mixed>> $rows The grouped rows.
	 * @param SelectStatement            $stmt The statement.
	 * @return array<string, mixed> The aggregated row.
	 */
	protected function applyAggregates(array $rows, SelectStatement $stmt): array
	{
		if (empty($rows)) {
			return [];
		}

		$result = $rows[0]; // Start with first row for non-aggregated columns.

		foreach ($stmt->expr as $expr) {
			$exprStr = $expr->expr ?? '';
			$alias   = $expr->alias ?? $exprStr;

			// Check for aggregate functions.
			if (\preg_match('/^(\w+)\s*\((.*)?\)\s*$/i', $exprStr, $m)) {
				$func = \strtoupper($m[1]);
				$arg  = \trim($m[2] ?? '');
				$arg  = \str_replace('`', '', $arg);

				$result[$alias] = match ($func) {
					'COUNT' => '*' === $arg ? \count($rows) : \count(\array_filter($rows, fn($r) => null !== ($r[$arg] ?? null))),
					'SUM'   => \array_sum(\array_column($rows, $arg)),
					'AVG'   => \array_sum(\array_column($rows, $arg)) / \count($rows),
					'MIN'   => \min(\array_column($rows, $arg)),
					'MAX'   => \max(\array_column($rows, $arg)),
					'GROUP_CONCAT' => \implode(',', \array_column($rows, $arg)),
					default => null,
				};
			}
		}

		return $result;
	}

	/**
	 * Apply ORDER BY.
	 *
	 * @param list<array<string, mixed>> $rows  The rows to sort.
	 * @param list<mixed>                $order The ORDER BY clause.
	 * @return list<array<string, mixed>> The sorted rows.
	 */
	protected function applyOrderBy(array $rows, array $order): array
	{
		\usort($rows, function ($a, $b) use ($order) {
			foreach ($order as $item) {
				$col = $item->expr->expr ?? '';
				$col = \str_replace('`', '', $col);
				$dir = \strtoupper($item->type ?? 'ASC');

				$aVal = $a[$col] ?? null;
				$bVal = $b[$col] ?? null;

				$cmp = $this->compareValues($aVal, $bVal);

				if (0 !== $cmp) {
					return 'DESC' === $dir ? -$cmp : $cmp;
				}
			}
			return 0;
		});

		return $rows;
	}

	/**
	 * Compare two values for sorting.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return int Comparison result.
	 */
	protected function compareValues(mixed $a, mixed $b): int
	{
		if (null === $a && null === $b) {
			return 0;
		}
		if (null === $a) {
			return -1;
		}
		if (null === $b) {
			return 1;
		}

		if (\is_numeric($a) && \is_numeric($b)) {
			return $a <=> $b;
		}

		return \strcmp((string) $a, (string) $b);
	}

	/**
	 * Check if query has DISTINCT.
	 *
	 * @param SelectStatement $stmt The statement.
	 * @return bool True if DISTINCT.
	 */
	protected function isDistinct(SelectStatement $stmt): bool
	{
		return !empty($stmt->options) && $stmt->options->has('DISTINCT');
	}

	/**
	 * Apply DISTINCT.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param SelectStatement            $stmt The statement.
	 * @return list<array<string, mixed>> The unique rows.
	 */
	protected function applyDistinct(array $rows, SelectStatement $stmt): array
	{
		$seen   = [];
		$result = [];

		foreach ($rows as $row) {
			$key = \serialize($row);
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$result[] = $row;
			}
		}

		return $result;
	}

	/**
	 * Apply LIMIT and OFFSET.
	 *
	 * @param list<array<string, mixed>> $rows  The rows.
	 * @param mixed                      $limit The LIMIT clause.
	 * @return list<array<string, mixed>> The limited rows.
	 */
	protected function applyLimit(array $rows, mixed $limit): array
	{
		$offset = (int) ($limit->offset ?? 0);
		$count  = (int) ($limit->rowCount ?? \count($rows));

		return \array_slice($rows, $offset, $count);
	}

	/**
	 * Project columns from rows based on SELECT expressions.
	 *
	 * @param list<array<string, mixed>> $rows    The rows.
	 * @param SelectStatement            $stmt    The statement.
	 * @param array<string, string>      $aliases Table aliases.
	 * @return list<array<string, mixed>> The projected rows.
	 */
	protected function projectColumns(array $rows, SelectStatement $stmt, array $aliases): array
	{
		if (empty($stmt->expr)) {
			return $rows;
		}

		// Check for SELECT * (global wildcard - return all columns).
		foreach ($stmt->expr as $expr) {
			if ('*' === ($expr->expr ?? '')) {
				// Return all rows but strip prefixed keys, keeping only unprefixed column names.
				return $this->stripPrefixedKeys($rows);
			}
		}

		$result = [];

		foreach ($rows as $row) {
			$projected = [];

			foreach ($stmt->expr as $expr) {
				$exprStr = $expr->expr ?? '';

				// Clean up column name (remove backticks).
				$colName = \str_replace('`', '', $exprStr);

				// Handle table-prefixed wildcard (e.g., t.*, tt.*).
				if (\str_ends_with($colName, '.*')) {
					$tableAlias = \substr($colName, 0, -2);
					$prefix     = $tableAlias . '.';
					$prefixLen  = \strlen($prefix);

					// Add all columns from this table (those with matching prefix).
					foreach ($row as $key => $val) {
						if (\str_starts_with($key, $prefix)) {
							// Output with column name only (strip table alias prefix).
							$outputKey = \substr($key, $prefixLen);
							$projected[$outputKey] = $val;
						}
					}
					continue;
				}

				// Determine the output alias.
				// If explicit alias is provided, use it.
				// Otherwise, strip table alias prefix (e.g., "t.term_id" -> "term_id").
				if (!empty($expr->alias)) {
					$outputName = $expr->alias;
				} else {
					// Strip table alias prefix if present (e.g., "t.term_id" -> "term_id").
					$outputName = \str_contains($colName, '.') ? \substr($colName, \strpos($colName, '.') + 1) : $colName;
				}

				// Check if it's a function or already evaluated.
				if (isset($row[$outputName])) {
					$projected[$outputName] = $row[$outputName];
				} elseif (isset($row[$colName])) {
					$projected[$outputName] = $row[$colName];
				} else {
					// Evaluate expression.
					$projected[$outputName] = $this->evaluator->evaluateValue($exprStr, $row, $aliases);
				}
			}

			$result[] = $projected;
		}

		return $result;
	}

	/**
	 * Strip prefixed keys from rows, keeping only unprefixed column names.
	 *
	 * When joins occur, rows contain both prefixed (t.column) and unprefixed (column) keys.
	 * For SELECT *, we want to return only unprefixed column names.
	 *
	 * @param list<array<string, mixed>> $rows The rows with potentially prefixed keys.
	 * @return list<array<string, mixed>> The rows with only unprefixed keys.
	 */
	protected function stripPrefixedKeys(array $rows): array
	{
		$result = [];

		foreach ($rows as $row) {
			$cleaned = [];
			foreach ($row as $key => $val) {
				// Skip prefixed keys (contain a dot like "t.column").
				if (!\str_contains($key, '.')) {
					$cleaned[$key] = $val;
				}
			}
			$result[] = $cleaned;
		}

		return $result;
	}
}
