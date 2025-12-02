<?php
/**
 * PHPUnit bootstrap file for WP-DBAL tests.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

// Load Composer autoloader first.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Determine test type from environment or default to unit.
$test_type = getenv( 'WP_DBAL_TEST_TYPE' ) ?: 'unit';

if ( 'integration' === $test_type ) {
	// Integration tests need WordPress.
	$_tests_dir = getenv( 'WP_TESTS_DIR' );

	if ( ! $_tests_dir ) {
		// Try the wp-phpunit package.
		$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	}

	// Give access to tests_add_filter() function.
	require_once $_tests_dir . '/includes/functions.php';

	/**
	 * Manually load the plugin being tested.
	 */
	tests_add_filter(
		'muplugins_loaded',
		function () {
			// The plugin is loaded via db.php drop-in, but we can test components directly.
		}
	);

	// Start up the WP testing environment.
	require $_tests_dir . '/includes/bootstrap.php';
} else {
	// Unit tests - minimal WordPress constants.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/wordpress/' );
	}

	if ( ! defined( 'WPINC' ) ) {
		define( 'WPINC', 'wp-includes' );
	}

	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	}

	if ( ! defined( 'WP_DBAL_PLUGIN_DIR' ) ) {
		define( 'WP_DBAL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}

	// Database constants for unit tests (not actually used).
	if ( ! defined( 'DB_NAME' ) ) {
		define( 'DB_NAME', 'test_db' );
	}
	if ( ! defined( 'DB_USER' ) ) {
		define( 'DB_USER', 'root' );
	}
	if ( ! defined( 'DB_PASSWORD' ) ) {
		define( 'DB_PASSWORD', '' );
	}
	if ( ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', 'localhost' );
	}
	if ( ! defined( 'DB_CHARSET' ) ) {
		define( 'DB_CHARSET', 'utf8mb4' );
	}

	$GLOBALS['table_prefix'] = 'wp_';
}
