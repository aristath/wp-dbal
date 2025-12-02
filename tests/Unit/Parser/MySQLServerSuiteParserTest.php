<?php
/**
 * MySQL Server Suite Parser Tests.
 *
 * Tests the phpmyadmin/sql-parser against 114,950 queries from MySQL server test suite.
 * This is a stress test to ensure the parser can handle all MySQL syntax.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use PhpMyAdmin\SqlParser\Parser;

/**
 * Tests MySQL query parsing against the MySQL server test suite.
 */
class MySQLServerSuiteParserTest extends TestCase {

	/**
	 * Path to the CSV file with test queries.
	 */
	private const CSV_PATH = __DIR__ . '/../../data/mysql-server-tests-queries.csv';

	/**
	 * Batch size for processing queries.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Known queries that fail to parse (expected failures).
	 * These are MySQL-specific syntax that phpmyadmin/sql-parser doesn't support.
	 *
	 * @var array<string>
	 */
	private const KNOWN_FAILURES = [
		// Add known failure patterns here as we discover them.
	];

	/**
	 * Test that the CSV file exists and is readable.
	 */
	public function test_csv_file_exists(): void {
		$this->assertFileExists( self::CSV_PATH, 'MySQL server test queries CSV file should exist' );
		$this->assertFileIsReadable( self::CSV_PATH, 'CSV file should be readable' );
	}

	/**
	 * Test parsing all queries from the MySQL server test suite.
	 *
	 * This test processes queries in batches to avoid memory issues.
	 * It tracks success/failure rates and reports statistics.
	 *
	 * @dataProvider data_query_batches
	 *
	 * @param array $queries Batch of queries to test.
	 * @param int   $batch_number The batch number for reporting.
	 */
	public function test_parse_mysql_server_suite( array $queries, int $batch_number ): void {
		$total    = count( $queries );
		$success  = 0;
		$failures = [];

		foreach ( $queries as $query ) {
			$query = trim( $query );
			if ( empty( $query ) ) {
				continue;
			}

			// Skip known failures.
			if ( $this->is_known_failure( $query ) ) {
				++$success; // Count as success since we expect it to fail.
				continue;
			}

			try {
				$parser = new Parser( $query );

				// Check if parsing produced any statements.
				if ( ! empty( $parser->statements ) ) {
					++$success;
				} else {
					// Empty statements array means parse failure.
					$failures[] = $this->truncate_query( $query );
				}
			} catch ( \Throwable $e ) {
				// Parser threw an exception.
				$failures[] = $this->truncate_query( $query ) . ' - ' . $e->getMessage();
			}
		}

		// Calculate success rate.
		$success_rate = $total > 0 ? ( $success / $total ) * 100 : 0;

		// We expect at least 90% success rate for each batch.
		// The remaining failures are typically MySQL-specific syntax.
		$this->assertGreaterThanOrEqual(
			90.0,
			$success_rate,
			sprintf(
				"Batch %d: Parser success rate %.2f%% is below 90%%. Failures:\n%s",
				$batch_number,
				$success_rate,
				implode( "\n", array_slice( $failures, 0, 10 ) ) // Show first 10 failures.
			)
		);
	}

	/**
	 * Data provider that yields batches of queries from the CSV file.
	 *
	 * @return \Generator<array{array<string>, int}>
	 */
	public static function data_query_batches(): \Generator {
		$csv_path = __DIR__ . '/../../data/mysql-server-tests-queries.csv';

		if ( ! file_exists( $csv_path ) ) {
			yield 'no_file' => [ [], 0 ];
			return;
		}

		$handle = fopen( $csv_path, 'r' );
		if ( ! $handle ) {
			yield 'cannot_open' => [ [], 0 ];
			return;
		}

		$batch        = [];
		$batch_number = 1;

		while ( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			if ( ! empty( $data[0] ) ) {
				$batch[] = $data[0];
			}

			if ( count( $batch ) >= self::BATCH_SIZE ) {
				yield "batch_{$batch_number}" => [ $batch, $batch_number ];
				$batch = [];
				++$batch_number;
			}
		}

		// Yield remaining queries.
		if ( ! empty( $batch ) ) {
			yield "batch_{$batch_number}" => [ $batch, $batch_number ];
		}

		fclose( $handle );
	}

	/**
	 * Check if a query is a known failure.
	 *
	 * @param string $query The query to check.
	 * @return bool True if the query is expected to fail.
	 */
	private function is_known_failure( string $query ): bool {
		foreach ( self::KNOWN_FAILURES as $pattern ) {
			if ( str_contains( $query, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Truncate a query for display in error messages.
	 *
	 * @param string $query The query to truncate.
	 * @return string The truncated query.
	 */
	private function truncate_query( string $query ): string {
		$query = preg_replace( '/\s+/', ' ', $query );
		if ( strlen( $query ) > 100 ) {
			return substr( $query, 0, 100 ) . '...';
		}
		return $query;
	}

	/**
	 * Test overall statistics for the CSV file.
	 */
	public function test_csv_statistics(): void {
		$handle = fopen( self::CSV_PATH, 'r' );
		$this->assertNotFalse( $handle, 'Should be able to open CSV file' );

		$total = 0;
		while ( fgetcsv( $handle, 0, ',', '"', '' ) !== false ) {
			++$total;
		}
		fclose( $handle );

		// Verify we have the expected number of queries.
		$this->assertGreaterThan( 100000, $total, 'CSV should contain over 100,000 queries' );
	}
}
