<?php

/**
 * Plugin Name: WP-DBAL
 * Plugin URI: https://github.com/aristath/wp-dbal
 * Description: Database abstraction layer for WordPress using Doctrine DBAL. Enables WordPress to work with MySQL, PostgreSQL, SQLite, and custom storage backends.
 * Version: 0.1.0
 * Author: Ari Stathopoulos
 * Author URI: https://developer.wordpress.org/aristath
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dbal
 * Requires at least: 6.4
 * Requires PHP: 8.1
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL;

// Prevent direct access.
if (! \defined('ABSPATH')) {
	exit;
}

// Plugin constants.
if (! \defined('WP_DBAL_VERSION')) {
	\define('WP_DBAL_VERSION', '0.1.0');
}
if (! \defined('WP_DBAL_PLUGIN_DIR')) {
	\define('WP_DBAL_PLUGIN_DIR', \plugin_dir_path(__FILE__));
}
if (! \defined('WP_DBAL_PLUGIN_URL')) {
	\define('WP_DBAL_PLUGIN_URL', \plugin_dir_url(__FILE__));
}
if (! \defined('WP_DBAL_PLUGIN_FILE')) {
	\define('WP_DBAL_PLUGIN_FILE', __FILE__);
}

// Load Composer autoloader.
if (\file_exists(WP_DBAL_PLUGIN_DIR . 'vendor/autoload.php')) {
	require_once WP_DBAL_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class.
 */
final class Plugin
{
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function getInstance(): Plugin
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 */
	private function __construct()
	{
		$this->initHooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function initHooks(): void
	{
		\register_activation_hook(WP_DBAL_PLUGIN_FILE, [ $this, 'activate' ]);
		\register_deactivation_hook(WP_DBAL_PLUGIN_FILE, [ $this, 'deactivate' ]);

		\add_action('admin_menu', [ $this, 'addAdminMenu' ]);
		\add_action('admin_notices', [ $this, 'adminNotices' ]);
		\add_action('rest_api_init', [ $this, 'registerRestAPI' ]);
		\add_action('admin_enqueue_scripts', [ $this, 'enqueueScripts' ]);
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void
	{
		// Check if db.php drop-in already exists.
		$dbDropin = WP_CONTENT_DIR . '/db.php';

		if (\file_exists($dbDropin)) {
			// Check if it's our drop-in.
			$content = \file_get_contents($dbDropin);
			if (false === \strpos($content, 'WP_DBAL')) {
				// Another plugin's db.php - don't overwrite.
				\set_transient('wp_dbal_activation_error', 'db_exists', 60);
				return;
			}
		}

		// Copy our db.php drop-in.
		$this->installDropin();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void
	{
		$this->removeDropin();
	}

	/**
	 * Install the db.php drop-in.
	 *
	 * @return bool
	 */
	public function installDropin(): bool
	{
		$source = WP_DBAL_PLUGIN_DIR . 'db.copy';
		$dest   = WP_CONTENT_DIR . '/db.php';

		if (! \file_exists($source)) {
			return false;
		}

		// Read template and replace placeholders.
		$content = \file_get_contents($source);
		$content = \str_replace('{PLUGIN_DIR}', WP_DBAL_PLUGIN_DIR, $content);

		return (bool) \file_put_contents($dest, $content);
	}

	/**
	 * Remove the db.php drop-in.
	 *
	 * @return bool
	 */
	public function removeDropin(): bool
	{
		$dbDropin = WP_CONTENT_DIR . '/db.php';

		if (! \file_exists($dbDropin)) {
			return true;
		}

		// Only remove if it's our drop-in.
		$content = \file_get_contents($dbDropin);
		if (false === \strpos($content, 'WP_DBAL')) {
			return false;
		}

		return \unlink($dbDropin);
	}

	/**
	 * Check if drop-in is installed.
	 *
	 * @return bool
	 */
	public function isDropinInstalled(): bool
	{
		$dbDropin = WP_CONTENT_DIR . '/db.php';

		if (! \file_exists($dbDropin)) {
			return false;
		}

		$content = \file_get_contents($dbDropin);
		return false !== \strpos($content, 'WP_DBAL');
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function addAdminMenu(): void
	{
		\add_management_page(
			\__('WP-DBAL Settings', 'wp-dbal'),
			\__('WP-DBAL', 'wp-dbal'),
			'manage_options',
			'wp-dbal',
			[ $this, 'renderAdminPage' ]
		);
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function adminNotices(): void
	{
		$error = \get_transient('wp_dbal_activation_error');
		if ('db_exists' === $error) {
			\delete_transient('wp_dbal_activation_error');
			echo '<div class="notice notice-error"><p>';
			\esc_html_e('WP-DBAL: Cannot install db.php drop-in because another plugin already installed one. Please deactivate the other plugin first.', 'wp-dbal');
			echo '</p></div>';
		}

		// Warning if drop-in is not installed.
		if (! $this->isDropinInstalled() && \current_user_can('manage_options')) {
			echo '<div class="notice notice-warning"><p>';
			\esc_html_e('WP-DBAL: The db.php drop-in is not installed. The plugin will not function without it.', 'wp-dbal');
			echo ' <a href="' . \esc_url(\admin_url('tools.php?page=wp-dbal')) . '">';
			\esc_html_e('Go to settings', 'wp-dbal');
			echo '</a></p></div>';
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function registerRestAPI(): void
	{
		// Migration REST API.
		$migrationRestAPI = new \WP_DBAL\Migration\RestAPI();
		$migrationRestAPI->registerRoutes();

		// Admin REST API.
		$adminController = new \WP_DBAL\REST\AdminController();
		$adminController->registerRoutes();
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts(string $hook): void
	{
		// Only enqueue on our admin page.
		if ('tools_page_wp-dbal' !== $hook) {
			return;
		}

		// Enqueue admin React app.
		$asset_file = WP_DBAL_PLUGIN_DIR . 'build/admin.asset.php';
		if (\file_exists($asset_file)) {
			$asset = require $asset_file;
			\wp_enqueue_script(
				'wp-dbal-admin',
				WP_DBAL_PLUGIN_URL . 'build/admin.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		} else {
			// Fallback if asset file doesn't exist yet.
			\wp_enqueue_script(
				'wp-dbal-admin',
				WP_DBAL_PLUGIN_URL . 'build/admin.js',
				[ 'wp-element', 'wp-i18n', 'wp-components', 'wp-api-fetch' ],
				WP_DBAL_VERSION,
				true
			);
		}

		// Enqueue admin styles.
		\wp_enqueue_style(
			'wp-dbal-admin',
			WP_DBAL_PLUGIN_URL . 'assets/css/migration.css',
			[],
			WP_DBAL_VERSION
		);

		// Set up API fetch nonce via localization.
		\wp_localize_script(
			'wp-dbal-admin',
			'wpDbalAdmin',
			[
				'restNonce' => \wp_create_nonce('wp_rest'),
			]
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function renderAdminPage(): void
	{
		// phpcs:disable Generic.Files.InlineHTML.Found -- Admin page template
		?>
		<div id="wp-dbal-admin-root"></div>
		<?php
		// phpcs:enable Generic.Files.InlineHTML.Found
	}

	/**
	 * Get current database engine.
	 *
	 * @return string
	 */
	public function getDbEngine(): string
	{
		return \defined('DB_ENGINE') ? \strtolower(DB_ENGINE) : 'mysql';
	}
}

// Initialize plugin.
Plugin::getInstance();
