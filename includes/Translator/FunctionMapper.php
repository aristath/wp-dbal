<?php

/**
 * Function Mapper - Maps MySQL functions to platform-specific equivalents.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\Translator;

use Doctrine\DBAL\Connection;

/**
 * Maps MySQL functions to platform-specific SQL functions.
 */
class FunctionMapper
{
	/**
	 * DBAL Connection.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Platform name.
	 *
	 * @var string
	 */
	protected string $platform;

	/**
	 * SQLite-compatible function mappings (shared between sqlite and d1).
	 *
	 * @var array<string, string>
	 */
	protected static array $sqliteMappings = [
		// Date/Time functions.
		'NOW()'                 => "datetime('now', 'localtime')",
		'CURDATE()'             => "date('now', 'localtime')",
		'CURTIME()'             => "time('now', 'localtime')",
		'UTC_TIMESTAMP()'       => "datetime('now')",
		'UNIX_TIMESTAMP()'      => "strftime('%s', 'now')",
		'CURRENT_TIMESTAMP()'   => "datetime('now', 'localtime')",
		'CURRENT_DATE()'        => "date('now', 'localtime')",
		'CURRENT_TIME()'        => "time('now', 'localtime')",
		'SYSDATE()'             => "datetime('now', 'localtime')",

		// String functions.
		'RAND()'                => 'RANDOM() / 18446744073709551616 + 0.5',

		// Other.
		'FOUND_ROWS()'          => '0', // Not supported in SQLite.
		'LAST_INSERT_ID()'      => 'last_insert_rowid()',
		'ROW_COUNT()'           => 'changes()',
		'DATABASE()'            => "''", // SQLite doesn't have named databases.
		'VERSION()'             => "'SQLite'",
	];

	/**
	 * Function mappings per platform.
	 *
	 * @var array<string, array<string, string|callable>>
	 */
	protected static array $mappings = [
		'sqlite' => [], // Will be populated from $sqliteMappings.
		'd1'     => [], // Will be populated from $sqliteMappings (D1 is SQLite-based).
		'pgsql'  => [
			// Date/Time functions.
			'NOW()'                 => 'NOW()',
			'CURDATE()'             => 'CURRENT_DATE',
			'CURTIME()'             => 'CURRENT_TIME',
			'UTC_TIMESTAMP()'       => "(NOW() AT TIME ZONE 'UTC')",
			'UNIX_TIMESTAMP()'      => 'EXTRACT(EPOCH FROM NOW())::INTEGER',
			'CURRENT_TIMESTAMP()'   => 'NOW()',
			'SYSDATE()'             => 'NOW()',

			// String functions.
			'RAND()'                => 'RANDOM()',

			// Other.
			'FOUND_ROWS()'          => '0', // Not directly supported.
			'LAST_INSERT_ID()'      => 'lastval()',
			'ROW_COUNT()'           => '0', // Not directly supported in this context.
			'DATABASE()'            => 'current_database()',
			'VERSION()'             => 'version()',
		],
		'mysql'  => [
			// MySQL passthrough - no changes needed.
		],
	];

