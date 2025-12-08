<?php

/**
 * Expression Evaluator - Evaluates SQL expressions against row data.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Components\Condition;

/**
 * Evaluates SQL WHERE conditions and expressions.
 *
 * Supports:
 * - Comparison operators: =, !=, <>, <, >, <=, >=
 * - Pattern matching: LIKE, NOT LIKE
 * - Set operations: IN, NOT IN
 * - Range: BETWEEN
 * - Null checks: IS NULL, IS NOT NULL
 * - Logical operators: AND, OR, NOT
 * - SQL functions: NOW(), CONCAT(), etc.
 */
class ExpressionEvaluator
{
	/**
	 * Evaluate WHERE conditions against a row.
	 *
	 * @param list<Condition>      $conditions The WHERE conditions.
	 * @param array<string, mixed> $row        The row data.
	 * @param array<string, mixed> $aliases    Table aliases (alias => table).
	 * @return bool True if row matches all conditions.
	 */
	public function evaluateWhere(array $conditions, array $row, array $aliases = []): bool
	{
		if (empty($conditions)) {
			return true;
		}

		// Build expression string.
		$parts = [];
		foreach ($conditions as $cond) {
			if ($cond->isOperator) {
				$parts[] = $cond->expr;
			} else {
				$result  = $this->evaluateCondition($cond->expr, $row, $aliases);
				$parts[] = $result ? 'TRUE' : 'FALSE';
			}
		}

		return $this->evaluateLogical(\implode(' ', $parts));
	}

	/**
	 * Evaluate a single condition expression.
	 *
	 * @param string               $expr    The expression string.
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return bool True if condition matches.
	 */
	public function evaluateCondition(string $expr, array $row, array $aliases = []): bool
	{
		// Normalize the expression.
		$expr = \trim($expr);

		// Handle IS NULL / IS NOT NULL.
		if (\preg_match('/^(.+?)\s+IS\s+(NOT\s+)?NULL$/i', $expr, $m)) {
			$column = $this->resolveColumn(\trim($m[1]), $row, $aliases);
			$value  = $this->getColumnValue($column, $row);
			$isNull = null === $value;
			return !empty($m[2]) ? !$isNull : $isNull;
		}

		// Handle BETWEEN.
		if (\preg_match('/^(.+?)\s+BETWEEN\s+(.+?)\s+AND\s+(.+)$/i', $expr, $m)) {
			$column = $this->resolveColumn(\trim($m[1]), $row, $aliases);
			$value  = $this->getColumnValue($column, $row);
			$low    = $this->evaluateValue(\trim($m[2]), $row, $aliases);
			$high   = $this->evaluateValue(\trim($m[3]), $row, $aliases);
			return $value >= $low && $value <= $high;
		}

		// Handle IN / NOT IN.
		if (\preg_match('/^(.+?)\s+(NOT\s+)?IN\s*\((.+)\)$/i', $expr, $m)) {
			$column  = $this->resolveColumn(\trim($m[1]), $row, $aliases);
			$value   = $this->getColumnValue($column, $row);
			$isNot   = !empty($m[2]);
			$inList  = $this->parseInList($m[3], $row, $aliases);
			$inArray = \in_array($value, $inList, false);
			return $isNot ? !$inArray : $inArray;
		}

		// Handle LIKE / NOT LIKE.
		if (\preg_match('/^(.+?)\s+(NOT\s+)?LIKE\s+(.+)$/i', $expr, $m)) {
			$column  = $this->resolveColumn(\trim($m[1]), $row, $aliases);
			$value   = (string) $this->getColumnValue($column, $row);
			$isNot   = !empty($m[2]);
			$pattern = $this->evaluateValue(\trim($m[3]), $row, $aliases);
			$matches = $this->matchLike($value, (string) $pattern);
			return $isNot ? !$matches : $matches;
		}

		// Handle comparison operators.
		$operators = ['<=>', '!=', '<>', '<=', '>=', '=', '<', '>'];
		foreach ($operators as $op) {
			if (\str_contains($expr, $op)) {
				$parts = \explode($op, $expr, 2);
				if (2 === \count($parts)) {
					$left  = $this->evaluateValue(\trim($parts[0]), $row, $aliases);
					$right = $this->evaluateValue(\trim($parts[1]), $row, $aliases);
					return $this->compare($left, $op, $right);
				}
			}
		}

		// If no operator found, treat as boolean expression.
		$value = $this->evaluateValue($expr, $row, $aliases);
		return (bool) $value;
	}

