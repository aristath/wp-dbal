<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

if ( ! defined( 'WP_DBAL_PLUGIN_DIR' ) ) {
	define( 'WP_DBAL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Define database constants for testing.
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

// Set up global table prefix.
$table_prefix = 'wp_';
