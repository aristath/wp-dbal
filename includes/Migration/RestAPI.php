<?php

/**
 * Migration REST API
 *
 * REST API endpoints for database migration.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API class.
 */
class RestAPI
{
	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'wp-dbal/v1';

	/**
	 * Migration manager instance.
	 *
	 * @var MigrationManager
	 */
	private MigrationManager $migrationManager;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->migrationManager = new MigrationManager();
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void
	{
		// Get migration status.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/status',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'getStatus' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		// Validate target connection.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/validate',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'validateConnection' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'target_engine' => [
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

		// Start migration.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/start',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'startMigration' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'target_engine' => [
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

		// Get migration progress.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/progress',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'getProgress' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'session_id' => [
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Process migration chunk.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/chunk',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'processChunk' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'session_id' => [
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Cancel migration.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/cancel',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'cancelMigration' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'session_id' => [
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Update wp-config.php.
		\register_rest_route(
			self::NAMESPACE,
			'/migration/update-config',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'updateConfig' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args' => [
					'target_engine' => [
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
	 * Get migration status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getStatus(WP_REST_Request $request)
	{
		$currentEngine = $this->migrationManager->getCurrentEngine();
		
		return new WP_REST_Response(
			[
				'success' => true,
				'current_engine' => $currentEngine,
			],
			200
		);
	}

	/**
	 * Validate target connection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validateConnection(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$targetEngine = $bodyParams['target_engine'] ?? $request->get_param('target_engine');
		$connectionParams = $bodyParams['connection_params'] ?? $request->get_param('connection_params') ?? [];

		if (empty($targetEngine)) {
			return new WP_Error(
				'missing_parameter',
				\__('target_engine parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$validation = $this->migrationManager->validateTargetConnection($targetEngine, $connectionParams);

		if (! $validation['success']) {
			return new WP_Error(
				'validation_failed',
				$validation['message'],
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => $validation['message'],
			],
			200
		);
	}

	/**
	 * Start migration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function startMigration(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$targetEngine = $bodyParams['target_engine'] ?? $request->get_param('target_engine');
		$connectionParams = $bodyParams['connection_params'] ?? $request->get_param('connection_params') ?? [];

		if (empty($targetEngine)) {
			return new WP_Error(
				'missing_parameter',
				\__('target_engine parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$result = $this->migrationManager->startMigration($targetEngine, $connectionParams);

		if (! $result['success']) {
			return new WP_Error(
				'migration_start_failed',
				$result['error'] ?? \__('Failed to start migration', 'wp-dbal'),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'session_id' => $result['session_id'],
			],
			200
		);
	}

	/**
	 * Get migration progress.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getProgress(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$sessionId = $bodyParams['session_id'] ?? $request->get_param('session_id');
		
		if (empty($sessionId)) {
			return new WP_Error(
				'missing_parameter',
				\__('session_id parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$progress = $this->migrationManager->getProgress($sessionId);

		if (! $progress) {
			return new WP_Error(
				'session_not_found',
				\__('Migration session not found or expired', 'wp-dbal'),
				[ 'status' => 404 ]
			);
		}

		// Verify session belongs to current user.
		if (isset($progress['user_id']) && $progress['user_id'] !== \get_current_user_id()) {
			return new WP_Error(
				'unauthorized',
				\__('Unauthorized access to migration session', 'wp-dbal'),
				[ 'status' => 403 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'progress' => $progress,
			],
			200
		);
	}

	/**
	 * Process migration chunk.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function processChunk(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$sessionId = $bodyParams['session_id'] ?? $request->get_param('session_id');
		
		if (empty($sessionId)) {
			return new WP_Error(
				'missing_parameter',
				\__('session_id parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}
		
		try {
			$result = $this->migrationManager->processChunk($sessionId);
			
			return new WP_REST_Response(
				[
					'success' => true,
					'complete' => $result['complete'],
					'progress' => $result['progress'],
				],
				200
			);
		} catch (\Exception $e) {
			return new WP_Error(
				'chunk_processing_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Cancel migration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancelMigration(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$sessionId = $bodyParams['session_id'] ?? $request->get_param('session_id');
		
		if (empty($sessionId)) {
			return new WP_Error(
				'missing_parameter',
				\__('session_id parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$result = $this->migrationManager->cancelMigration($sessionId);

		if (! $result) {
			return new WP_Error(
				'cancel_failed',
				\__('Failed to cancel migration. Session not found or unauthorized.', 'wp-dbal'),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => \__('Migration cancelled', 'wp-dbal'),
			],
			200
		);
	}

	/**
	 * Update wp-config.php.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateConfig(WP_REST_Request $request)
	{
		// Get params from body (POST) or query (GET).
		$bodyParams = $request->get_json_params();
		$targetEngine = $bodyParams['target_engine'] ?? $request->get_param('target_engine');
		$connectionParams = $bodyParams['connection_params'] ?? $request->get_param('connection_params') ?? [];

		if (empty($targetEngine)) {
			return new WP_Error(
				'missing_parameter',
				\__('target_engine parameter is required', 'wp-dbal'),
				[ 'status' => 400 ]
			);
		}

		$configWriter = new \WP_DBAL\Migration\ConfigWriter();
		$result = $configWriter->updateConfig($targetEngine, $connectionParams);

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
				'message' => \__('wp-config.php updated successfully', 'wp-dbal'),
			],
			200
		);
	}
}