	/**
	 * Evaluate a value (column reference, literal, or function call).
	 *
	 * @param string               $expr    The expression.
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return mixed The evaluated value.
	 */
	public function evaluateValue(string $expr, array $row, array $aliases = []): mixed
	{
		$expr = \trim($expr);

		// String literal.
		if (\preg_match('/^[\'"](.*)[\'"]\s*$/s', $expr, $m)) {
			return $this->unescapeString($m[1]);
		}

		// Numeric literal.
		if (\is_numeric($expr)) {
			return \str_contains($expr, '.') ? (float) $expr : (int) $expr;
		}

		// NULL.
		if ('NULL' === \strtoupper($expr)) {
			return null;
		}

		// Boolean.
		$upper = \strtoupper($expr);
		if ('TRUE' === $upper) {
			return true;
		}
		if ('FALSE' === $upper) {
			return false;
		}

		// Function call.
		if (\preg_match('/^(\w+)\s*\((.*)?\)\s*$/s', $expr, $m)) {
			return $this->evaluateFunction(\strtoupper($m[1]), $m[2] ?? '', $row, $aliases);
		}

		// Column reference.
		$column = $this->resolveColumn($expr, $row, $aliases);
		return $this->getColumnValue($column, $row);
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

	/**
	 * Evaluate a SQL function.
	 *
	 * @param string               $func    The function name.
	 * @param string               $args    The arguments string.
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return mixed The function result.
	 */
	protected function evaluateFunction(string $func, string $args, array $row, array $aliases): mixed
	{
		$argList = $this->parseArguments($args, $row, $aliases);

		return match ($func) {
			'NOW', 'CURRENT_TIMESTAMP' => \date('Y-m-d H:i:s'),
			'CURDATE', 'CURRENT_DATE'  => \date('Y-m-d'),
			'CURTIME', 'CURRENT_TIME'  => \date('H:i:s'),
			'UNIX_TIMESTAMP'           => empty($argList) ? \time() : \strtotime((string) $argList[0]),
			'FROM_UNIXTIME'            => isset($argList[0]) ? \date('Y-m-d H:i:s', (int) $argList[0]) : null,
			'YEAR'                     => isset($argList[0]) ? (int) \date('Y', \strtotime((string) $argList[0])) : null,
			'MONTH'                    => isset($argList[0]) ? (int) \date('n', \strtotime((string) $argList[0])) : null,
			'DAY', 'DAYOFMONTH'        => isset($argList[0]) ? (int) \date('j', \strtotime((string) $argList[0])) : null,
			'HOUR'                     => isset($argList[0]) ? (int) \date('G', \strtotime((string) $argList[0])) : null,
			'MINUTE'                   => isset($argList[0]) ? (int) \date('i', \strtotime((string) $argList[0])) : null,
			'SECOND'                   => isset($argList[0]) ? (int) \date('s', \strtotime((string) $argList[0])) : null,
			'DATE_FORMAT'              => isset($argList[0], $argList[1]) ? $this->dateFormat((string) $argList[0], (string) $argList[1]) : null,
			'CONCAT'                   => \implode('', \array_map('strval', $argList)),
			'CONCAT_WS'                => \count($argList) > 1 ? \implode((string) $argList[0], \array_map('strval', \array_slice($argList, 1))) : '',
			'UPPER', 'UCASE'           => isset($argList[0]) ? \strtoupper((string) $argList[0]) : null,
			'LOWER', 'LCASE'           => isset($argList[0]) ? \strtolower((string) $argList[0]) : null,
			'LENGTH', 'CHAR_LENGTH'    => isset($argList[0]) ? \strlen((string) $argList[0]) : null,
			'SUBSTRING', 'SUBSTR', 'MID' => $this->substring($argList),
			'TRIM'                     => isset($argList[0]) ? \trim((string) $argList[0]) : null,
			'LTRIM'                    => isset($argList[0]) ? \ltrim((string) $argList[0]) : null,
			'RTRIM'                    => isset($argList[0]) ? \rtrim((string) $argList[0]) : null,
			'REPLACE'                  => isset($argList[0], $argList[1], $argList[2]) ? \str_replace((string) $argList[1], (string) $argList[2], (string) $argList[0]) : null,
			'LEFT'                     => isset($argList[0], $argList[1]) ? \substr((string) $argList[0], 0, (int) $argList[1]) : null,
			'RIGHT'                    => isset($argList[0], $argList[1]) ? \substr((string) $argList[0], -(int) $argList[1]) : null,
			'REVERSE'                  => isset($argList[0]) ? \strrev((string) $argList[0]) : null,
			'ABS'                      => isset($argList[0]) ? \abs((float) $argList[0]) : null,
			'CEIL', 'CEILING'          => isset($argList[0]) ? (int) \ceil((float) $argList[0]) : null,
			'FLOOR'                    => isset($argList[0]) ? (int) \floor((float) $argList[0]) : null,
			'ROUND'                    => isset($argList[0]) ? \round((float) $argList[0], (int) ($argList[1] ?? 0)) : null,
			'RAND'                     => $this->randomWithSeed($argList[0] ?? null),
			'IF'                       => isset($argList[0]) && $argList[0] ? ($argList[1] ?? null) : ($argList[2] ?? null),
			'IFNULL'                   => $argList[0] ?? $argList[1] ?? null,
			'NULLIF'                   => isset($argList[0], $argList[1]) && $argList[0] == $argList[1] ? null : ($argList[0] ?? null),
			'COALESCE'                 => $this->coalesce($argList),
			'GREATEST'                 => empty($argList) ? null : \max($argList),
			'LEAST'                    => empty($argList) ? null : \min($argList),
			'MD5'                      => isset($argList[0]) ? \md5((string) $argList[0]) : null,
			'SHA1', 'SHA'              => isset($argList[0]) ? \sha1((string) $argList[0]) : null,
			default                    => null, // Unknown function returns null.
		};
	}

	/**
	 * Format a date using MySQL format string.
	 *
	 * @param string $date   The date string.
	 * @param string $format The MySQL format string.
	 * @return string The formatted date.
	 */
	protected function dateFormat(string $date, string $format): string
	{
		$timestamp = \strtotime($date);
		if (false === $timestamp) {
			return '';
		}

		// Convert MySQL format to PHP format.
		$phpFormat = \str_replace(
			['%Y', '%y', '%m', '%c', '%d', '%e', '%H', '%k', '%h', '%l', '%i', '%s', '%p', '%W', '%a', '%M', '%b', '%D'],
			['Y',  'y',  'm',  'n',  'd',  'j',  'H',  'G',  'h',  'g',  'i',  's',  'A',  'l',  'D',  'F',  'M',  'jS'],
			$format
		);

		return \date($phpFormat, $timestamp);
	}

	/**
	 * Implement SUBSTRING function.
	 *
	 * @param list<mixed> $args The function arguments.
	 * @return string|null The substring.
	 */
	protected function substring(array $args): ?string
	{
		if (!isset($args[0], $args[1])) {
			return null;
		}

		$str   = (string) $args[0];
		$start = (int) $args[1] - 1; // MySQL is 1-indexed.

		if (isset($args[2])) {
			return \substr($str, $start, (int) $args[2]);
		}

		return \substr($str, $start);
	}

	/**
	 * Implement COALESCE function.
	 *
	 * @param list<mixed> $args The function arguments.
	 * @return mixed The first non-null value.
	 */
	protected function coalesce(array $args): mixed
	{
		foreach ($args as $arg) {
			if (null !== $arg) {
				return $arg;
			}
		}

		return null;
	}

	/**
	 * Generate a random number with optional seed.
	 *
	 * @param mixed $seed Optional seed value.
	 * @return float Random number between 0 and 1.
	 */
	protected function randomWithSeed(mixed $seed): float
	{
		if (null !== $seed) {
			\mt_srand((int) $seed);
		}
		return \mt_rand() / \mt_getrandmax();
	}

	/**
	 * Parse function arguments.
	 *
	 * @param string               $args    The arguments string.
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return list<mixed> The parsed arguments.
	 */
	protected function parseArguments(string $args, array $row, array $aliases): array
	{
		if ('' === \trim($args)) {
			return [];
		}

		$result = [];
		$depth  = 0;
		$current = '';

		for ($i = 0, $len = \strlen($args); $i < $len; $i++) {
			$char = $args[$i];

			if ('(' === $char) {
				$depth++;
				$current .= $char;
			} elseif (')' === $char) {
				$depth--;
				$current .= $char;
			} elseif (',' === $char && 0 === $depth) {
				$result[] = $this->evaluateValue(\trim($current), $row, $aliases);
				$current  = '';
			} else {
				$current .= $char;
			}
		}

		if ('' !== \trim($current)) {
			$result[] = $this->evaluateValue(\trim($current), $row, $aliases);
		}

		return $result;
	}

	/**
	 * Compare two values with an operator.
	 *
	 * @param mixed  $left  The left value.
	 * @param string $op    The operator.
	 * @param mixed  $right The right value.
	 * @return bool The comparison result.
	 */
	protected function compare(mixed $left, string $op, mixed $right): bool
	{
		// Handle NULL comparisons.
		if (null === $left || null === $right) {
			if ('<=>' === $op) {
				return $left === $right;
			}
			return false; // NULL comparisons return false in SQL.
		}

		return match ($op) {
			'='       => $left == $right,
			'!=', '<>' => $left != $right,
			'<=>'     => $left == $right, // NULL-safe equals.
			'<'       => $left < $right,
			'>'       => $left > $right,
			'<='      => $left <= $right,
			'>='      => $left >= $right,
			default   => false,
		};
	}

	/**
	 * Match a value against a LIKE pattern.
	 *
	 * @param string $value   The value to match.
	 * @param string $pattern The LIKE pattern.
	 * @return bool True if matches.
	 */
	protected function matchLike(string $value, string $pattern): bool
	{
		// Convert SQL LIKE pattern to regex.
		$regex = '/^' . \str_replace(
			['%', '_', '\\%', '\\_'],
			['.*', '.', '%', '_'],
			\preg_quote($pattern, '/')
		) . '$/i';

		return 1 === \preg_match($regex, $value);
	}

	/**
	 * Parse an IN list.
	 *
	 * @param string               $list    The list string.
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return list<mixed> The list values.
	 */
	protected function parseInList(string $list, array $row, array $aliases): array
	{
		$items = \preg_split('/\s*,\s*/', \trim($list));
		if (false === $items) {
			return [];
		}

		$result = [];
		foreach ($items as $item) {
			$result[] = $this->evaluateValue(\trim($item), $row, $aliases);
		}

		return $result;
	}

	/**
	 * Resolve a column reference to a column name.
	 *
	 * First checks if the fully-qualified name (alias.column) exists in the row,
	 * otherwise falls back to just the column name.
	 *
	 * @param string               $ref     The column reference (may include table alias).
	 * @param array<string, mixed> $row     The row data.
	 * @param array<string, mixed> $aliases Table aliases.
	 * @return string The column name to use for lookup.
	 */
	protected function resolveColumn(string $ref, array $row, array $aliases): string
	{
		// Remove backticks.
		$ref = \str_replace('`', '', $ref);

		// If ref contains alias.column, check if that prefixed key exists in the row first.
		// This handles JOINs where both tables have columns with the same name (like term_id).
		if (\str_contains($ref, '.')) {
			// Check if the full prefixed key exists.
			if (\array_key_exists($ref, $row)) {
				return $ref;
			}

			// Fall back to just the column name.
			$parts = \explode('.', $ref, 2);
			return $parts[1];
		}

		return $ref;
	}

	/**
	 * Get a column value from a row.
	 *
	 * @param string               $column The column name.
	 * @param array<string, mixed> $row    The row data.
	 * @return mixed The column value.
	 */
	protected function getColumnValue(string $column, array $row): mixed
	{
		// Direct match.
		if (\array_key_exists($column, $row)) {
			return $row[$column];
		}

		// Case-insensitive match.
		$lowerColumn = \strtolower($column);
		foreach ($row as $key => $value) {
			if (\strtolower($key) === $lowerColumn) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Evaluate a logical expression (AND, OR, NOT).
	 *
	 * @param string $expr The expression with TRUE/FALSE values.
	 * @return bool The result.
	 */
	protected function evaluateLogical(string $expr): bool
	{
		$expr = \trim($expr);

		// Handle NOT.
		$expr = \preg_replace('/\bNOT\s+TRUE\b/i', 'FALSE', $expr) ?? $expr;
		$expr = \preg_replace('/\bNOT\s+FALSE\b/i', 'TRUE', $expr) ?? $expr;

		// Handle AND.
		if (\preg_match('/(.+?)\s+AND\s+(.+)/i', $expr, $m)) {
			return $this->evaluateLogical($m[1]) && $this->evaluateLogical($m[2]);
		}

		// Handle OR.
		if (\preg_match('/(.+?)\s+OR\s+(.+)/i', $expr, $m)) {
			return $this->evaluateLogical($m[1]) || $this->evaluateLogical($m[2]);
		}

		return 'TRUE' === \strtoupper(\trim($expr));
	}
}
