<?php

/**
 * D1 Statement - Doctrine DBAL Statement for D1 prepared statements.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\D1;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/**
 * D1 Statement implementation.
 *
 * Handles prepared statements for the D1 driver by collecting
 * bound parameters and executing them via the HTTP client.
 */
class Statement implements StatementInterface
{
	/**
	 * The SQL query.
	 *
	 * @var string
	 */
	protected string $sql;

	/**
	 * The connection instance.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Bound parameters.
	 *
	 * @var array<int|string, mixed>
	 */
	protected array $params = [];

	/**
	 * Parameter types.
	 *
	 * @var array<int|string, ParameterType>
	 */
	protected array $types = [];

	/**
	 * Constructor.
	 *
	 * @param string     $sql        The SQL query.
	 * @param Connection $connection The connection instance.
	 */
	public function __construct(string $sql, Connection $connection)
	{
		$this->sql        = $sql;
		$this->connection = $connection;
	}

	/**
	 * Bind a value to a parameter.
	 *
	 * @param int|string    $param The parameter identifier (1-indexed for positional, or named).
	 * @param mixed         $value The value to bind.
	 * @param ParameterType $type  The parameter type.
	 * @return void
	 */
	public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
	{
		$this->params[$param] = $this->convertValue($value, $type);
		$this->types[$param]  = $type;
	}

	/**
	 * Execute the prepared statement.
	 *
	 * @return ResultInterface
	 */
	public function execute(): ResultInterface
	{
		$params = $this->prepareParams();

		$response = $this->connection->getHttpClient()->query($this->sql, $params);

		// Reset params for potential re-execution.
		$this->params = [];
		$this->types  = [];

		return new Result($response);
	}

	/**
	 * Convert a value to the appropriate type for D1.
	 *
	 * @param mixed         $value The value to convert.
	 * @param ParameterType $type  The parameter type.
	 * @return mixed
	 */
	protected function convertValue(mixed $value, ParameterType $type): mixed
	{
		if (null === $value) {
			return null;
		}

		return match ($type) {
			ParameterType::NULL => null,
			ParameterType::INTEGER => (int) $value,
			ParameterType::BOOLEAN => $value ? 1 : 0,
			ParameterType::BINARY, ParameterType::LARGE_OBJECT => base64_encode((string) $value),
			default => (string) $value,
		};
	}

	/**
	 * Prepare parameters for the D1 API.
	 *
	 * D1 expects positional parameters as an array.
	 * This method normalizes the parameter indices.
	 *
	 * @return array<mixed>
	 */
	protected function prepareParams(): array
	{
		if (empty($this->params)) {
			return [];
		}

		// Check if we have named parameters.
		$hasNamed     = false;
		$hasPositional = false;

		foreach (array_keys($this->params) as $key) {
			if (is_string($key)) {
				$hasNamed = true;
			} else {
				$hasPositional = true;
			}
		}

		// D1 supports both named and positional parameters.
		if ($hasNamed && ! $hasPositional) {
			// All named - return as associative array.
			return $this->params;
		}

		// Positional parameters - DBAL uses 1-indexed, D1 uses 0-indexed array.
		$result = [];
		ksort($this->params, SORT_NUMERIC);

		foreach ($this->params as $value) {
			$result[] = $value;
		}

		return $result;
	}
}
