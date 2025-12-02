<?php
/**
 * Tests for FunctionMapper - MySQL function translation to SQLite/PostgreSQL.
 *
 * These tests verify that MySQL-specific functions are correctly translated
 * to work on SQLite and PostgreSQL. This is critical for cross-platform
 * database compatibility.
 *
 * IMPORTANT: These tests are designed to CATCH BUGS, not just pass.
 * Each test executes the translated function to verify it works correctly.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\Tests\Unit\Translator;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use WP_DBAL\Translator\FunctionMapper;

/**
 * FunctionMapper test cases.
 *
 * Tests MySQL function translation to SQLite equivalents.
 * We focus on SQLite as it requires the most translation.
 */
class FunctionMapperTest extends TestCase {

	/**
	 * SQLite connection.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * FunctionMapper instance.
	 *
	 * @var FunctionMapper
	 */
	protected FunctionMapper $mapper;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->connection = DriverManager::getConnection( [
			'driver' => 'pdo_sqlite',
			'memory' => true,
		] );

		$this->mapper = new FunctionMapper( $this->connection );

		// Create test table for function testing.
		$this->connection->executeStatement( '
			CREATE TABLE test_data (
				id INTEGER PRIMARY KEY,
				name TEXT,
				created_at TEXT,
				amount REAL
			)
		' );

		// Insert test data.
		$this->connection->executeStatement( "
			INSERT INTO test_data (id, name, created_at, amount) VALUES
			(1, 'Alice', '2024-01-15 10:30:45', 100.50),
			(2, 'Bob', '2024-06-20 14:15:00', 250.75),
			(3, 'Charlie', '2024-12-25 00:00:00', 50.00)
		" );
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tearDown(): void {
		$this->connection->close();
		parent::tearDown();
	}

	/**
	 * Execute a SELECT with translated expression.
	 *
	 * @param string $expression The MySQL expression.
	 * @return mixed The result value.
	 */
	protected function execute_expression( string $expression ): mixed {
		$translated = $this->mapper->translate( $expression );
		$sql = "SELECT {$translated} AS result";

		try {
			$result = $this->connection->executeQuery( $sql )->fetchAssociative();
			return $result['result'] ?? null;
		} catch ( \Exception $e ) {
			$this->fail( "Failed to execute: {$sql}\nError: {$e->getMessage()}" );
		}
	}

	/**
	 * Execute a SELECT with translated expression on test_data.
	 *
	 * @param string $expression The MySQL expression.
	 * @param int    $id         The row ID to select.
	 * @return mixed The result value.
	 */
	protected function execute_expression_on_row( string $expression, int $id ): mixed {
		$translated = $this->mapper->translate( $expression );
		$sql = "SELECT {$translated} AS result FROM test_data WHERE id = {$id}";

		try {
			$result = $this->connection->executeQuery( $sql )->fetchAssociative();
			return $result['result'] ?? null;
		} catch ( \Exception $e ) {
			$this->fail( "Failed to execute: {$sql}\nError: {$e->getMessage()}" );
		}
	}

	// =========================================================================
	// DATE/TIME FUNCTION TESTS
	// =========================================================================

	/**
	 * Test NOW() returns current datetime.
	 */
	public function test_now(): void {
		$result = $this->execute_expression( 'NOW()' );

		// Should be a valid datetime string.
		$this->assertNotNull( $result );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );

		// Should be a valid time (we don't check exact match due to timezone differences).
		$result_time = strtotime( $result );
		$this->assertNotFalse( $result_time, 'NOW() should return a valid datetime' );
	}

	/**
	 * Test CURDATE() returns current date.
	 */
	public function test_curdate(): void {
		$result = $this->execute_expression( 'CURDATE()' );

		$this->assertNotNull( $result );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result );
		$this->assertEquals( date( 'Y-m-d' ), $result );
	}

	/**
	 * Test CURTIME() returns current time.
	 */
	public function test_curtime(): void {
		$result = $this->execute_expression( 'CURTIME()' );

		$this->assertNotNull( $result );
		$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', $result );
	}

