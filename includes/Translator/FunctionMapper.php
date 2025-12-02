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
class FunctionMapper {

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
	 * Function mappings per platform.
	 *
	 * @var array<string, array<string, string|callable>>
	 */
	protected static array $mappings = [
		'sqlite' => [
			// Date/Time functions.
			'NOW()'                 => "datetime('now', 'localtime')",
			'CURDATE()'             => "date('now', 'localtime')",
			'CURTIME()'             => "time('now', 'localtime')",
			'UTC_TIMESTAMP()'       => "datetime('now')",
			'UNIX_TIMESTAMP()'      => "strftime('%s', 'now')",

			// String functions.
			'RAND()'                => 'RANDOM() / 18446744073709551616 + 0.5',

			// Other.
			'FOUND_ROWS()'          => '0', // Not supported in SQLite.
			'LAST_INSERT_ID()'      => 'last_insert_rowid()',

			// CHAR_LENGTH -> LENGTH (same in SQLite for UTF-8).
			// Note: SQLite's LENGTH returns byte count for BLOBs but character count for TEXT.
		],
		'pgsql'  => [
			// Date/Time functions.
			'NOW()'                 => 'NOW()',
			'CURDATE()'             => 'CURRENT_DATE',
			'CURTIME()'             => 'CURRENT_TIME',
			'UTC_TIMESTAMP()'       => "(NOW() AT TIME ZONE 'UTC')",
			'UNIX_TIMESTAMP()'      => 'EXTRACT(EPOCH FROM NOW())::INTEGER',

			// String functions.
			'RAND()'                => 'RANDOM()',

			// Other.
			'FOUND_ROWS()'          => '0', // Not directly supported.
			'LAST_INSERT_ID()'      => 'lastval()',
		],
		'mysql'  => [
			// MySQL passthrough - no changes needed.
		],
	];

