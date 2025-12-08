<?php

/**
 * Insert Executor - Executes INSERT queries against file storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use WP_DBAL\FileDB\Connection;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Executes INSERT statements against file-based storage.
 */
class InsertExecutor
{
	/**
	 * Storage manager instance.
	 *
	 * @var StorageManager
	 */
	protected StorageManager $storage;

	/**
	 * Connection instance.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Expression evaluator instance.
	 *
	 * @var ExpressionEvaluator
	 */
	protected ExpressionEvaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param StorageManager      $storage    The storage manager.
	 * @param Connection          $connection The connection.
	 * @param ExpressionEvaluator $evaluator  The expression evaluator.
	 */
	public function __construct(StorageManager $storage, Connection $connection, ExpressionEvaluator $evaluator)
	{
		$this->storage    = $storage;
		$this->connection = $connection;
		$this->evaluator  = $evaluator;
	}

	/**
	 * Execute an INSERT statement.
	 *
	 * @param InsertStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	public function execute(InsertStatement $stmt): Result
	{
		$table = $stmt->into->dest->table ?? '';

		if ('' === $table) {
			return new Result([], 0);
		}

		// Get columns (strip backticks if present).
		$columns = [];
		if (!empty($stmt->into->columns)) {
			foreach ($stmt->into->columns as $col) {
				$columns[] = \str_replace('`', '', $col);
			}
		}

		// Get values.
		$insertedCount = 0;
		$lastInsertId  = 0;

		if (!empty($stmt->values)) {
			// Standard INSERT ... VALUES syntax.
			foreach ($stmt->values as $valueSet) {
				$row = $this->buildRow($columns, $valueSet);

				if (!empty($row)) {
					$id = $this->storage->insertRow($table, $row);
					$insertedCount++;
					$lastInsertId = $id;
				}
			}
		} elseif (isset($stmt->select) && !empty($stmt->select->expr)) {
			// INSERT ... SELECT syntax (commonly used by Action Scheduler with FROM DUAL).
			// Extract values from SELECT expressions and treat as a single row insert.
			$row = $this->buildRowFromSelect($columns, $stmt->select);

			if (!empty($row)) {
				$id = $this->storage->insertRow($table, $row);
				$insertedCount++;
				$lastInsertId = $id;
			}
		}

		// Flush changes.
		$this->storage->flush();

		// Set last insert ID.
		if ($lastInsertId) {
			$this->connection->setLastInsertId($lastInsertId);
		}

		return new Result([], $insertedCount);
	}

	/**
	 * Build a row from columns and values.
	 *
	 * @param list<string>    $columns  The column names.
	 * @param mixed           $valueSet The value set.
	 * @return array<string, mixed> The row data.
	 */
	protected function buildRow(array $columns, mixed $valueSet): array
	{
		$row = [];

		// Get raw values from the ArrayObj.
		$values = $valueSet->raw ?? [];

		foreach ($columns as $i => $column) {
			if (isset($values[$i])) {
				$row[$column] = $this->parseValue($values[$i]);
			}
		}

		return $row;
	}

	/**
	 * Build a row from INSERT ... SELECT statement.
	 *
	 * This handles the pattern: INSERT INTO table (cols) SELECT values FROM DUAL WHERE ...
	 * commonly used by Action Scheduler for conditional inserts.
	 *
	 * @param list<string> $columns The column names.
	 * @param mixed        $select  The SELECT statement.
	 * @return array<string, mixed> The row data.
	 */
	protected function buildRowFromSelect(array $columns, mixed $select): array
	{
		$row = [];

		if (empty($select->expr)) {
			return $row;
		}

		foreach ($columns as $i => $column) {
			if (isset($select->expr[$i])) {
				// The expr property contains the expression string.
				$expr = $select->expr[$i]->expr ?? '';
				if ('' !== $expr) {
					$row[$column] = $this->parseValue($expr);
				}
			}
		}

		return $row;
	}

	/**
	 * Parse a value expression.
	 *
	 * @param string $value The value expression.
	 * @return mixed The parsed value.
	 */
	protected function parseValue(string $value): mixed
	{
		$value = \trim($value);

		// NULL.
		if ('NULL' === \strtoupper($value)) {
			return null;
		}

		// String literal.
		if (\preg_match('/^[\'"](.*)[\'"]\s*$/s', $value, $m)) {
			return $this->unescapeString($m[1]);
		}

		// Numeric.
		if (\is_numeric($value)) {
			return \str_contains($value, '.') ? (float) $value : (int) $value;
		}

		// Boolean.
		$upper = \strtoupper($value);
		if ('TRUE' === $upper) {
			return true;
		}
		if ('FALSE' === $upper) {
			return false;
		}

		// Function call (e.g., NOW()).
		if (\preg_match('/^(\w+)\s*\((.*)?\)\s*$/i', $value, $m)) {
			return $this->evaluator->evaluateValue($value, [], []);
		}

		// Default: return as string.
		return $value;
	}

	/**
	 * Unescape a MySQL string literal character by character.
	 *
	 * This properly handles MySQL escape sequences and avoids the issue where
	 * str_replace() would convert \"\" (empty string) to " instead of "".
	 *
	 * @param string $str The string content (without surrounding quotes).
	 * @return string The unescaped string.
	 */
	protected function unescapeString(string $str): string
	{
		$result = '';
		$len    = \strlen($str);
		$i      = 0;

		while ($i < $len) {
			// Check for escape sequence.
			if ('\\' === $str[$i] && $i + 1 < $len) {
				$next = $str[$i + 1];
				switch ($next) {
					case '\\':
						$result .= '\\';
						$i += 2;
						break;
					case "'":
						$result .= "'";
						$i += 2;
						break;
					case '"':
						$result .= '"';
						$i += 2;
						break;
					case '0':
						$result .= "\x00";
						$i += 2;
						break;
					case 'n':
						$result .= "\n";
						$i += 2;
						break;
					case 'r':
						$result .= "\r";
						$i += 2;
						break;
					case 't':
						$result .= "\t";
						$i += 2;
						break;
					case 'Z':
						$result .= "\x1a";
						$i += 2;
						break;
					default:
						// Unknown escape, keep the backslash and move forward.
						$result .= $str[$i];
						$i++;
						break;
				}
			} elseif ("'" === $str[$i] && $i + 1 < $len && "'" === $str[$i + 1]) {
				// MySQL doubled single quote '' => '.
				$result .= "'";
				$i += 2;
			} elseif ('"' === $str[$i] && $i + 1 < $len && '"' === $str[$i + 1]) {
				// MySQL doubled double quote "" => ".
				$result .= '"';
				$i += 2;
			} else {
				$result .= $str[$i];
				$i++;
			}
		}

		return $result;
	}
}
