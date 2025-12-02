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
\define('WP_DBAL_VERSION', '0.1.0');
\define('WP_DBAL_PLUGIN_DIR', \plugin_dir_path(__FILE__));
\define('WP_DBAL_PLUGIN_URL', \plugin_dir_url(__FILE__));
\define('WP_DBAL_PLUGIN_FILE', __FILE__);

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
	 * Render admin page.
	 *
	 * @return void
	 */
	public function renderAdminPage(): void
	{
		// Handle actions.
		if (isset($_POST['wp_dbal_action']) && \check_admin_referer('wp_dbal_admin')) {
			$action = \sanitize_text_field(\wp_unslash($_POST['wp_dbal_action']));
			if ('install_dropin' === $action) {
				$this->installDropin();
			} elseif ('remove_dropin' === $action) {
				$this->removeDropin();
			}
		}

		$dropinInstalled = $this->isDropinInstalled();
		$dbEngine        = \defined('DB_ENGINE') ? DB_ENGINE : 'mysql';

		// phpcs:disable Generic.Files.InlineHTML.Found -- Admin page template
		?>
		<div class="wrap">
			<h1><?php \esc_html_e('WP-DBAL Settings', 'wp-dbal'); ?></h1>

			<div class="card">
				<h2><?php \esc_html_e('Status', 'wp-dbal'); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php \esc_html_e('Drop-in Status', 'wp-dbal'); ?></th>
						<td>
							<?php if ($dropinInstalled) : ?>
								<span style="color: green;">&#10003; <?php \esc_html_e('Installed', 'wp-dbal'); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10007; <?php \esc_html_e('Not Installed', 'wp-dbal'); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php \esc_html_e('Database Engine', 'wp-dbal'); ?></th>
						<td><code><?php echo \esc_html($dbEngine); ?></code></td>
					</tr>
					<tr>
						<th><?php \esc_html_e('DBAL Version', 'wp-dbal'); ?></th>
						<td>
							<?php
							if (\class_exists('\Doctrine\DBAL\Connection')) {
								echo '<code>' . \esc_html(\Doctrine\DBAL\Connection::class) . '</code>';
							} else {
								\esc_html_e('Not loaded (run composer install)', 'wp-dbal');
							}
							?>
						</td>
					</tr>
				</table>

				<form method="post">
					<?php \wp_nonce_field('wp_dbal_admin'); ?>
					<?php if ($dropinInstalled) : ?>
						<input type="hidden" name="wp_dbal_action" value="remove_dropin">
						<button type="submit" class="button button-secondary">
							<?php \esc_html_e('Remove Drop-in', 'wp-dbal'); ?>
						</button>
					<?php else : ?>
						<input type="hidden" name="wp_dbal_action" value="install_dropin">
						<button type="submit" class="button button-primary">
							<?php \esc_html_e('Install Drop-in', 'wp-dbal'); ?>
						</button>
					<?php endif; ?>
				</form>
			</div>

			<div class="card" style="margin-top: 20px;">
				<h2><?php \esc_html_e('Configuration', 'wp-dbal'); ?></h2>
				<p><?php \esc_html_e('Add the following constants to wp-config.php to configure the database engine:', 'wp-dbal'); ?></p>
				<pre style="background: #f0f0f0; padding: 15px; overflow-x: auto;">
// Database engine: mysql, pgsql, sqlite
define( 'DB_ENGINE', 'mysql' );

// For non-MySQL engines, configure DBAL options:
// define( 'DB_DBAL_OPTIONS', [
//     'driver' => 'pdo_pgsql',
//     'host' => 'localhost',
//     'port' => 5432,
//     'dbname' => 'wordpress',
//     'user' => 'root',
//     'password' => '',
// ] );
				</pre>
			</div>
		</div>
		<?php
		// phpcs:enable Generic.Files.InlineHTML.Found
	}
}

// Initialize plugin.
Plugin::getInstance();