	/**
	 * Regex patterns for functions with arguments.
	 *
	 * @var array<string, array<string, string|callable>>
	 */
	protected static array $patterns = [
		'sqlite' => [
			// CONCAT handled specially due to nested parentheses
			'/\bCONCAT\s*\(/i'                               => 'sqlite_concat_start',
			// IFNULL(a, b) -> COALESCE(a, b)
			'/\bIFNULL\s*\(([^,]+),\s*([^)]+)\)/i'           => 'COALESCE($1, $2)',
			// IF(cond, true, false) -> CASE WHEN cond THEN true ELSE false END
			'/\bIF\s*\(([^,]+),\s*([^,]+),\s*([^)]+)\)/i'    => 'CASE WHEN $1 THEN $2 ELSE $3 END',
			// UNIX_TIMESTAMP(date) -> strftime('%s', date)
			'/\bUNIX_TIMESTAMP\s*\(([^)]+)\)/i'              => "strftime('%s', $1)",
			// DATE_FORMAT(date, format) - needs special handling
			'/\bDATE_FORMAT\s*\(([^,]+),\s*([^)]+)\)/i'      => 'sqlite_date_format',
			// YEAR(date) -> strftime('%Y', date)
			'/\bYEAR\s*\(([^)]+)\)/i'                        => "strftime('%Y', $1)",
			// MONTH(date) -> strftime('%m', date)
			'/\bMONTH\s*\(([^)]+)\)/i'                       => "strftime('%m', $1)",
			// DAY(date) -> strftime('%d', date)
			'/\bDAY\s*\(([^)]+)\)/i'                         => "strftime('%d', $1)",
			// HOUR(date) -> strftime('%H', date)
			'/\bHOUR\s*\(([^)]+)\)/i'                        => "strftime('%H', $1)",
			// MINUTE(date) -> strftime('%M', date)
			'/\bMINUTE\s*\(([^)]+)\)/i'                      => "strftime('%M', $1)",
			// SECOND(date) -> strftime('%S', date)
			'/\bSECOND\s*\(([^)]+)\)/i'                      => "strftime('%S', $1)",
			// DATEDIFF(a, b) -> julianday(a) - julianday(b)
			'/\bDATEDIFF\s*\(([^,]+),\s*([^)]+)\)/i'         => 'CAST(julianday($1) - julianday($2) AS INTEGER)',
			// DATE_ADD(date, INTERVAL n unit) - needs special handling
			'/\bDATE_ADD\s*\(([^,]+),\s*INTERVAL\s+(\d+)\s+(\w+)\)/i' => 'sqlite_date_add',
			// DATE_SUB(date, INTERVAL n unit) - needs special handling
			'/\bDATE_SUB\s*\(([^,]+),\s*INTERVAL\s+(\d+)\s+(\w+)\)/i' => 'sqlite_date_sub',
			// GROUP_CONCAT - basic support
			'/\bGROUP_CONCAT\s*\(([^)]+)\)/i'                => 'GROUP_CONCAT($1)',
			// SUBSTRING(str, pos, len) -> SUBSTR(str, pos, len)
			'/\bSUBSTRING\s*\(/i'                            => 'SUBSTR(',
			// LOCATE(substr, str) -> INSTR(str, substr)
			'/\bLOCATE\s*\(([^,]+),\s*([^)]+)\)/i'           => 'INSTR($2, $1)',
			// LCASE/UCASE -> LOWER/UPPER
			'/\bLCASE\s*\(/i'                                => 'LOWER(',
			'/\bUCASE\s*\(/i'                                => 'UPPER(',
			// CHAR_LENGTH -> LENGTH
			'/\bCHAR_LENGTH\s*\(/i'                          => 'LENGTH(',
			// GREATEST(a, b, ...) -> MAX(a, b, ...) - SQLite's MAX works for 2+ args
			'/\bGREATEST\s*\(/i'                             => 'MAX(',
			// LEAST(a, b, ...) -> MIN(a, b, ...) - SQLite's MIN works for 2+ args
			'/\bLEAST\s*\(/i'                                => 'MIN(',
			// FIELD(needle, a, b, c) - needs special handling
			'/\bFIELD\s*\(([^,]+),\s*(.+)\)/i'               => 'sqlite_field',
			// ELT(n, a, b, c) - needs special handling
			'/\bELT\s*\(([^,]+),\s*(.+)\)/i'                 => 'sqlite_elt',
		],
		'pgsql'  => [
			// CONCAT(a, b, c) -> CONCAT(a, b, c) (PostgreSQL supports it)
			// IFNULL(a, b) -> COALESCE(a, b)
			'/\bIFNULL\s*\(([^,]+),\s*([^)]+)\)/i'           => 'COALESCE($1, $2)',
			// IF(cond, true, false) -> CASE WHEN cond THEN true ELSE false END
			'/\bIF\s*\(([^,]+),\s*([^,]+),\s*([^)]+)\)/i'    => 'CASE WHEN $1 THEN $2 ELSE $3 END',
			// UNIX_TIMESTAMP(date) -> EXTRACT(EPOCH FROM date)::INTEGER
			'/\bUNIX_TIMESTAMP\s*\(([^)]+)\)/i'              => 'EXTRACT(EPOCH FROM $1)::INTEGER',
			// RAND() -> RANDOM()
			'/\bRAND\s*\(\s*\)/i'                            => 'RANDOM()',
			// GROUP_CONCAT -> STRING_AGG
			'/\bGROUP_CONCAT\s*\(([^)]+)\)/i'                => "STRING_AGG($1::text, ',')",
			// SUBSTRING - PostgreSQL supports it
			// DATE_FORMAT - needs special handling for PostgreSQL
			'/\bDATE_FORMAT\s*\(([^,]+),\s*([^)]+)\)/i'      => 'pgsql_date_format',
			// LCASE/UCASE -> LOWER/UPPER
			'/\bLCASE\s*\(/i'                                => 'LOWER(',
			'/\bUCASE\s*\(/i'                                => 'UPPER(',
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
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
		$this->platform   = $this->detect_platform();
	}

	/**
	 * Translate MySQL functions in an expression to platform-specific equivalents.
	 *
	 * @param string $expression The SQL expression.
	 * @return string The translated expression.
	 */
	public function translate( string $expression ): string {
		if ( 'mysql' === $this->platform ) {
			// No translation needed for MySQL.
			return $expression;
		}

		$result = $expression;

		// Handle CONCAT specially first (needs proper parentheses matching).
		if ( 'sqlite' === $this->platform && preg_match( '/\bCONCAT\s*\(/i', $result ) ) {
			$result = $this->translate_concat( $result );
		}

		// Handle LIKE escape sequences for SQLite.
		// MySQL uses \_ and \% by default, SQLite needs ESCAPE clause.
		if ( 'sqlite' === $this->platform ) {
			$result = $this->translate_like_escapes( $result );
		}

		// Apply simple replacements.
		$mappings = self::$mappings[ $this->platform ] ?? [];
		foreach ( $mappings as $mysql => $replacement ) {
			$result = str_ireplace( $mysql, $replacement, $result );
		}

		// Apply pattern-based replacements.
		$patterns = self::$patterns[ $this->platform ] ?? [];
		foreach ( $patterns as $pattern => $replacement ) {
			// Skip CONCAT pattern - handled above.
			if ( str_contains( $pattern, 'CONCAT' ) ) {
				continue;
			}

			if ( is_string( $replacement ) && str_starts_with( $replacement, 'sqlite_' ) ) {
				// Special handler method.
				$result = $this->apply_special_handler( $result, $pattern, $replacement );
			} elseif ( is_string( $replacement ) && str_starts_with( $replacement, 'pgsql_' ) ) {
				// Special handler method.
				$result = $this->apply_special_handler( $result, $pattern, $replacement );
			} else {
				$result = preg_replace( $pattern, $replacement, $result ) ?? $result;
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
	protected function apply_special_handler( string $expression, string $pattern, string $handler ): string {
		return preg_replace_callback(
			$pattern,
			fn( $matches ) => $this->$handler( $matches ),
			$expression
		) ?? $expression;
	}

	/**
	 * Handle SQLite CONCAT translation start - finds matching parentheses.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Placeholder - actual replacement done in translate().
	 */
	protected function sqlite_concat_start( array $matches ): string {
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
	protected function translate_like_escapes( string $expression ): string {
		// Pattern to find LIKE/NOT LIKE followed by a string literal containing backslash escapes.
		// We need to handle: LIKE '\_%', LIKE '%\_%', NOT LIKE '\_%', etc.
		$pattern = '/(\bLIKE\s+)(\'[^\']*\\\\[_%][^\']*\'|"[^"]*\\\\[_%][^"]*")/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$like_keyword = $matches[1];
				$pattern_str = $matches[2];

				// Check if this LIKE already has an ESCAPE clause following it.
				// We'll add ESCAPE '\' after the pattern.
				return $like_keyword . $pattern_str . " ESCAPE '\\'";
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
	protected function translate_concat( string $expression ): string {
		// Find CONCAT( and match the closing parenthesis properly.
		$pattern = '/\bCONCAT\s*\(/i';

		while ( preg_match( $pattern, $expression, $matches, PREG_OFFSET_CAPTURE ) ) {
			$start = $matches[0][1];
			$open_paren = $start + strlen( $matches[0][0] ) - 1;

			// Find the matching closing parenthesis.
			$depth = 1;
			$pos = $open_paren + 1;
			$len = strlen( $expression );
			$in_string = false;
			$string_char = '';

			while ( $pos < $len && $depth > 0 ) {
				$char = $expression[ $pos ];

				// Handle string literals.
				if ( ! $in_string && ( "'" === $char || '"' === $char ) ) {
					$in_string = true;
					$string_char = $char;
				} elseif ( $in_string && $char === $string_char ) {
					// Check for escaped quote.
					if ( $pos + 1 < $len && $expression[ $pos + 1 ] === $string_char ) {
						$pos++; // Skip escaped quote.
					} else {
						$in_string = false;
					}
				} elseif ( ! $in_string ) {
					if ( '(' === $char ) {
						$depth++;
					} elseif ( ')' === $char ) {
						$depth--;
					}
				}
				$pos++;
			}

			if ( 0 === $depth ) {
				// Extract the arguments.
				$args_str = substr( $expression, $open_paren + 1, $pos - $open_paren - 2 );

				// Parse arguments respecting parentheses and quotes.
				$args = $this->parse_function_args( $args_str );

				// Translate each argument recursively.
				$translated_args = array_map( fn( $arg ) => $this->translate( trim( $arg ) ), $args );

				// Build SQLite concatenation.
				$replacement = '(' . implode( ' || ', $translated_args ) . ')';

				// Replace in expression.
				$expression = substr( $expression, 0, $start ) . $replacement . substr( $expression, $pos );
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
	 * @param string $args_str The arguments string.
	 * @return array<string> Array of arguments.
	 */
	protected function parse_function_args( string $args_str ): array {
		$args = [];
		$current = '';
		$depth = 0;
		$in_string = false;
		$string_char = '';
		$len = strlen( $args_str );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $args_str[ $i ];

			// Handle string literals.
			if ( ! $in_string && ( "'" === $char || '"' === $char ) ) {
				$in_string = true;
				$string_char = $char;
				$current .= $char;
			} elseif ( $in_string && $char === $string_char ) {
				$current .= $char;
				// Check for escaped quote.
				if ( $i + 1 < $len && $args_str[ $i + 1 ] === $string_char ) {
					$current .= $args_str[ $i + 1 ];
					$i++;
				} else {
					$in_string = false;
				}
			} elseif ( ! $in_string ) {
				if ( '(' === $char ) {
					$depth++;
					$current .= $char;
				} elseif ( ')' === $char ) {
					$depth--;
					$current .= $char;
				} elseif ( ',' === $char && 0 === $depth ) {
					$args[] = $current;
					$current = '';
				} else {
					$current .= $char;
				}
			} else {
				$current .= $char;
			}
		}

		if ( '' !== $current ) {
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
	protected function sqlite_date_format( array $matches ): string {
		$date   = trim( $matches[1] );
		$format = trim( $matches[2], "\"'" );

		// Convert MySQL format to SQLite strftime format.
		$sqlite_format = $format;
		foreach ( self::$mysql_to_sqlite_date_formats as $mysql => $sqlite ) {
			$sqlite_format = str_replace( $mysql, $sqlite, $sqlite_format );
		}

		return "strftime('{$sqlite_format}', {$date})";
	}

	/**
	 * Handle SQLite DATE_ADD translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqlite_date_add( array $matches ): string {
		$date   = trim( $matches[1] );
		$amount = (int) $matches[2];
		$unit   = strtolower( trim( $matches[3] ) );

		$modifier = match ( $unit ) {
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
	protected function sqlite_date_sub( array $matches ): string {
		$date   = trim( $matches[1] );
		$amount = (int) $matches[2];
		$unit   = strtolower( trim( $matches[3] ) );

		$modifier = match ( $unit ) {
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
	protected function sqlite_field( array $matches ): string {
		$needle = trim( $matches[1] );
		$list_str = $matches[2];

		// Parse the list of values.
		$values = $this->parse_function_args( $list_str );

		if ( empty( $values ) ) {
			return '0';
		}

		// Build CASE WHEN expression.
		$cases = [];
		foreach ( $values as $index => $value ) {
			$position = $index + 1;
			$value = trim( $value );
			$cases[] = "WHEN {$needle} = {$value} THEN {$position}";
		}

		return '(CASE ' . implode( ' ', $cases ) . ' ELSE 0 END)';
	}

	/**
	 * Handle SQLite ELT function translation.
	 *
	 * ELT(n, a, b, c) returns the n-th element (1-based), or NULL if out of range.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function sqlite_elt( array $matches ): string {
		$index = trim( $matches[1] );
		$list_str = $matches[2];

		// Parse the list of values.
		$values = $this->parse_function_args( $list_str );

		if ( empty( $values ) ) {
			return 'NULL';
		}

		// Build CASE WHEN expression.
		$cases = [];
		foreach ( $values as $i => $value ) {
			$position = $i + 1;
			$value = trim( $value );
			$cases[] = "WHEN {$index} = {$position} THEN {$value}";
		}

		return '(CASE ' . implode( ' ', $cases ) . ' ELSE NULL END)';
	}

	/**
	 * Handle PostgreSQL DATE_FORMAT translation.
	 *
	 * @param array<int, string> $matches Regex matches.
	 * @return string Translated expression.
	 */
	protected function pgsql_date_format( array $matches ): string {
		$date   = trim( $matches[1] );
		$format = trim( $matches[2], "\"'" );

		// Convert MySQL format to PostgreSQL to_char format.
		$pgsql_format = $format;
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

		foreach ( $conversions as $mysql => $pgsql ) {
			$pgsql_format = str_replace( $mysql, $pgsql, $pgsql_format );
		}

		return "to_char({$date}, '{$pgsql_format}')";
	}

	/**
	 * Detect the database platform.
	 *
	 * @return string Platform name (mysql, sqlite, pgsql).
	 */
	protected function detect_platform(): string {
		$platform = $this->connection->getDatabasePlatform();
		$class    = get_class( $platform );

		return match ( true ) {
			str_contains( $class, 'SQLite' )     => 'sqlite',
			str_contains( $class, 'PostgreSQL' ) => 'pgsql',
			str_contains( $class, 'MySQL' )      => 'mysql',
			str_contains( $class, 'MariaDB' )    => 'mysql',
			default                              => 'mysql',
		};
	}
}
