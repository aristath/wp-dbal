<?php

/**
 * Admin REST API Controller
 *
 * REST API endpoints for admin page functionality.
 *
 * @package WP_DBAL\REST
 */

declare(strict_types=1);

namespace WP_DBAL\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_DBAL\Plugin;

/**
 * Admin REST API controller.
 */
class AdminController
{
	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'wp-dbal/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void
	{
		// Get admin status.
		\register_rest_route(
			self::NAMESPACE,
			'/admin/status',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'getStatus' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		// Install dropin.
		\register_rest_route(
			self::NAMESPACE,
			'/admin/dropin/install',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'installDropin' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		// Remove dropin.
		\register_rest_route(
			self::NAMESPACE,
			'/admin/dropin/remove',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'removeDropin' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		// Get configuration.
		\register_rest_route(
			self::NAMESPACE,
			'/admin/configuration',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'getConfiguration' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		// Update configuration.
		\register_rest_route(
			self::NAMESPACE,
			'/admin/configuration',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'updateConfiguration' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'db_engine' => [
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'connection_params' => [
						'required' => false,
						'type' => 'object',
						'default' => [],
					],
				],
			]
		);
	}

	/**
	 * Permission check callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user has permission.
	 */
	public function permissionCheck(WP_REST_Request $request): bool
	{
		return \current_user_can('manage_options');
	}

	/**
	 * Get admin status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function getStatus(WP_REST_Request $request): WP_REST_Response
	{
		$plugin = Plugin::getInstance();
		$dropinInstalled = $plugin->isDropinInstalled();
		$dbEngine = $plugin->getDbEngine();
		$dbalLoaded = \class_exists('\Doctrine\DBAL\Connection');

		$dbalVersion = null;
		if ($dbalLoaded) {
			try {
				$reflection = new \ReflectionClass(\Doctrine\DBAL\Connection::class);
				$dbalVersion = $reflection->getNamespaceName();
			} catch (\Exception $e) {
				// Ignore.
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'data' => [
					'dropin_installed' => $dropinInstalled,
					'db_engine' => $dbEngine,
					'dbal_loaded' => $dbalLoaded,
					'dbal_version' => $dbalVersion,
				],
			],
			200
		);
	}

	/**
	 * Install dropin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function installDropin(WP_REST_Request $request)
	{
		$plugin = Plugin::getInstance();

		// Check if db.php already exists and is not ours.
		$dbDropin = WP_CONTENT_DIR . '/db.php';
		if (\file_exists($dbDropin)) {
			$content = \file_get_contents($dbDropin);
			if (false === \strpos($content, 'WP_DBAL')) {
				return new WP_Error(
					'dropin_exists',
					\__('Cannot install db.php drop-in because another plugin already installed one. Please deactivate the other plugin first.', 'wp-dbal'),
					[ 'status' => 400 ]
				);
			}
		}

		$result = $plugin->installDropin();

		if (! $result) {
			return new WP_Error(
				'install_failed',
				\__('Failed to install drop-in. Check file permissions.', 'wp-dbal'),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => \__('Drop-in installed successfully.', 'wp-dbal'),
			],
			200
		);
	}

	/**
	 * Remove dropin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function removeDropin(WP_REST_Request $request)
	{
		$plugin = Plugin::getInstance();

		$result = $plugin->removeDropin();

		if (! $result) {
			return new WP_Error(
				'remove_failed',
				\__('Failed to remove drop-in. The file may not belong to WP-DBAL or may have permission issues.', 'wp-dbal'),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => \__('Drop-in removed successfully.', 'wp-dbal'),
			],
			200
		);
	}

	/**
	 * Get current configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function getConfiguration(WP_REST_Request $request): WP_REST_Response
	{
		$plugin = Plugin::getInstance();
		
		// Allow fetching config for a specific engine (for migration UI pre-population).
		$requestedEngine = $request->get_param('db_engine');
		if (! empty($requestedEngine)) {
			$dbEngine = \strtolower($requestedEngine);
		} else {
			$dbEngine = $plugin->getDbEngine();
		}
		
		$connectionParams = $this->readConnectionParams($dbEngine);

		return new WP_REST_Response(
			[
				'success' => true,
				'data' => [
					'db_engine' => $dbEngine,
					'connection_params' => $connectionParams,
				],
			],
			200
		);
	}

	/**
	 * Update configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateConfiguration(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$dbEngine = $bodyParams['db_engine'] ?? $request->get_param('db_engine');
		$connectionParams = $bodyParams['connection_params'] ?? $request->get_param('connection_params') ?? [];

		if (empty($dbEngine)) {
			return new WP_Error(
				'missing_parameter',
				\__('db_engine parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$configWriter = new \WP_DBAL\Migration\ConfigWriter();
		$result = $configWriter->updateConfig($dbEngine, $connectionParams);

		if (! $result['success']) {
			return new WP_Error(
				'config_update_failed',
				$result['error'] ?? \__('Failed to update wp-config.php', 'wp-dbal'),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => \__('Configuration updated successfully', 'wp-dbal'),
			],
			200
		);
	}

	/**
	 * Read connection parameters from wp-config.php.
	 *
	 * @param string $dbEngine Database engine.
	 * @return array<string, mixed> Connection parameters.
	 */
	private function readConnectionParams(string $dbEngine): array
	{
		$params = [];

		switch ($dbEngine) {
			case 'sqlite':
				if (\defined('DB_SQLITE_PATH')) {
					$params['path'] = DB_SQLITE_PATH;
				}
				break;

			case 'filedb':
				if (\defined('DB_FILEDB_PATH')) {
					$params['path'] = DB_FILEDB_PATH;
				}
				if (\defined('DB_FILEDB_FORMAT')) {
					$params['format'] = DB_FILEDB_FORMAT;
				}
				break;

			case 'd1':
				if (\defined('DB_D1_ACCOUNT_ID')) {
					$params['account_id'] = DB_D1_ACCOUNT_ID;
				}
				if (\defined('DB_D1_DATABASE_ID')) {
					$params['database_id'] = DB_D1_DATABASE_ID;
				}
				if (\defined('DB_D1_API_TOKEN')) {
					$params['api_token'] = DB_D1_API_TOKEN;
				}
				break;

			case 'pgsql':
			case 'postgresql':
				// Read from DB_DBAL_OPTIONS if available.
				if (\defined('DB_DBAL_OPTIONS') && \is_array(DB_DBAL_OPTIONS)) {
					$dbalOptions = DB_DBAL_OPTIONS;
					$params['host'] = $dbalOptions['host'] ?? '';
					$params['port'] = $dbalOptions['port'] ?? 5432;
					$params['dbname'] = $dbalOptions['dbname'] ?? '';
					$params['user'] = $dbalOptions['user'] ?? '';
					$params['password'] = $dbalOptions['password'] ?? '';
				} else {
					// Fall back to standard DB_* constants.
					$params['host'] = \defined('DB_HOST') ? DB_HOST : 'localhost';
					$params['port'] = \defined('DB_PORT') ? DB_PORT : 5432;
					$params['dbname'] = \defined('DB_NAME') ? DB_NAME : '';
					$params['user'] = \defined('DB_USER') ? DB_USER : '';
					$params['password'] = \defined('DB_PASSWORD') ? DB_PASSWORD : '';
				}
				break;

			case 'mysql':
			default:
				// Read MySQL connection constants.
				$params['dbname'] = \defined('DB_NAME') ? DB_NAME : '';
				$params['user'] = \defined('DB_USER') ? DB_USER : '';
				$params['password'] = \defined('DB_PASSWORD') ? DB_PASSWORD : '';
				$params['host'] = \defined('DB_HOST') ? DB_HOST : 'localhost';
				$params['charset'] = \defined('DB_CHARSET') ? DB_CHARSET : 'utf8';
				$params['collate'] = \defined('DB_COLLATE') ? DB_COLLATE : '';
				break;
		}

		return $params;
	}
}