	/**
	 * SQLite-compatible regex patterns (shared between sqlite and d1).
	 *
	 * @var array<string, string>
	 */
	protected static array $sqlitePatterns = [
		// CONCAT handled specially due to nested parentheses.
		'/\bCONCAT\s*\(/i'                               => 'sqliteConcatStart',
		// CONCAT_WS(sep, a, b, c) - needs special handling.
		'/\bCONCAT_WS\s*\(/i'                            => 'sqliteConcatWsStart',
		// IFNULL(a, b) -> COALESCE(a, b).
		'/\bIFNULL\s*\(([^,]+),\s*([^)]+)\)/i'           => 'COALESCE($1, $2)',
		// NULLIF(a, b) -> NULLIF(a, b) (SQLite supports it).
		// IF(cond, true, false) -> CASE WHEN cond THEN true ELSE false END.
		'/\bIF\s*\(([^,]+),\s*([^,]+),\s*([^)]+)\)/i'    => 'CASE WHEN $1 THEN $2 ELSE $3 END',
		// UNIX_TIMESTAMP(date) -> strftime('%s', date).
		'/\bUNIX_TIMESTAMP\s*\(([^)]+)\)/i'              => "strftime('%s', $1)",
		// FROM_UNIXTIME(timestamp) -> datetime(timestamp, 'unixepoch', 'localtime').
		'/\bFROM_UNIXTIME\s*\(([^)]+)\)/i'               => "datetime($1, 'unixepoch', 'localtime')",
		// DATE_FORMAT(date, format) - needs special handling.
		'/\bDATE_FORMAT\s*\(([^,]+),\s*([^)]+)\)/i'      => 'sqliteDateFormat',
		// YEAR(date) -> strftime('%Y', date).
		'/\bYEAR\s*\(([^)]+)\)/i'                        => "CAST(strftime('%Y', $1) AS INTEGER)",
		// MONTH(date) -> strftime('%m', date).
		'/\bMONTH\s*\(([^)]+)\)/i'                       => "CAST(strftime('%m', $1) AS INTEGER)",
		// DAY(date) -> strftime('%d', date).
		'/\bDAY\s*\(([^)]+)\)/i'                         => "CAST(strftime('%d', $1) AS INTEGER)",
		// DAYOFMONTH(date) -> strftime('%d', date).
		'/\bDAYOFMONTH\s*\(([^)]+)\)/i'                  => "CAST(strftime('%d', $1) AS INTEGER)",
		// DAYOFWEEK(date) -> strftime('%w', date) + 1 (MySQL is 1-7, SQLite is 0-6).
		'/\bDAYOFWEEK\s*\(([^)]+)\)/i'                   => "(CAST(strftime('%w', $1) AS INTEGER) + 1)",
		// DAYOFYEAR(date) -> strftime('%j', date).
		'/\bDAYOFYEAR\s*\(([^)]+)\)/i'                   => "CAST(strftime('%j', $1) AS INTEGER)",
		// WEEKDAY(date) -> strftime('%w', date) (0=Monday in MySQL, but 0=Sunday in SQLite - approximate).
		'/\bWEEKDAY\s*\(([^)]+)\)/i'                     => "((CAST(strftime('%w', $1) AS INTEGER) + 6) % 7)",
		// HOUR(date) -> strftime('%H', date).
		'/\bHOUR\s*\(([^)]+)\)/i'                        => "CAST(strftime('%H', $1) AS INTEGER)",
		// MINUTE(date) -> strftime('%M', date).
		'/\bMINUTE\s*\(([^)]+)\)/i'                      => "CAST(strftime('%M', $1) AS INTEGER)",
		// SECOND(date) -> strftime('%S', date).
		'/\bSECOND\s*\(([^)]+)\)/i'                      => "CAST(strftime('%S', $1) AS INTEGER)",
		// DATE(expr) -> date(expr).
		'/\bDATE\s*\(([^)]+)\)/i'                        => "date($1)",
		// TIME(expr) -> time(expr).
		'/\bTIME\s*\(([^)]+)\)/i'                        => "time($1)",
		// DATEDIFF(a, b) -> julianday(a) - julianday(b).
		'/\bDATEDIFF\s*\(([^,]+),\s*([^)]+)\)/i'         => 'CAST(julianday($1) - julianday($2) AS INTEGER)',
		// DATE_ADD(date, INTERVAL n unit) - needs special handling.
		'/\bDATE_ADD\s*\(([^,]+),\s*INTERVAL\s+(-?\d+)\s+(\w+)\)/i' => 'sqliteDateAdd',
		// DATE_SUB(date, INTERVAL n unit) - needs special handling.
		'/\bDATE_SUB\s*\(([^,]+),\s*INTERVAL\s+(-?\d+)\s+(\w+)\)/i' => 'sqliteDateSub',
		// GROUP_CONCAT - basic support.
		'/\bGROUP_CONCAT\s*\(([^)]+)\)/i'                => 'GROUP_CONCAT($1)',
		// SUBSTRING(str, pos, len) -> SUBSTR(str, pos, len).
		'/\bSUBSTRING\s*\(/i'                            => 'SUBSTR(',
		// SUBSTRING_INDEX(str, delim, count) - needs special handling.
		'/\bSUBSTRING_INDEX\s*\(/i'                      => 'sqliteSubstringIndex',
		// LOCATE(substr, str) -> INSTR(str, substr).
		'/\bLOCATE\s*\(([^,]+),\s*([^)]+)\)/i'           => 'INSTR($2, $1)',
		// POSITION(substr IN str) -> INSTR(str, substr).
		'/\bPOSITION\s*\(([^)]+)\s+IN\s+([^)]+)\)/i'     => 'INSTR($2, $1)',
		// LCASE/UCASE -> LOWER/UPPER.
		'/\bLCASE\s*\(/i'                                => 'LOWER(',
		'/\bUCASE\s*\(/i'                                => 'UPPER(',
		// CHAR_LENGTH / CHARACTER_LENGTH -> LENGTH.
		'/\bCHAR_LENGTH\s*\(/i'                          => 'LENGTH(',
		'/\bCHARACTER_LENGTH\s*\(/i'                     => 'LENGTH(',
		// OCTET_LENGTH -> LENGTH (for byte count).
		'/\bOCTET_LENGTH\s*\(/i'                         => 'LENGTH(',
		// BIT_LENGTH -> LENGTH * 8 (approximate).
		'/\bBIT_LENGTH\s*\(([^)]+)\)/i'                  => '(LENGTH($1) * 8)',
		// LEFT(str, len) -> SUBSTR(str, 1, len).
		'/\bLEFT\s*\(([^,]+),\s*([^)]+)\)/i'             => 'SUBSTR($1, 1, $2)',
		// RIGHT(str, len) -> SUBSTR(str, -len).
		'/\bRIGHT\s*\(([^,]+),\s*([^)]+)\)/i'            => 'SUBSTR($1, -$2)',
		// REVERSE(str) - not directly supported, return as-is.
		// REPEAT(str, count) - not directly supported.
		// SPACE(n) -> (create n spaces) - not directly supported.
		// GREATEST(a, b, ...) -> MAX(a, b, ...) - SQLite's MAX works for 2+ args.
		'/\bGREATEST\s*\(/i'                             => 'MAX(',
		// LEAST(a, b, ...) -> MIN(a, b, ...) - SQLite's MIN works for 2+ args.
		'/\bLEAST\s*\(/i'                                => 'MIN(',
		// FIELD(needle, a, b, c) - needs special handling.
		'/\bFIELD\s*\(([^,]+),\s*(.+)\)/i'               => 'sqliteField',
		// ELT(n, a, b, c) - needs special handling.
		'/\bELT\s*\(([^,]+),\s*(.+)\)/i'                 => 'sqliteElt',
		// FIND_IN_SET(needle, set) - needs special handling.
		'/\bFIND_IN_SET\s*\(([^,]+),\s*([^)]+)\)/i'      => 'sqliteFindInSet',
		// MAKE_SET - complex, not supported.
		// TRUNCATE(n, d) -> ROUND(n - 0.5*SIGN(n)*POWER(10,-d), d) (approximate).
		'/\bTRUNCATE\s*\(([^,]+),\s*([^)]+)\)/i'         => 'ROUND($1, $2)',
		// MOD(a, b) -> (a % b).
		'/\bMOD\s*\(([^,]+),\s*([^)]+)\)/i'              => '($1 % $2)',
		// POW/POWER - not available in base SQLite, but some builds have it.
		// ABS is native in SQLite.
		// CEIL/CEILING - not native, approximate with CAST.
		'/\bCEIL\s*\(([^)]+)\)/i'                        => 'CAST($1 + 0.5 AS INTEGER)',
		'/\bCEILING\s*\(([^)]+)\)/i'                     => 'CAST($1 + 0.5 AS INTEGER)',
		// FLOOR - not native, approximate with CAST.
		'/\bFLOOR\s*\(([^)]+)\)/i'                       => 'CAST($1 AS INTEGER)',
		// SIGN(n).
		'/\bSIGN\s*\(([^)]+)\)/i'                        => 'CASE WHEN $1 > 0 THEN 1 WHEN $1 < 0 THEN -1 ELSE 0 END',
		// HEX(str) -> hex() is available in SQLite.
		// UNHEX - not available in SQLite.
		// MD5/SHA1/SHA2 - not available in base SQLite.
		// BINARY cast -> just remove it.
		'/\bBINARY\s+/i'                                 => '',
	];

