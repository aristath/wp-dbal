<?php

/**
 * FileDB Statement - Doctrine DBAL Driver Statement for file-based storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/**
 * FileDB Statement implementation.
 *
 * Handles prepared statements with parameter binding.
 */
class Statement implements StatementInterface
{
	/**
	 * The SQL query template.
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
	 * @param int|string    $param The parameter identifier.
	 * @param mixed         $value The value to bind.
	 * @param ParameterType $type  The parameter type.
	 * @return void
	 */
	public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
	{
		$this->params[$param] = $value;
		$this->types[$param]  = $type;
	}

	/**
	 * Execute the prepared statement.
	 *
	 * @return ResultInterface The result set.
	 */
	public function execute(): ResultInterface
	{
		$sql = $this->interpolateParams();
		return $this->connection->query($sql);
	}

	/**
	 * Interpolate bound parameters into the SQL query.
	 *
	 * @return string The SQL with parameters replaced.
	 */
	protected function interpolateParams(): string
	{
		if (empty($this->params)) {
			return $this->sql;
		}

		$sql = $this->sql;

		// Handle positional parameters (?)
		if ($this->hasPositionalParams()) {
			$sql = $this->replacePositionalParams($sql);
		}

		// Handle named parameters (:name)
		if ($this->hasNamedParams()) {
			$sql = $this->replaceNamedParams($sql);
		}

		return $sql;
	}

	/**
	 * Check if there are positional parameters.
	 *
	 * @return bool True if positional parameters exist.
	 */
	protected function hasPositionalParams(): bool
	{
		foreach (\array_keys($this->params) as $key) {
			if (\is_int($key)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if there are named parameters.
	 *
	 * @return bool True if named parameters exist.
	 */
	protected function hasNamedParams(): bool
	{
		foreach (\array_keys($this->params) as $key) {
			if (\is_string($key)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace positional parameters with their values.
	 *
	 * @param string $sql The SQL query.
	 * @return string The SQL with positional parameters replaced.
	 */
	protected function replacePositionalParams(string $sql): string
	{
		$index  = 1;
		$offset = 0;

		while (false !== ($pos = \strpos($sql, '?', $offset))) {
			// Skip if inside a quoted string.
			if ($this->isInsideQuotes($sql, $pos)) {
				$offset = $pos + 1;
				continue;
			}

			$value = $this->formatValue($this->params[$index] ?? null, $this->types[$index] ?? ParameterType::STRING);
			$sql   = \substr_replace($sql, $value, $pos, 1);
			$offset = $pos + \strlen($value);
			$index++;
		}

		return $sql;
	}

	/**
	 * Replace named parameters with their values.
	 *
	 * @param string $sql The SQL query.
	 * @return string The SQL with named parameters replaced.
	 */
	protected function replaceNamedParams(string $sql): string
	{
		// Sort by key length descending to replace longer names first.
		$params = $this->params;
		\uksort($params, fn($a, $b) => \strlen((string) $b) - \strlen((string) $a));

		foreach ($params as $name => $value) {
			if (!\is_string($name)) {
				continue;
			}

			$placeholder = ':' . \ltrim($name, ':');
			$type        = $this->types[$name] ?? ParameterType::STRING;
			$formatted   = $this->formatValue($value, $type);

			// Use word boundary to avoid partial replacements.
			$sql = \preg_replace(
				'/(' . \preg_quote($placeholder, '/') . ')(?![a-zA-Z0-9_])/',
				$formatted,
				$sql
			) ?? $sql;
		}

		return $sql;
	}

	/**
	 * Check if a position is inside quoted strings.
	 *
	 * @param string $sql The SQL query.
	 * @param int    $pos The position to check.
	 * @return bool True if inside quotes.
	 */
	protected function isInsideQuotes(string $sql, int $pos): bool
	{
		$inSingle = false;
		$inDouble = false;

		for ($i = 0; $i < $pos; $i++) {
			$char = $sql[$i];
			$prev = $i > 0 ? $sql[$i - 1] : '';

			if ("'" === $char && '\\' !== $prev && !$inDouble) {
				$inSingle = !$inSingle;
			} elseif ('"' === $char && '\\' !== $prev && !$inSingle) {
				$inDouble = !$inDouble;
			}
		}

		return $inSingle || $inDouble;
	}

	/**
	 * Format a value for SQL inclusion.
	 *
	 * Handles proper type conversion and escaping to prevent injection attacks.
	 *
	 * @param mixed         $value The value to format.
	 * @param ParameterType $type  The parameter type.
	 * @return string The formatted value.
	 */
	protected function formatValue(mixed $value, ParameterType $type): string
	{
		if (null === $value) {
			return 'NULL';
		}

		return match ($type) {
			ParameterType::NULL    => 'NULL',
			ParameterType::INTEGER => $this->formatInteger($value),
			ParameterType::BOOLEAN => $value ? '1' : '0',
			ParameterType::BINARY,
			ParameterType::LARGE_OBJECT => $this->formatBinary($value),
			ParameterType::ASCII   => $this->formatString($value),
			default => $this->formatString($value),
		};
	}

	/**
	 * Format an integer value safely.
	 *
	 * @param mixed $value The value to format.
	 * @return string The formatted integer.
	 */
	protected function formatInteger(mixed $value): string
	{
		// Ensure the value is actually numeric to prevent injection.
		if (\is_numeric($value)) {
			// Use intval for integers, or keep as float string if it has decimals.
			if (\is_float($value) || (\is_string($value) && \str_contains($value, '.'))) {
				return (string) (float) $value;
			}
			return (string) (int) $value;
		}

		// Non-numeric values become 0 for INTEGER type.
		return '0';
	}

	/**
	 * Format a string value with proper escaping.
	 *
	 * @param mixed $value The value to format.
	 * @return string The quoted and escaped string.
	 */
	protected function formatString(mixed $value): string
	{
		// Convert to string.
		$str = (string) $value;

		// Use the connection's quote method which handles escaping.
		return $this->connection->quote($str);
	}

	/**
	 * Format binary data safely.
	 *
	 * @param mixed $value The value to format.
	 * @return string The formatted binary value.
	 */
	protected function formatBinary(mixed $value): string
	{
		// For binary data, we base64 encode to ensure safe storage.
		// The storage layer should decode when reading.
		if (\is_resource($value)) {
			$value = \stream_get_contents($value);
		}

		$encoded = \base64_encode((string) $value);
		return "X'" . \bin2hex((string) $value) . "'";
	}
}
