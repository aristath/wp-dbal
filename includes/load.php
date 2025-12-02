<?php
/**
 * WP-DBAL Loader
 *
 * This file is loaded by the db.php drop-in before WordPress initializes.
 * It sets up the DBAL-powered database layer.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the plugin directory if not already defined.
if ( ! defined( 'WP_DBAL_PLUGIN_DIR' ) ) {
	define( 'WP_DBAL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Load Composer autoloader.
$autoloader = WP_DBAL_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	// Composer dependencies not installed - cannot proceed.
	// WordPress will use the default wpdb.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'WP-DBAL: Composer autoloader not found. Run "composer install" in the plugin directory.' );
	}
	return;
}

require_once $autoloader;

// Load the original wpdb class from WordPress core (we extend it).
require_once ABSPATH . WPINC . '/class-wpdb.php';

// Load the translator classes.
require_once WP_DBAL_PLUGIN_DIR . 'includes/Translator/FunctionMapper.php';
require_once WP_DBAL_PLUGIN_DIR . 'includes/Translator/QueryConverter.php';

// Load the custom wpdb class.
require_once WP_DBAL_PLUGIN_DIR . 'includes/class-wp-dbal-db.php';

// Determine which database engine to use.
$db_engine = defined( 'DB_ENGINE' ) ? strtolower( DB_ENGINE ) : 'mysql';

// Create the global $wpdb instance.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wpdb = new WP_DBAL\WP_DBAL_DB( $db_engine );

// Set up the database connection.
// This is normally called by wp-settings.php, but we need to do it here
// because the drop-in is loaded before wp-settings.php.
$wpdb->db_connect();