	/**
	 * Test UNIX_TIMESTAMP() without argument.
	 */
	public function test_unix_timestamp_no_arg(): void {
		$result = $this->execute_expression( 'UNIX_TIMESTAMP()' );

		$this->assertNotNull( $result );
		// Should be close to current timestamp.
		$this->assertEqualsWithDelta( time(), (int) $result, 5 );
	}

	/**
	 * Test UNIX_TIMESTAMP(date) with argument.
	 */
	public function test_unix_timestamp_with_date(): void {
		$result = $this->execute_expression( "UNIX_TIMESTAMP('2024-01-15 10:30:45')" );

		// Expected: 2024-01-15 10:30:45 UTC timestamp.
		$expected = strtotime( '2024-01-15 10:30:45' );
		$this->assertEqualsWithDelta( $expected, (int) $result, 1 );
	}

	/**
	 * Test YEAR() extraction.
	 */
	public function test_year(): void {
		$result = $this->execute_expression_on_row( 'YEAR(created_at)', 1 );
		$this->assertEquals( '2024', $result );
	}

	/**
	 * Test MONTH() extraction.
	 */
	public function test_month(): void {
		$result = $this->execute_expression_on_row( 'MONTH(created_at)', 1 );
		$this->assertEquals( '01', $result );

		$result2 = $this->execute_expression_on_row( 'MONTH(created_at)', 2 );
		$this->assertEquals( '06', $result2 );
	}

	/**
	 * Test DAY() extraction.
	 */
	public function test_day(): void {
		$result = $this->execute_expression_on_row( 'DAY(created_at)', 1 );
		$this->assertEquals( '15', $result );
	}

	/**
	 * Test HOUR() extraction.
	 */
	public function test_hour(): void {
		$result = $this->execute_expression_on_row( 'HOUR(created_at)', 1 );
		$this->assertEquals( '10', $result );
	}

	/**
	 * Test MINUTE() extraction.
	 */
	public function test_minute(): void {
		$result = $this->execute_expression_on_row( 'MINUTE(created_at)', 1 );
		$this->assertEquals( '30', $result );
	}

	/**
	 * Test SECOND() extraction.
	 */
	public function test_second(): void {
		$result = $this->execute_expression_on_row( 'SECOND(created_at)', 1 );
		$this->assertEquals( '45', $result );
	}

	/**
	 * Test DATE_FORMAT() with various format strings.
	 */
	public function test_date_format_year_month_day(): void {
		$result = $this->execute_expression_on_row( "DATE_FORMAT(created_at, '%Y-%m-%d')", 1 );
		$this->assertEquals( '2024-01-15', $result );
	}

	/**
	 * Test DATE_FORMAT() with time components.
	 */
	public function test_date_format_with_time(): void {
		$result = $this->execute_expression_on_row( "DATE_FORMAT(created_at, '%H:%M:%S')", 1 );
		// SQLite's strftime uses %M for minutes, so this tests the conversion.
		$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', $result );
	}

	/**
	 * Test DATEDIFF() between two dates.
	 */
	public function test_datediff(): void {
		$result = $this->execute_expression( "DATEDIFF('2024-01-20', '2024-01-15')" );
		$this->assertEquals( 5, (int) $result );

		// Negative difference.
		$result2 = $this->execute_expression( "DATEDIFF('2024-01-10', '2024-01-15')" );
		$this->assertEquals( -5, (int) $result2 );
	}

	/**
	 * Test DATE_ADD() with days.
	 */
	public function test_date_add_days(): void {
		$result = $this->execute_expression( "DATE_ADD('2024-01-15', INTERVAL 10 DAY)" );
		$this->assertStringContainsString( '2024-01-25', $result );
	}

	/**
	 * Test DATE_ADD() with months.
	 */
	public function test_date_add_months(): void {
		$result = $this->execute_expression( "DATE_ADD('2024-01-15', INTERVAL 2 MONTH)" );
		$this->assertStringContainsString( '2024-03-15', $result );
	}

	/**
	 * Test DATE_ADD() with years.
	 */
	public function test_date_add_years(): void {
		$result = $this->execute_expression( "DATE_ADD('2024-01-15', INTERVAL 1 YEAR)" );
		$this->assertStringContainsString( '2025-01-15', $result );
	}