	/**
	 * Regex patterns for functions with arguments.
	 *
	 * @var array<string, array<string, string|callable>>
	 */
	protected static array $patterns = [
		'sqlite' => [], // Will be populated from $sqlitePatterns.
		'd1'     => [], // Will be populated from $sqlitePatterns (D1 is SQLite-based).
		'pgsql'  => [
			// CONCAT(a, b, c) -> CONCAT(a, b, c) (PostgreSQL supports it).
			// IFNULL(a, b) -> COALESCE(a, b).
			'/\bIFNULL\s*\(([^,]+),\s*([^)]+)\)/i'           => 'COALESCE($1, $2)',
			// IF(cond, true, false) -> CASE WHEN cond THEN true ELSE false END.
			'/\bIF\s*\(([^,]+),\s*([^,]+),\s*([^)]+)\)/i'    => 'CASE WHEN $1 THEN $2 ELSE $3 END',
			// UNIX_TIMESTAMP(date) -> EXTRACT(EPOCH FROM date)::INTEGER.
			'/\bUNIX_TIMESTAMP\s*\(([^)]+)\)/i'              => 'EXTRACT(EPOCH FROM $1)::INTEGER',
			// FROM_UNIXTIME(timestamp) -> to_timestamp(timestamp).
			'/\bFROM_UNIXTIME\s*\(([^)]+)\)/i'               => 'to_timestamp($1)',
			// RAND() -> RANDOM().
			'/\bRAND\s*\(\s*\)/i'                            => 'RANDOM()',
			// GROUP_CONCAT -> STRING_AGG.
			'/\bGROUP_CONCAT\s*\(([^)]+)\)/i'                => "STRING_AGG($1::text, ',')",
			// SUBSTRING - PostgreSQL supports it.
			// DATE_FORMAT - needs special handling for PostgreSQL.
			'/\bDATE_FORMAT\s*\(([^,]+),\s*([^)]+)\)/i'      => 'pgsqlDateFormat',
			// LCASE/UCASE -> LOWER/UPPER.
			'/\bLCASE\s*\(/i'                                => 'LOWER(',
			'/\bUCASE\s*\(/i'                                => 'UPPER(',
			// CHAR_LENGTH / CHARACTER_LENGTH - PostgreSQL supports both.
			// FIND_IN_SET - needs special handling.
			'/\bFIND_IN_SET\s*\(([^,]+),\s*([^)]+)\)/i'      => 'pgsqlFindInSet',
		],
		'mysql'  => [
			// No transformations for MySQL.
		],
	];

