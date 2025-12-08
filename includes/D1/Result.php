<?php

/**
 * D1 Result - Doctrine DBAL Result wrapper for D1 API responses.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\D1;

use Doctrine\DBAL\Driver\Result as ResultInterface;

/**
 * D1 Result implementation.
 *
 * Wraps the D1 API response to provide the DBAL Result interface.
 */
class Result implements ResultInterface
{
	/**
	 * The result rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $rows;

	/**
	 * Column names from the result.
	 *
	 * @var array<int, string>
	 */
	protected array $columns;

	/**
	 * Current row position.
	 *
	 * @var integer
	 */
	protected int $position = 0;

	/**
	 * Number of affected rows (for INSERT/UPDATE/DELETE).
	 *
	 * @var integer
	 */
	protected int $affectedRows;

	/**
	 * Constructor.
	 *
	 * @param array{
	 *     success?: bool,
	 *     results?: array<int, array<string, mixed>>,
	 *     meta?: array{changes?: int}
	 * } $response The D1 API response for a single statement.
	 */
	public function __construct(array $response)
	{
		$this->rows         = $response['results'] ?? [];
		$this->affectedRows = $response['meta']['changes'] ?? 0;

		// Extract column names from the first row.
		if (! empty($this->rows)) {
			$this->columns = array_keys($this->rows[0]);
		} else {
			$this->columns = [];
		}
	}

	/**
	 * Fetch the next row as a numerically indexed array.
	 *
	 * @return array<int, mixed>|false
	 */
	public function fetchNumeric(): array|false
	{
		if ($this->position >= count($this->rows)) {
			return false;
		}

		$row = $this->rows[$this->position];
		++$this->position;

		return array_values($row);
	}

	/**
	 * Fetch the next row as an associative array.
	 *
	 * @return array<string, mixed>|false
	 */
	public function fetchAssociative(): array|false
	{
		if ($this->position >= count($this->rows)) {
			return false;
		}

		$row = $this->rows[$this->position];
		++$this->position;

		return $row;
	}

	/**
	 * Fetch the value of the first column of the next row.
	 *
	 * @return mixed
	 */
	public function fetchOne(): mixed
	{
		if ($this->position >= count($this->rows)) {
			return false;
		}

		$row = $this->rows[$this->position];
		++$this->position;

		return reset($row);
	}

	/**
	 * Fetch all rows as numerically indexed arrays.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	public function fetchAllNumeric(): array
	{
		$result = [];

		while (($row = $this->fetchNumeric()) !== false) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Fetch all rows as associative arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAllAssociative(): array
	{
		$result = [];

		while (($row = $this->fetchAssociative()) !== false) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Fetch the first column of all rows.
	 *
	 * @return array<int, mixed>
	 */
	public function fetchFirstColumn(): array
	{
		$result = [];

		foreach ($this->rows as $row) {
			$result[] = reset($row);
		}

		// Reset position since we iterated directly.
		$this->position = count($this->rows);

		return $result;
	}

	/**
	 * Get the number of rows affected by the statement.
	 *
	 * For SELECT queries, this returns the number of rows in the result.
	 * For INSERT/UPDATE/DELETE, this returns the number of affected rows.
	 *
	 * @return int
	 */
	public function rowCount(): int
	{
		// If we have affected rows from meta, use that (for INSERT/UPDATE/DELETE).
		if ($this->affectedRows > 0) {
			return $this->affectedRows;
		}

		// Otherwise return the number of result rows (for SELECT).
		return count($this->rows);
	}

	/**
	 * Get the number of columns in the result.
	 *
	 * @return int
	 */
	public function columnCount(): int
	{
		return count($this->columns);
	}

	/**
	 * Free the result resources.
	 *
	 * @return void
	 */
	public function free(): void
	{
		$this->rows     = [];
		$this->columns  = [];
		$this->position = 0;
	}

	/**
	 * Get the column name at a given index.
	 *
	 * @param int $index The column index (0-based).
	 * @return string The column name.
	 */
	public function getColumnName(int $index): string
	{
		return $this->columns[$index] ?? '';
	}
}