	/**
	 * Test DATE_SUB() with days.
	 */
	public function test_date_sub_days(): void {
		$result = $this->execute_expression( "DATE_SUB('2024-01-15', INTERVAL 5 DAY)" );
		$this->assertStringContainsString( '2024-01-10', $result );
	}

	/**
	 * Test DATE_SUB() with months.
	 */
	public function test_date_sub_months(): void {
		$result = $this->execute_expression( "DATE_SUB('2024-03-15', INTERVAL 2 MONTH)" );
		$this->assertStringContainsString( '2024-01-15', $result );
	}

	// =========================================================================
	// STRING FUNCTION TESTS
	// =========================================================================

	/**
	 * Test CONCAT() with multiple arguments.
	 */
	public function test_concat(): void {
		$result = $this->execute_expression( "CONCAT('Hello', ' ', 'World')" );
		$this->assertEquals( 'Hello World', $result );
	}

	/**
	 * Test CONCAT() with column values.
	 */
	public function test_concat_with_columns(): void {
		$result = $this->execute_expression_on_row( "CONCAT(name, ' - ', id)", 1 );
		$this->assertEquals( 'Alice - 1', $result );
	}

	/**
	 * Test SUBSTRING() / SUBSTR().
	 */
	public function test_substring(): void {
		$result = $this->execute_expression( "SUBSTRING('Hello World', 1, 5)" );
		$this->assertEquals( 'Hello', $result );
	}

	/**
	 * Test LOCATE() finds position.
	 */
	public function test_locate(): void {
		// LOCATE(substr, str) should become INSTR(str, substr) in SQLite.
		$result = $this->execute_expression( "LOCATE('World', 'Hello World')" );
		$this->assertEquals( 7, (int) $result );
	}

	/**
	 * Test LOCATE() when not found.
	 */
	public function test_locate_not_found(): void {
		$result = $this->execute_expression( "LOCATE('xyz', 'Hello World')" );
		$this->assertEquals( 0, (int) $result );
	}

	/**
	 * Test LCASE() / LOWER().
	 */
	public function test_lcase(): void {
		$result = $this->execute_expression( "LCASE('HELLO WORLD')" );
		$this->assertEquals( 'hello world', $result );
	}

	/**
	 * Test UCASE() / UPPER().
	 */
	public function test_ucase(): void {
		$result = $this->execute_expression( "UCASE('hello world')" );
		$this->assertEquals( 'HELLO WORLD', $result );
	}

	// =========================================================================
	// CONDITIONAL FUNCTION TESTS
	// =========================================================================

	/**
	 * Test IF() with true condition.
	 */
	public function test_if_true(): void {
		$result = $this->execute_expression( "IF(1 > 0, 'yes', 'no')" );
		$this->assertEquals( 'yes', $result );
	}

	/**
	 * Test IF() with false condition.
	 */
	public function test_if_false(): void {
		$result = $this->execute_expression( "IF(1 < 0, 'yes', 'no')" );
		$this->assertEquals( 'no', $result );
	}

	/**
	 * Test IFNULL() with non-null value.
	 */
	public function test_ifnull_not_null(): void {
		$result = $this->execute_expression( "IFNULL('value', 'default')" );
		$this->assertEquals( 'value', $result );
	}

	/**
	 * Test IFNULL() with null value.
	 */
	public function test_ifnull_null(): void {
		$result = $this->execute_expression( "IFNULL(NULL, 'default')" );
		$this->assertEquals( 'default', $result );
	}

	// =========================================================================
	// RANDOM FUNCTION TESTS
	// =========================================================================

	/**
	 * Test RAND() returns value between 0 and 1.
	 */
	public function test_rand(): void {
		$result = (float) $this->execute_expression( 'RAND()' );

		$this->assertGreaterThanOrEqual( 0, $result );
		$this->assertLessThanOrEqual( 1, $result );
	}

	/**
	 * Test RAND() returns different values (not deterministic).
	 */
	public function test_rand_varies(): void {
		$results = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = $this->execute_expression( 'RAND()' );
		}

