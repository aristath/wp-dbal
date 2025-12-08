<?php

/**
 * FileDB Result - Doctrine DBAL Driver Result for file-based storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB;

use Doctrine\DBAL\Driver\Result as ResultInterface;

/**
 * FileDB Result implementation.
 *
 * Wraps query results for consumption by DBAL.
 */
class Result implements ResultInterface
{
	/**
	 * The result rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	protected array $rows;

	/**
	 * The current row pointer.
	 *
	 * @var integer
	 */
	protected int $pointer = 0;

	/**
	 * Number of affected rows (for INSERT/UPDATE/DELETE).
	 *
	 * @var integer
	 */
	protected int $affectedRows;

	/**
	 * Column names from the result set.
	 *
	 * @var list<string>
	 */
	protected array $columns = [];

	/**
	 * Constructor.
	 *
	 * @param list<array<string, mixed>> $rows         The result rows.
	 * @param int                        $affectedRows Number of affected rows.
	 */
	public function __construct(array $rows = [], int $affectedRows = 0)
	{
		$this->rows         = $rows;
		$this->affectedRows = $affectedRows;

		// Extract column names from first row.
		if (!empty($rows)) {
			$this->columns = \array_keys($rows[0]);
		}
	}

	/**
	 * Fetch the next row as a numeric array.
	 *
	 * @return list<mixed>|false The row data or false if no more rows.
	 */
	public function fetchNumeric(): array|false
	{
		if (!isset($this->rows[$this->pointer])) {
			return false;
		}

		$row = $this->rows[$this->pointer];
		$this->pointer++;

		return \array_values($row);
	}

	/**
	 * Fetch the next row as an associative array.
	 *
	 * @return array<string, mixed>|false The row data or false if no more rows.
	 */
	public function fetchAssociative(): array|false
	{
		if (!isset($this->rows[$this->pointer])) {
			return false;
		}

		$row = $this->rows[$this->pointer];
		$this->pointer++;

		return $row;
	}

	/**
	 * Fetch the first column of the next row.
	 *
	 * @return mixed The column value or false if no more rows.
	 */
	public function fetchOne(): mixed
	{
		$row = $this->fetchNumeric();

		if (false === $row) {
			return false;
		}

		return $row[0] ?? false;
	}

	/**
	 * Fetch all rows as numeric arrays.
	 *
	 * @return list<list<mixed>> All rows.
	 */
	public function fetchAllNumeric(): array
	{
		$result = [];

		while (false !== ($row = $this->fetchNumeric())) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Fetch all rows as associative arrays.
	 *
	 * @return list<array<string, mixed>> All rows.
	 */
	public function fetchAllAssociative(): array
	{
		$result = [];

		while (false !== ($row = $this->fetchAssociative())) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Fetch all values from the first column.
	 *
	 * @return list<mixed> All first column values.
	 */
	public function fetchFirstColumn(): array
	{
		$result = [];

		while (false !== ($value = $this->fetchOne())) {
			$result[] = $value;
		}

		return $result;
	}

	/**
	 * Get the number of affected rows.
	 *
	 * @return int|string The row count.
	 */
	public function rowCount(): int|string
	{
		return $this->affectedRows;
	}

	/**
	 * Get the number of columns in the result.
	 *
	 * @return int The column count.
	 */
	public function columnCount(): int
	{
		return \count($this->columns);
	}

	/**
	 * Get the name of a column by index.
	 *
	 * @param int $index The column index.
	 * @return string The column name.
	 */
	public function getColumnName(int $index): string
	{
		return $this->columns[$index] ?? '';
	}

	/**
	 * Free the result set.
	 *
	 * @return void
	 */
	public function free(): void
	{
		$this->rows    = [];
		$this->pointer = 0;
		$this->columns = [];
	}

	/**
	 * Reset the pointer to the beginning.
	 *
	 * @return void
	 */
	public function reset(): void
	{
		$this->pointer = 0;
	}

	/**
	 * Get all rows without advancing the pointer.
	 *
	 * @return list<array<string, mixed>> All rows.
	 */
	public function getAllRows(): array
	{
		return $this->rows;
	}
}