	/**
	 * MySQL to SQLite date format mapping.
	 *
	 * @var array<string, string>
	 */
	protected static array $mysql_to_sqlite_date_formats = [
		'%Y' => '%Y',    // 4-digit year
		'%y' => '%y',    // 2-digit year
		'%m' => '%m',    // Month 01-12
		'%c' => '%m',    // Month 1-12 (no leading zero - SQLite doesn't support, use %m)
		'%d' => '%d',    // Day 01-31
		'%e' => '%d',    // Day 1-31 (no leading zero - SQLite doesn't support, use %d)
		'%H' => '%H',    // Hour 00-23
		'%h' => '%H',    // Hour 01-12 (SQLite doesn't have 12-hour, use 24-hour)
		'%i' => '%M',    // Minutes 00-59
		'%s' => '%S',    // Seconds 00-59
		'%p' => '',      // AM/PM (not supported in SQLite strftime)
		'%W' => '%w',    // Weekday name (partial support)
		'%M' => '%m',    // Month name (partial support - returns number)
		'%D' => '%d',    // Day with suffix (partial support)
	];

	/**
	 * Constructor.
	 *
	 * @param Connection $connection DBAL connection.
	 */
	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
		$this->platform   = $this->detectPlatform();

		// Initialize SQLite-compatible mappings and patterns for sqlite and d1 platforms.
		// This avoids code duplication since D1 is SQLite-based.
		if (empty(self::$mappings['sqlite'])) {
			self::$mappings['sqlite'] = self::$sqliteMappings;
		}
		if (empty(self::$mappings['d1'])) {
			self::$mappings['d1'] = self::$sqliteMappings;
		}
		if (empty(self::$patterns['sqlite'])) {
			self::$patterns['sqlite'] = self::$sqlitePatterns;
		}
		if (empty(self::$patterns['d1'])) {
			self::$patterns['d1'] = self::$sqlitePatterns;
		}
	}

	/**
	 * Translate MySQL functions in an expression to platform-specific equivalents.
	 *
	 * @param string $expression The SQL expression.
	 * @return string The translated expression.
	 */
	public function translate(string $expression): string
	{
		if ('mysql' === $this->platform) {
			// No translation needed for MySQL.
			return $expression;
		}

		$result = $expression;

		// Handle CONCAT specially first (needs proper parentheses matching).
		if (\in_array($this->platform, ['sqlite', 'd1'], true) && \preg_match('/\bCONCAT\s*\(/i', $result)) {
			$result = $this->translateConcat($result);
		}

		// Handle CONCAT_WS specially (needs proper parentheses matching).
		if (\in_array($this->platform, ['sqlite', 'd1'], true) && \preg_match('/\bCONCAT_WS\s*\(/i', $result)) {
			$result = $this->translateConcatWs($result);
		}

		// Handle LIKE escape sequences for SQLite and D1 (SQLite-based).
		// MySQL uses \_ and \% by default, SQLite needs ESCAPE clause.
		if (\in_array($this->platform, ['sqlite', 'd1'], true)) {
			$result = $this->translateLikeEscapes($result);
		}

		// Apply simple replacements.
		$mappings = self::$mappings[ $this->platform ] ?? [];
		foreach ($mappings as $mysql => $replacement) {
			$result = \str_ireplace($mysql, $replacement, $result);
		}

		// Apply pattern-based replacements.
		$patterns = self::$patterns[ $this->platform ] ?? [];
		foreach ($patterns as $pattern => $replacement) {
			// Skip CONCAT and CONCAT_WS patterns - handled above with proper parentheses matching.
			if (\str_contains($pattern, 'CONCAT')) {
				continue;
			}

			// Skip SUBSTRING_INDEX - complex function not fully supported.
			if (\str_contains($pattern, 'SUBSTRING_INDEX')) {
				continue;
			}

			if (\is_string($replacement) && \str_starts_with($replacement, 'sqlite')) {
				// Special handler method.
				$result = $this->applySpecialHandler($result, $pattern, $replacement);
			} elseif (\is_string($replacement) && \str_starts_with($replacement, 'pgsql')) {
				// Special handler method.
				$result = $this->applySpecialHandler($result, $pattern, $replacement);
			} else {
				$result = \preg_replace($pattern, $replacement, $result) ?? $result;
			}
		}

		return $result;
	}

	/**
	 * Apply special handler for complex function translations.
	 *
	 * @param string $expression The expression.
	 * @param string $pattern    The regex pattern.
	 * @param string $handler    The handler name.
	 * @return string The translated expression.
	 */
	protected function applySpecialHandler(string $expression, string $pattern, string $handler): string
	{
		return \preg_replace_callback(
			$pattern,
			fn($matches) => $this->$handler($matches),
			$expression
		) ?? $expression;
	}

	/**
	 * Handle SQLite CONCAT translation start - finds matching parentheses.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Placeholder - actual replacement done in translate().
	 */
	protected function sqliteConcatStart(array $matches): string
	{
		// This is just a marker - actual handling is done in translate().
		return $matches[0];
	}

	/**
	 * Translate MySQL LIKE escape sequences for SQLite.
	 *
	 * MySQL uses \_ and \% by default to escape wildcards in LIKE.
	 * SQLite requires explicit ESCAPE clause: LIKE 'pattern' ESCAPE '\'.
	 *
	 * @param string $expression The expression containing LIKE patterns.
	 * @return string Translated expression with ESCAPE clauses added.
	 */
	protected function translateLikeEscapes(string $expression): string
	{
		// Pattern to find LIKE/NOT LIKE followed by a string literal containing backslash escapes.
		// We need to handle: LIKE '\_%', LIKE '%\_%', NOT LIKE '\_%', etc.
		$pattern = '/(\bLIKE\s+)(\'[^\']*\\\\[_%][^\']*\'|"[^"]*\\\\[_%][^"]*")/i';

		return \preg_replace_callback(
			$pattern,
			function ($matches) {
				$likeKeyword = $matches[1];
				$patternStr = $matches[2];

				// Check if this LIKE already has an ESCAPE clause following it.
				// We'll add ESCAPE '\' after the pattern.
				return $likeKeyword . $patternStr . " ESCAPE '\\'";
			},
			$expression
		) ?? $expression;
	}

	/**
	 * Handle SQLite CONCAT translation with proper parentheses matching.
	 *
	 * @param string $expression The expression containing CONCAT.
	 * @return string Translated expression.
	 */
	protected function translateConcat(string $expression): string
	{
		// Find CONCAT( and match the closing parenthesis properly.
		$pattern = '/\bCONCAT\s*\(/i';

		while (\preg_match($pattern, $expression, $matches, PREG_OFFSET_CAPTURE)) {
			$start = $matches[0][1];
			$openParen = $start + \strlen($matches[0][0]) - 1;

			// Find the matching closing parenthesis.
			$depth = 1;
			$pos = $openParen + 1;
			$len = \strlen($expression);
			$inString = false;
			$stringChar = '';

			while ($pos < $len && $depth > 0) {
				$char = $expression[ $pos ];

				// Handle string literals.
				if (! $inString && ( "'" === $char || '"' === $char )) {
					$inString = true;
					$stringChar = $char;
				} elseif ($inString && $char === $stringChar) {
					// Check for escaped quote.
					if ($pos + 1 < $len && $expression[ $pos + 1 ] === $stringChar) {
						$pos++; // Skip escaped quote.
					} else {
						$inString = false;
					}
				} elseif (! $inString) {
					if ('(' === $char) {
						$depth++;
					} elseif (')' === $char) {
						$depth--;
					}
				}
				$pos++;
			}

			if (0 === $depth) {
				// Extract the arguments.
				$argsStr = \substr($expression, $openParen + 1, $pos - $openParen - 2);

				// Parse arguments respecting parentheses and quotes.
				$args = $this->parseFunctionArgs($argsStr);

				// Translate each argument recursively.
				$translatedArgs = \array_map(fn($arg) => $this->translate(\trim($arg)), $args);

				// Build SQLite concatenation.
				$replacement = '(' . \implode(' || ', $translatedArgs) . ')';

				// Replace in expression.
				$expression = \substr($expression, 0, $start) . $replacement . \substr($expression, $pos);
			} else {
				// Couldn't find matching paren - break to avoid infinite loop.
				break;
			}
		}

		return $expression;
	}

	/**
	 * Handle SQLite CONCAT_WS translation with proper parentheses matching.
	 *
	 * CONCAT_WS(separator, str1, str2, ...) concatenates with separator, skipping NULLs.
	 *
	 * @param string $expression The expression containing CONCAT_WS.
	 * @return string Translated expression.
	 */
	protected function translateConcatWs(string $expression): string
	{
		// Find CONCAT_WS( and match the closing parenthesis properly.
		$pattern = '/\bCONCAT_WS\s*\(/i';

		while (\preg_match($pattern, $expression, $matches, PREG_OFFSET_CAPTURE)) {
			$start = $matches[0][1];
			$openParen = $start + \strlen($matches[0][0]) - 1;

			// Find the matching closing parenthesis.
			$depth = 1;
			$pos = $openParen + 1;
			$len = \strlen($expression);
			$inString = false;
			$stringChar = '';

			while ($pos < $len && $depth > 0) {
				$char = $expression[ $pos ];

				// Handle string literals.
				if (! $inString && ( "'" === $char || '"' === $char )) {
					$inString = true;
					$stringChar = $char;
				} elseif ($inString && $char === $stringChar) {
					// Check for escaped quote.
					if ($pos + 1 < $len && $expression[ $pos + 1 ] === $stringChar) {
						$pos++; // Skip escaped quote.
					} else {
						$inString = false;
					}
				} elseif (! $inString) {
					if ('(' === $char) {
						$depth++;
					} elseif (')' === $char) {
						$depth--;
					}
				}
				$pos++;
			}

			if (0 === $depth) {
				// Extract the arguments.
				$argsStr = \substr($expression, $openParen + 1, $pos - $openParen - 2);

				// Parse arguments respecting parentheses and quotes.
				$args = $this->parseFunctionArgs($argsStr);

				if (\count($args) < 2) {
					// Invalid CONCAT_WS - needs at least separator and one string.
					break;
				}

				// First argument is the separator.
				$separator = $this->translate(\trim($args[0]));
				$strings = \array_slice($args, 1);

				// Translate each string argument recursively.
				$translatedStrings = \array_map(fn($arg) => $this->translate(\trim($arg)), $strings);

				// Build SQLite concatenation with separator.
				// Use COALESCE to handle NULLs - we replace NULLs with empty string
				// then filter out empty strings from the concatenation.
				// Note: This is a simplified implementation that doesn't perfectly match
				// MySQL's NULL-skipping behavior but works for most cases.
				$parts = [];
				foreach ($translatedStrings as $str) {
					$parts[] = "COALESCE({$str}, '')";
				}

				// Join with separator (simplified - doesn't skip NULLs perfectly).
				$replacement = '(' . \implode(' || ' . $separator . ' || ', $parts) . ')';

				// Replace in expression.
				$expression = \substr($expression, 0, $start) . $replacement . \substr($expression, $pos);
			} else {
				// Couldn't find matching paren - break to avoid infinite loop.
				break;
			}
		}

		return $expression;
	}

	/**
	 * Parse function arguments respecting nested parentheses and quotes.
	 *
	 * @param string $argsStr The arguments string.
	 * @return array<string> Array of arguments.
	 */
	protected function parseFunctionArgs(string $argsStr): array
	{
		$args = [];
		$current = '';
		$depth = 0;
		$inString = false;
		$stringChar = '';
		$len = \strlen($argsStr);

		for ($i = 0; $i < $len; $i++) {
			$char = $argsStr[ $i ];

			// Handle string literals.
			if (! $inString && ( "'" === $char || '"' === $char )) {
				$inString = true;
				$stringChar = $char;
				$current .= $char;
			} elseif ($inString && $char === $stringChar) {
				$current .= $char;
				// Check for escaped quote.
				if ($i + 1 < $len && $argsStr[ $i + 1 ] === $stringChar) {
					$current .= $argsStr[ $i + 1 ];
					$i++;
				} else {
					$inString = false;
				}
			} elseif (! $inString) {
				if ('(' === $char) {
					$depth++;
					$current .= $char;
				} elseif (')' === $char) {
					$depth--;
					$current .= $char;
				} elseif (',' === $char && 0 === $depth) {
					$args[] = $current;
					$current = '';
				} else {
					$current .= $char;
				}
			} else {
				$current .= $char;
			}
		}

		if ('' !== $current) {
			$args[] = $current;
		}

		return $args;
	}

	/**
	 * Handle SQLite DATE_FORMAT translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteDateFormat(array $matches): string
	{
		$date   = \trim($matches[1]);
		$format = \trim($matches[2], "\"'");

		// Convert MySQL format to SQLite strftime format.
		$sqliteFormat = $format;
		foreach (self::$mysql_to_sqlite_date_formats as $mysql => $sqlite) {
			$sqliteFormat = \str_replace($mysql, $sqlite, $sqliteFormat);
		}

		return "strftime('{$sqliteFormat}', {$date})";
	}

	/**
	 * Handle SQLite DATE_ADD translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteDateAdd(array $matches): string
	{
		$date   = \trim($matches[1]);
		$amount = (int) $matches[2];
		$unit   = \strtolower(\trim($matches[3]));

		$modifier = match ($unit) {
			'second', 'seconds' => "+{$amount} seconds",
			'minute', 'minutes' => "+{$amount} minutes",
			'hour', 'hours'     => "+{$amount} hours",
			'day', 'days'       => "+{$amount} days",
			'week', 'weeks'     => '+' . ( $amount * 7 ) . ' days',
			'month', 'months'   => "+{$amount} months",
			'year', 'years'     => "+{$amount} years",
			default             => "+{$amount} {$unit}",
		};

		return "datetime({$date}, '{$modifier}')";
	}

	/**
	 * Handle SQLite DATE_SUB translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteDateSub(array $matches): string
	{
		$date   = \trim($matches[1]);
		$amount = (int) $matches[2];
		$unit   = \strtolower(\trim($matches[3]));

		$modifier = match ($unit) {
			'second', 'seconds' => "-{$amount} seconds",
			'minute', 'minutes' => "-{$amount} minutes",
			'hour', 'hours'     => "-{$amount} hours",
			'day', 'days'       => "-{$amount} days",
			'week', 'weeks'     => '-' . ( $amount * 7 ) . ' days',
			'month', 'months'   => "-{$amount} months",
			'year', 'years'     => "-{$amount} years",
			default             => "-{$amount} {$unit}",
		};

		return "datetime({$date}, '{$modifier}')";
	}

	/**
	 * Handle SQLite FIELD function translation.
	 *
	 * FIELD(needle, a, b, c) returns position of needle in the list (1-based), or 0 if not found.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteField(array $matches): string
	{
		$needle = \trim($matches[1]);
		$listStr = $matches[2];

		// Parse the list of values.
		$values = $this->parseFunctionArgs($listStr);

		if (empty($values)) {
			return '0';
		}

		// Build CASE WHEN expression.
		$cases = [];
		foreach ($values as $index => $value) {
			$position = $index + 1;
			$value = \trim($value);
			$cases[] = "WHEN {$needle} = {$value} THEN {$position}";
		}

		return '(CASE ' . \implode(' ', $cases) . ' ELSE 0 END)';
	}

	/**
	 * Handle SQLite ELT function translation.
	 *
	 * ELT(n, a, b, c) returns the n-th element (1-based), or NULL if out of range.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteElt(array $matches): string
	{
		$index = \trim($matches[1]);
		$listStr = $matches[2];

		// Parse the list of values.
		$values = $this->parseFunctionArgs($listStr);

		if (empty($values)) {
			return 'NULL';
		}

		// Build CASE WHEN expression.
		$cases = [];
		foreach ($values as $i => $value) {
			$position = $i + 1;
			$value = \trim($value);
			$cases[] = "WHEN {$index} = {$position} THEN {$value}";
		}

		return '(CASE ' . \implode(' ', $cases) . ' ELSE NULL END)';
	}

	/**
	 * Handle SQLite FIND_IN_SET function translation.
	 *
	 * FIND_IN_SET(needle, comma_separated_set) returns the position (1-based)
	 * of needle in the set, or 0 if not found.
	 *
	 * SQLite translation uses INSTR and comma counting.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteFindInSet(array $matches): string
	{
		$needle = \trim($matches[1]);
		$set = \trim($matches[2]);

		// Build a CASE expression that checks if the needle is in the set.
		// We use ',' || set || ',' to ensure proper matching at boundaries.
		// If found, we count the commas before the match position to get the index.
		return "(CASE WHEN INSTR(',' || {$set} || ',', ',' || {$needle} || ',') > 0 " .
			"THEN (LENGTH(SUBSTR(',' || {$set} || ',', 1, INSTR(',' || {$set} || ',', ',' || {$needle} || ','))) - " .
			"LENGTH(REPLACE(SUBSTR(',' || {$set} || ',', 1, INSTR(',' || {$set} || ',', ',' || {$needle} || ',')), ',', '')) + 1) " .
			'ELSE 0 END)';
	}

	/**
	 * Handle PostgreSQL FIND_IN_SET function translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function pgsqlFindInSet(array $matches): string
	{
		$needle = \trim($matches[1]);
		$set = \trim($matches[2]);

		// PostgreSQL: Use string_to_array and array_position.
		return "COALESCE(array_position(string_to_array({$set}, ','), {$needle}::text), 0)";
	}

	/**
	 * Handle SQLite CONCAT_WS translation start.
	 *
	 * CONCAT_WS(separator, str1, str2, ...) concatenates strings with separator.
	 * Unlike CONCAT, it skips NULL values.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Placeholder - actual handling done in translateConcatWs().
	 */
	protected function sqliteConcatWsStart(array $matches): string
	{
		// This is just a marker - actual handling is done in translate().
		return $matches[0];
	}

	/**
	 * Handle SQLite SUBSTRING_INDEX translation.
	 *
	 * SUBSTRING_INDEX(str, delim, count) returns the substring before/after count occurrences of delim.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqliteSubstringIndex(array $matches): string
	{
		// This is a complex function that's difficult to translate to SQLite.
		// For simple cases (count = 1 or -1), we can use SUBSTR and INSTR.
		// For the general case, we'd need a recursive CTE or user-defined function.
		// Return a simplified version that handles common cases.
		return $matches[0]; // Return as-is - not fully supported.
	}

	/**
	 * Handle PostgreSQL DATE_FORMAT translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function pgsqlDateFormat(array $matches): string
	{
		$date   = \trim($matches[1]);
		$format = \trim($matches[2], "\"'");

		// Convert MySQL format to PostgreSQL to_char format.
		$pgsqlFormat = $format;
		$conversions  = [
			'%Y' => 'YYYY',
			'%y' => 'YY',
			'%m' => 'MM',
			'%c' => 'MM',
			'%d' => 'DD',
			'%e' => 'DD',
			'%H' => 'HH24',
			'%h' => 'HH12',
			'%i' => 'MI',
			'%s' => 'SS',
			'%p' => 'AM',
			'%W' => 'Day',
			'%M' => 'Month',
		];

		foreach ($conversions as $mysql => $pgsql) {
			$pgsqlFormat = \str_replace($mysql, $pgsql, $pgsqlFormat);
		}

		return "to_char({$date}, '{$pgsqlFormat}')";
	}

	/**
	 * Detect the database platform.
	 *
	 * @return string Platform name (mysql, sqlite, pgsql).
	 */
	protected function detectPlatform(): string
	{
		$platform = $this->connection->getDatabasePlatform();
		$class    = \get_class($platform);

		return match (true) {
			\str_contains($class, 'SQLite')     => 'sqlite',
			\str_contains($class, 'PostgreSQL') => 'pgsql',
			\str_contains($class, 'MySQL')      => 'mysql',
			\str_contains($class, 'MariaDB')    => 'mysql',
			default                              => 'mysql',
		};
	}
}