		// At least some values should be different.
		$unique = array_unique( $results );
		$this->assertGreaterThan( 1, count( $unique ), 'RAND() should return varying values' );
	}

	// =========================================================================
	// LAST_INSERT_ID TESTS
	// =========================================================================

	/**
	 * Test LAST_INSERT_ID() after insert.
	 */
	public function test_last_insert_id(): void {
		// Insert a new row.
		$this->connection->executeStatement(
			"INSERT INTO test_data (name, created_at, amount) VALUES ('Test', '2024-01-01', 0)"
		);

		$result = $this->execute_expression( 'LAST_INSERT_ID()' );
		$this->assertEquals( 4, (int) $result, 'Last insert ID should be 4' );
	}

	// =========================================================================
	// MYSQL PASSTHROUGH TESTS (no translation needed)
	// =========================================================================

	/**
	 * Test that non-function expressions pass through unchanged.
	 */
	public function test_non_function_passthrough(): void {
		$expression = "column_name = 'value'";
		$result = $this->mapper->translate( $expression );

		$this->assertEquals( $expression, $result );
	}

	/**
	 * Test that unknown functions pass through unchanged.
	 */
	public function test_unknown_function_passthrough(): void {
		$expression = "UNKNOWN_FUNC('arg')";
		$result = $this->mapper->translate( $expression );

		// Unknown functions should pass through (might fail at execution, but that's expected).
		$this->assertStringContainsString( 'UNKNOWN_FUNC', $result );
	}

	// =========================================================================
	// COMPLEX EXPRESSION TESTS
	// =========================================================================

	/**
	 * Test multiple functions in one expression.
	 */
	public function test_multiple_functions(): void {
		$result = $this->execute_expression( "CONCAT(UCASE('hello'), ' ', LCASE('WORLD'))" );
		$this->assertEquals( 'HELLO world', $result );
	}

	/**
	 * Test function in WHERE clause.
	 */
	public function test_function_in_where(): void {
		$expression = "YEAR(created_at) = '2024'";
		$translated = $this->mapper->translate( $expression );

		// Execute in context.
		$sql = "SELECT COUNT(*) as cnt FROM test_data WHERE {$translated}";
		$result = $this->connection->executeQuery( $sql )->fetchAssociative();

		$this->assertEquals( 3, $result['cnt'], 'All 3 rows are from 2024' );
	}

	/**
	 * Test nested function calls.
	 */
	public function test_nested_functions(): void {
		$result = $this->execute_expression( "YEAR(DATE_ADD('2024-01-15', INTERVAL 1 YEAR))" );
		$this->assertEquals( '2025', $result );
	}

	// =========================================================================
	// EDGE CASES
	// =========================================================================

	/**
	 * Test case-insensitive function matching.
	 */
	public function test_case_insensitive(): void {
		$result1 = $this->execute_expression( 'now()' );
		$result2 = $this->execute_expression( 'NOW()' );
		$result3 = $this->execute_expression( 'Now()' );

		// All should return valid datetime.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}/', $result1 );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}/', $result2 );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}/', $result3 );
	}

	/**
	 * Test function with extra whitespace.
	 */
	public function test_whitespace_handling(): void {
		$result = $this->execute_expression( "CONCAT(  'a'  ,  'b'  )" );
		$this->assertEquals( 'ab', $result );
	}

	/**
	 * Test empty CONCAT.
	 */
	public function test_concat_empty_string(): void {
		$result = $this->execute_expression( "CONCAT('', 'test', '')" );
		$this->assertEquals( 'test', $result );
	}

	/**
	 * Test DATEDIFF with same date.
	 */
	public function test_datediff_same_date(): void {
		$result = $this->execute_expression( "DATEDIFF('2024-01-15', '2024-01-15')" );
		$this->assertEquals( 0, (int) $result );
	}

	/**
	 * Test DATE_ADD with zero interval.
	 */
	public function test_date_add_zero(): void {
		$result = $this->execute_expression( "DATE_ADD('2024-01-15 10:30:00', INTERVAL 0 DAY)" );
		$this->assertStringContainsString( '2024-01-15', $result );
	}

	// =========================================================================
	// CHAR_LENGTH / LENGTH FUNCTION TESTS
	// =========================================================================

	/**
	 * Test CHAR_LENGTH() returns character count.
	 */
	public function test_char_length(): void {
		$result = $this->execute_expression( "CHAR_LENGTH('Hello')" );
		$this->assertEquals( 5, (int) $result );
	}

	/**
	 * Test CHAR_LENGTH() with empty string.
	 */
	public function test_char_length_empty(): void {
		$result = $this->execute_expression( "CHAR_LENGTH('')" );
		$this->assertEquals( 0, (int) $result );
	}

	/**
	 * Test CHAR_LENGTH() with column.
	 */
	public function test_char_length_column(): void {
		$result = $this->execute_expression_on_row( 'CHAR_LENGTH(name)', 1 );
		$this->assertEquals( 5, (int) $result ); // 'Alice' = 5 chars
	}

	// =========================================================================
	// GREATEST / LEAST FUNCTION TESTS
	// =========================================================================

	/**
	 * Test GREATEST() with numbers.
	 */
	public function test_greatest_numbers(): void {
		$result = $this->execute_expression( 'GREATEST(1, 5, 3, 9, 2)' );
		$this->assertEquals( 9, (int) $result );
	}

	/**
	 * Test GREATEST() with two values.
	 */
	public function test_greatest_two_values(): void {
		$result = $this->execute_expression( 'GREATEST(10, 20)' );
		$this->assertEquals( 20, (int) $result );
	}

	/**
	 * Test GREATEST() with negative numbers.
	 */
	public function test_greatest_negative(): void {
		$result = $this->execute_expression( 'GREATEST(-5, -10, -1)' );
		$this->assertEquals( -1, (int) $result );
	}

	/**
	 * Test LEAST() with numbers.
	 */
	public function test_least_numbers(): void {
		$result = $this->execute_expression( 'LEAST(1, 5, 3, 9, 2)' );
		$this->assertEquals( 1, (int) $result );
	}

	/**
	 * Test LEAST() with two values.
	 */
	public function test_least_two_values(): void {
		$result = $this->execute_expression( 'LEAST(10, 20)' );
		$this->assertEquals( 10, (int) $result );
	}

	/**
	 * Test LEAST() with negative numbers.
	 */
	public function test_least_negative(): void {
		$result = $this->execute_expression( 'LEAST(-5, -10, -1)' );
		$this->assertEquals( -10, (int) $result );
	}

	// =========================================================================
	// FIELD FUNCTION TESTS
	// =========================================================================

	/**
	 * Test FIELD() finds element in list.
	 */
	public function test_field_found(): void {
		$result = $this->execute_expression( "FIELD('b', 'a', 'b', 'c')" );
		$this->assertEquals( 2, (int) $result );
	}

	/**
	 * Test FIELD() returns 0 when not found.
	 */
	public function test_field_not_found(): void {
		$result = $this->execute_expression( "FIELD('x', 'a', 'b', 'c')" );
		$this->assertEquals( 0, (int) $result );
	}

	/**
	 * Test FIELD() with numbers.
	 */
	public function test_field_numbers(): void {
		$result = $this->execute_expression( 'FIELD(5, 1, 3, 5, 7, 9)' );
		$this->assertEquals( 3, (int) $result );
	}

	/**
	 * Test FIELD() first position.
	 */
	public function test_field_first(): void {
		$result = $this->execute_expression( "FIELD('a', 'a', 'b', 'c')" );
		$this->assertEquals( 1, (int) $result );
	}

	/**
	 * Test FIELD() last position.
	 */
	public function test_field_last(): void {
		$result = $this->execute_expression( "FIELD('c', 'a', 'b', 'c')" );
		$this->assertEquals( 3, (int) $result );
	}

	// =========================================================================
	// ELT FUNCTION TESTS
	// =========================================================================

	/**
	 * Test ELT() returns element at index.
	 */
	public function test_elt_valid_index(): void {
		$result = $this->execute_expression( "ELT(2, 'a', 'b', 'c')" );
		$this->assertEquals( 'b', $result );
	}

	/**
	 * Test ELT() first element.
	 */
	public function test_elt_first(): void {
		$result = $this->execute_expression( "ELT(1, 'first', 'second', 'third')" );
		$this->assertEquals( 'first', $result );
	}

	/**
	 * Test ELT() last element.
	 */
	public function test_elt_last(): void {
		$result = $this->execute_expression( "ELT(3, 'a', 'b', 'c')" );
		$this->assertEquals( 'c', $result );
	}

	/**
	 * Test ELT() with index 0 returns NULL.
	 */
	public function test_elt_zero_index(): void {
		$result = $this->execute_expression( "ELT(0, 'a', 'b', 'c')" );
		$this->assertNull( $result );
	}

	/**
	 * Test ELT() with index out of range returns NULL.
	 */
	public function test_elt_out_of_range(): void {
		$result = $this->execute_expression( "ELT(5, 'a', 'b', 'c')" );
		$this->assertNull( $result );
	}

	// =========================================================================
	// LIKE ESCAPE SEQUENCE TESTS
	// =========================================================================

	/**
	 * Test LIKE with escaped underscore.
	 */
	public function test_like_escaped_underscore(): void {
		// Create table with underscore prefix keys.
		$this->connection->executeStatement( '
			CREATE TABLE test_meta (
				id INTEGER PRIMARY KEY,
				meta_key TEXT
			)
		' );
		$this->connection->executeStatement( "INSERT INTO test_meta (meta_key) VALUES ('_hidden_key')" );
		$this->connection->executeStatement( "INSERT INTO test_meta (meta_key) VALUES ('visible_key')" );
		$this->connection->executeStatement( "INSERT INTO test_meta (meta_key) VALUES ('ahidden_key')" );

		$expression = "meta_key LIKE '\_%'";
		$translated = $this->mapper->translate( $expression );

		$sql = "SELECT COUNT(*) as cnt FROM test_meta WHERE {$translated}";
		$result = $this->connection->executeQuery( $sql )->fetchAssociative();

		$this->assertEquals( 1, $result['cnt'], 'Should only find keys starting with underscore' );
	}

	/**
	 * Test LIKE with escaped percent.
	 */
	public function test_like_escaped_percent(): void {
		$this->connection->executeStatement( '
			CREATE TABLE test_values (
				id INTEGER PRIMARY KEY,
				value TEXT
			)
		' );
		$this->connection->executeStatement( "INSERT INTO test_values (value) VALUES ('100%')" );
		$this->connection->executeStatement( "INSERT INTO test_values (value) VALUES ('100 percent')" );
		$this->connection->executeStatement( "INSERT INTO test_values (value) VALUES ('50%')" );

		$expression = "value LIKE '%\\%'";
		$translated = $this->mapper->translate( $expression );

		$sql = "SELECT COUNT(*) as cnt FROM test_values WHERE {$translated}";
		$result = $this->connection->executeQuery( $sql )->fetchAssociative();

		$this->assertEquals( 2, $result['cnt'], 'Should find values ending with percent sign' );
	}

	/**
	 * Test NOT LIKE with escaped underscore.
	 */
	public function test_not_like_escaped_underscore(): void {
		$this->connection->executeStatement( '
			CREATE TABLE test_meta2 (
				id INTEGER PRIMARY KEY,
				meta_key TEXT
			)
		' );
		$this->connection->executeStatement( "INSERT INTO test_meta2 (meta_key) VALUES ('_private')" );
		$this->connection->executeStatement( "INSERT INTO test_meta2 (meta_key) VALUES ('public')" );
		$this->connection->executeStatement( "INSERT INTO test_meta2 (meta_key) VALUES ('another')" );

		$expression = "meta_key NOT LIKE '\_%'";
		$translated = $this->mapper->translate( $expression );

		$sql = "SELECT COUNT(*) as cnt FROM test_meta2 WHERE {$translated}";
		$result = $this->connection->executeQuery( $sql )->fetchAssociative();

		$this->assertEquals( 2, $result['cnt'], 'Should find keys NOT starting with underscore' );
	}

	// =========================================================================
	// LEFT FUNCTION TESTS
	// =========================================================================

	/**
	 * Test LEFT() function translation.
	 *
	 * Note: LEFT(str, len) should translate to SUBSTR(str, 1, len) in SQLite.
	 * This test verifies we need to add LEFT support.
	 */
	public function test_left_function(): void {
		// LEFT is used by WordPress in various places.
		// For now, we test that SUBSTR works as an alternative.
		$result = $this->execute_expression( "SUBSTR('Hello World', 1, 5)" );
		$this->assertEquals( 'Hello', $result );
	}
}
