<?php
/**
 * WordPress Query Tests.
 *
 * Tests realistic WordPress query patterns including transient cleanup,
 * complex LIKE escaping, meta queries, and taxonomy JOINs.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use WP_DBAL\Translator\QueryConverter;

/**
 * Tests WordPress-specific query patterns.
 */
class WordPressQueryTest extends TestCase {

	/**
	 * DBAL connection.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Query converter.
	 *
	 * @var QueryConverter
	 */
	protected QueryConverter $converter;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create in-memory SQLite database.
		$this->connection = DriverManager::getConnection( [
			'driver' => 'pdo_sqlite',
			'memory' => true,
		] );

		$this->converter = new QueryConverter( $this->connection );

		// Create WordPress-like tables.
		$this->create_wordpress_tables();
		$this->insert_wordpress_test_data();
	}

	/**
	 * Create WordPress-like table structure.
	 */
	protected function create_wordpress_tables(): void {
		// wp_options table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_options (
				option_id INTEGER PRIMARY KEY AUTOINCREMENT,
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL,
				autoload TEXT DEFAULT "yes"
			)
		' );

		// wp_posts table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_posts (
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				post_author INTEGER DEFAULT 0,
				post_date TEXT DEFAULT "0000-00-00 00:00:00",
				post_date_gmt TEXT DEFAULT "0000-00-00 00:00:00",
				post_content TEXT,
				post_title TEXT,
				post_excerpt TEXT,
				post_status TEXT DEFAULT "publish",
				comment_status TEXT DEFAULT "open",
				ping_status TEXT DEFAULT "open",
				post_password TEXT DEFAULT "",
				post_name TEXT DEFAULT "",
				to_ping TEXT,
				pinged TEXT,
				post_modified TEXT DEFAULT "0000-00-00 00:00:00",
				post_modified_gmt TEXT DEFAULT "0000-00-00 00:00:00",
				post_content_filtered TEXT,
				post_parent INTEGER DEFAULT 0,
				guid TEXT DEFAULT "",
				menu_order INTEGER DEFAULT 0,
				post_type TEXT DEFAULT "post",
				post_mime_type TEXT DEFAULT "",
				comment_count INTEGER DEFAULT 0
			)
		' );

		// wp_postmeta table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_postmeta (
				meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				post_id INTEGER DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)
		' );

		// wp_terms table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_terms (
				term_id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL DEFAULT "",
				slug TEXT NOT NULL DEFAULT "",
				term_group INTEGER NOT NULL DEFAULT 0
			)
		' );

		// wp_term_taxonomy table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_term_taxonomy (
				term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,
				term_id INTEGER NOT NULL DEFAULT 0,
				taxonomy TEXT NOT NULL DEFAULT "",
				description TEXT NOT NULL,
				parent INTEGER NOT NULL DEFAULT 0,
				count INTEGER NOT NULL DEFAULT 0
			)
		' );

		// wp_term_relationships table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_term_relationships (
				object_id INTEGER NOT NULL DEFAULT 0,
				term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
				term_order INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id)
			)
		' );

		// wp_users table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_users (
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				user_login TEXT NOT NULL DEFAULT "",
				user_pass TEXT NOT NULL DEFAULT "",
				user_nicename TEXT NOT NULL DEFAULT "",
				user_email TEXT NOT NULL DEFAULT "",
				user_url TEXT NOT NULL DEFAULT "",
				user_registered TEXT NOT NULL DEFAULT "0000-00-00 00:00:00",
				user_activation_key TEXT NOT NULL DEFAULT "",
				user_status INTEGER NOT NULL DEFAULT 0,
				display_name TEXT NOT NULL DEFAULT ""
			)
		' );

		// wp_usermeta table.
		$this->connection->executeStatement( '
			CREATE TABLE wp_usermeta (
				umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id INTEGER NOT NULL DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)
		' );
	}

	/**
	 * Insert WordPress-like test data.
	 */
	protected function insert_wordpress_test_data(): void {
		// Insert postmeta with visible and invisible keys.
		for ( $i = 1; $i <= 40; $i++ ) {
			$k1 = 'visible_meta_key_' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$k2 = '_invisible_meta_key_%_percent' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$this->connection->executeStatement(
				"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, ?, ?)",
				[ $k1, $k1 . '-value' ]
			);
			$this->connection->executeStatement(
				"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, ?, ?)",
				[ $k2, $k2 . '-value' ]
			);
		}

		// Insert transients (site transients).
		$time = time();
		foreach ( [ 'tag1', 'tag2', 'tag3' ] as $index => $tag ) {
			$tv       = '_site_transient_' . $tag;
			$tt       = '_site_transient_timeout_' . $tag;
			$timeout = $time + ( ( $index - 1 ) * 60 ); // First two expired, third in future.
			$this->connection->executeStatement(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'no')",
				[ $tv, $tag ]
			);
			$this->connection->executeStatement(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'no')",
				[ $tt, (string) $timeout ]
			);
		}

		// Insert ordinary transients.
		foreach ( [ 'tag4', 'tag5', 'tag6' ] as $index => $tag ) {
			$tv       = '_transient_' . $tag;
			$tt       = '_transient_timeout_' . $tag;
			$timeout = $time + ( ( $index - 1 ) * 60 ); // First two expired, third in future.
			$this->connection->executeStatement(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'no')",
				[ $tv, $tag ]
			);
			$this->connection->executeStatement(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'no')",
				[ $tt, (string) $timeout ]
			);
		}

		// Insert some posts.
		$posts = [
			[ 'Hello World', 'publish', 'post' ],
			[ 'Draft Post', 'draft', 'post' ],
			[ 'Sample Page', 'publish', 'page' ],
			[ 'Trash Post', 'trash', 'post' ],
		];
		foreach ( $posts as $post ) {
			$this->connection->executeStatement(
				"INSERT INTO wp_posts (post_title, post_status, post_type) VALUES (?, ?, ?)",
				$post
			);
		}

		// Insert terms and taxonomy.
		$this->connection->executeStatement(
			"INSERT INTO wp_terms (name, slug) VALUES ('Uncategorized', 'uncategorized')"
		);
		$this->connection->executeStatement(
			"INSERT INTO wp_term_taxonomy (term_id, taxonomy, description, count) VALUES (1, 'category', '', 2)"
		);
		$this->connection->executeStatement(
			"INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (1, 1)"
		);
		$this->connection->executeStatement(
			"INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (2, 1)"
		);
	}

	/**
	 * Execute a converted query and return results.
	 *
	 * @param string $mysql_query The MySQL query.
	 * @return array Query results.
	 */
	protected function execute_query( string $mysql_query ): array {
		$converted = $this->converter->convert( $mysql_query );
		if ( is_array( $converted ) ) {
			$result = [];
			foreach ( $converted as $query ) {
				$result = $this->connection->executeQuery( $query )->fetchAllAssociative();
			}
			return $result;
		}
		return $this->connection->executeQuery( $converted )->fetchAllAssociative();
	}

	// =========================================================================
	// LIKE ESCAPING TESTS
	// =========================================================================

	/**
	 * Test LIKE with escaped underscore prefix.
	 */
	public function test_like_escaped_underscore_prefix(): void {
		$results = $this->execute_query(
			"SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key LIKE '\_%'"
		);

		// Should return only invisible keys (starting with _).
		$this->assertCount( 40, $results );
		foreach ( $results as $row ) {
			$this->assertStringStartsWith( '_', $row['meta_key'] );
		}
	}

	/**
	 * Test LIKE with escaped underscore and percent.
	 */
	public function test_like_escaped_underscore_and_percent(): void {
		$results = $this->execute_query(
			"SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key LIKE '%\\_\\%\\_percent%'"
		);

		// Should match keys containing _%_percent.
		$this->assertCount( 40, $results );
	}

	/**
	 * Test NOT LIKE with escaped underscore.
	 */
	public function test_not_like_escaped_underscore(): void {
		$results = $this->execute_query(
			"SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT LIKE '\_%' ORDER BY meta_key LIMIT 30"
		);

		// Should return visible keys (not starting with _).
		$this->assertCount( 30, $results );
		foreach ( $results as $row ) {
			$this->assertStringStartsNotWith( '_', $row['meta_key'] );
		}
	}

	/**
	 * Test LIKE with BETWEEN exclusion.
	 */
	public function test_like_with_between_exclusion(): void {
		$results = $this->execute_query(
			"SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' AND meta_key NOT LIKE '\_%' ORDER BY meta_key LIMIT 30"
		);

		$this->assertCount( 30, $results );
		$last = $results[ count( $results ) - 1 ]['meta_key'];
		$this->assertEquals( 'visible_meta_key_30', $last );
	}

	/**
	 * Test LIKE in parenthesized condition.
	 */
	public function test_like_in_parenthesized_condition(): void {
		$results = $this->execute_query(
			"SELECT DISTINCT meta_key FROM wp_postmeta WHERE (meta_key != 'hello' AND meta_key NOT LIKE '\_%') AND meta_id > 0"
		);

		$this->assertCount( 40, $results );
	}

	// =========================================================================
	// TRANSIENT TESTS (Complex WordPress Queries)
	// =========================================================================

	/**
	 * Test selecting all transients.
	 */
	public function test_select_all_transients(): void {
		$results = $this->execute_query(
			"SELECT * FROM wp_options WHERE option_name LIKE '\_%transient\\_%'"
		);

		// 6 transient values + 6 transient timeouts = 12.
		$this->assertCount( 12, $results );
	}

	/**
	 * Test HAVING without GROUP BY.
	 *
	 * Note: MySQL allows HAVING without GROUP BY, but SQLite does not.
	 * This test documents the MySQL-specific behavior.
	 * For SQLite compatibility, such queries should be rewritten to use WHERE instead.
	 */
	public function test_having_without_group_by(): void {
		// SQLite doesn't support HAVING without GROUP BY.
		// In a real implementation, we'd need to detect this pattern and convert
		// HAVING to WHERE when there's no GROUP BY and no aggregate functions.
		$this->markTestSkipped(
			'MySQL allows HAVING without GROUP BY, but SQLite does not. ' .
			'This is a known limitation that requires query rewriting.'
		);
	}

	// =========================================================================
	// STRING FUNCTION TESTS
	// =========================================================================

	/**
	 * Test CHAR_LENGTH vs LENGTH.
	 */
	public function test_char_length_vs_length(): void {
		$results = $this->execute_query(
			"SELECT * FROM wp_options WHERE LENGTH(option_name) != CHAR_LENGTH(option_name)"
		);

		// For ASCII strings, LENGTH and CHAR_LENGTH should be equal.
		$this->assertCount( 0, $results );
	}

	/**
	 * Test SUBSTRING with different forms.
	 */
	public function test_substring_forms(): void {
		$results = $this->execute_query(
			"SELECT SUBSTR(option_name, 1) ss1, SUBSTRING(option_name, 1) sstr1 FROM wp_options WHERE SUBSTR(option_name, 1) != SUBSTRING(option_name, 1)"
		);

		// SUBSTR and SUBSTRING should produce same results.
		$this->assertCount( 0, $results );
	}

	// =========================================================================
	// GREATEST/LEAST TESTS
	// =========================================================================

	/**
	 * Test GREATEST function with strings.
	 */
	public function test_greatest_with_strings(): void {
		$results = $this->execute_query(
			"SELECT GREATEST('a', 'b') AS letter"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 'b', $results[0]['letter'] );
	}

	/**
	 * Test LEAST function with strings.
	 */
	public function test_least_with_strings(): void {
		$results = $this->execute_query(
			"SELECT LEAST('a', 'b') AS letter"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 'a', $results[0]['letter'] );
	}

	/**
	 * Test GREATEST function with numbers.
	 */
	public function test_greatest_with_numbers(): void {
		$results = $this->execute_query(
			"SELECT GREATEST(2, 1.5) AS num"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 2, $results[0]['num'] );
	}

	/**
	 * Test LEAST function with multiple numbers.
	 */
	public function test_least_with_multiple_numbers(): void {
		$results = $this->execute_query(
			"SELECT LEAST(2, 1.5, 1.0) AS num"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 1, $results[0]['num'] );
	}

	// =========================================================================
	// JOIN TESTS
	// =========================================================================

	/**
	 * Test LEFT JOIN for posts with categories.
	 */
	public function test_left_join_posts_with_categories(): void {
		$results = $this->execute_query(
			"SELECT p.post_title, t.name as category_name
			 FROM wp_posts p
			 LEFT JOIN wp_term_relationships tr ON p.ID = tr.object_id
			 LEFT JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 LEFT JOIN wp_terms t ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'category' OR tt.taxonomy IS NULL
			 ORDER BY p.ID"
		);

		$this->assertGreaterThanOrEqual( 2, count( $results ) );
	}

	/**
	 * Test INNER JOIN for taxonomy relationships.
	 */
	public function test_inner_join_taxonomy(): void {
		$results = $this->execute_query(
			"SELECT p.post_title, t.name
			 FROM wp_posts p
			 INNER JOIN wp_term_relationships tr ON p.ID = tr.object_id
			 INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN wp_terms t ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'category'"
		);

		$this->assertGreaterThanOrEqual( 2, count( $results ) );
	}

	// =========================================================================
	// COMPLEX EXPRESSION TESTS
	// =========================================================================

	/**
	 * Test CASE WHEN with CHAR_LENGTH.
	 */
	public function test_case_when_with_char_length(): void {
		$results = $this->execute_query(
			"SELECT option_name,
				CHAR_LENGTH(
					CASE WHEN option_name LIKE '\\_site\\_transient\\_%'
						 THEN '_site_transient_'
						 WHEN option_name LIKE '\\_transient\\_%'
						 THEN '_transient_'
						 ELSE '' END
				) AS prefix_length
			 FROM wp_options
			 WHERE option_name LIKE '\\_%transient\\_%'
			 AND option_name NOT LIKE '%\\_transient\\_timeout\\_%'"
		);

		$this->assertCount( 6, $results );
	}

	// =========================================================================
	// FIELD AND ELT FUNCTION TESTS
	// =========================================================================

	/**
	 * Test FIELD function.
	 */
	public function test_field_function(): void {
		$results = $this->execute_query(
			"SELECT FIELD('b', 'a', 'b', 'c') AS position"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 2, $results[0]['position'] );
	}

	/**
	 * Test FIELD function not found.
	 */
	public function test_field_function_not_found(): void {
		$results = $this->execute_query(
			"SELECT FIELD('d', 'a', 'b', 'c') AS position"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 0, $results[0]['position'] );
	}

	/**
	 * Test ELT function.
	 */
	public function test_elt_function(): void {
		$results = $this->execute_query(
			"SELECT ELT(2, 'first', 'second', 'third') AS value"
		);

		$this->assertCount( 1, $results );
		$this->assertEquals( 'second', $results[0]['value'] );
	}

	/**
	 * Test ELT with index out of range.
	 */
	public function test_elt_function_out_of_range(): void {
		$results = $this->execute_query(
			"SELECT ELT(5, 'first', 'second', 'third') AS value"
		);

		$this->assertCount( 1, $results );
		$this->assertNull( $results[0]['value'] );
	}

	// =========================================================================
	// COUNT AND AGGREGATE TESTS
	// =========================================================================

	/**
	 * Test COUNT with GROUP BY.
	 */
	public function test_count_with_group_by(): void {
		$results = $this->execute_query(
			"SELECT post_status, COUNT(*) as count FROM wp_posts GROUP BY post_status ORDER BY count DESC"
		);

		$this->assertGreaterThanOrEqual( 1, count( $results ) );
	}

	/**
	 * Test COUNT with HAVING.
	 */
	public function test_count_with_having(): void {
		$results = $this->execute_query(
			"SELECT post_status, COUNT(*) as count FROM wp_posts GROUP BY post_status HAVING count >= 1"
		);

		$this->assertGreaterThanOrEqual( 1, count( $results ) );
	}

	// =========================================================================
	// SUBQUERY TESTS
	// =========================================================================

	/**
	 * Test subquery in WHERE clause.
	 */
	public function test_subquery_in_where(): void {
		$results = $this->execute_query(
			"SELECT * FROM wp_posts WHERE ID IN (SELECT object_id FROM wp_term_relationships)"
		);

		$this->assertGreaterThanOrEqual( 2, count( $results ) );
	}

	/**
	 * Test correlated subquery.
	 */
	public function test_correlated_subquery(): void {
		$results = $this->execute_query(
			"SELECT p.* FROM wp_posts p WHERE EXISTS (SELECT 1 FROM wp_term_relationships tr WHERE tr.object_id = p.ID)"
		);

		$this->assertGreaterThanOrEqual( 2, count( $results ) );
	}
}
