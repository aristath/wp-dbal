<?php

/**
 * Migration Manager
 *
 * Coordinates database migration between different backends.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use WP_DBAL\Migration\SchemaExporter;
use WP_DBAL\Migration\DataExporter;
use WP_DBAL\Migration\SchemaImporter;
use WP_DBAL\Migration\DataImporter;

/**
 * Migration Manager class.
 */
class MigrationManager
{
	/**
	 * Progress file directory.
	 *
	 * @var string
	 */
	private string $progressDir;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$upload_dir = \wp_upload_dir();
		$this->progressDir = $upload_dir['basedir'] . '/.wp-dbal-migrations';
		
		// Ensure directory exists.
		if (! \is_dir($this->progressDir)) {
			\wp_mkdir_p($this->progressDir);
		}
	}

	/**
	 * Get current database engine.
	 *
	 * @return string Current database engine (defaults to 'mysql' if not defined).
	 */
	public function getCurrentEngine(): string
	{
		return \defined('DB_ENGINE') ? \strtolower(DB_ENGINE) : 'mysql';
	}

	/**
	 * Get WordPress tables.
	 *
	 * @param Connection $connection Database connection.
	 * @return array<int, string> Array of table names.
	 */
	public function getWordPressTables(Connection $connection): array
	{
		global $wpdb;
		$prefix = $wpdb->prefix;
		
		// Escape LIKE wildcards in prefix.
		$prefix_escaped = \str_replace([ '_', '%' ], [ '\_', '\%' ], $prefix);
		$like_pattern   = $prefix_escaped . '%';

		// Get tables based on engine.
		$engine = $this->getCurrentEngine();
		
		if ('mysql' === $engine) {
			$tables = $wpdb->get_col(
				$wpdb->prepare('SHOW TABLES LIKE %s', $like_pattern)
			);
		} else {
			// Use Doctrine DBAL schema manager for other engines.
			$schemaManager = $connection->createSchemaManager();
			$all_tables = $schemaManager->listTableNames();
			$tables = \array_filter($all_tables, function ($table) use ($prefix) {
				return \strpos($table, $prefix) === 0;
			});
		}

		return $tables ?: [];
	}

	/**
	 * Validate target database connection.
	 *
	 * @param string $targetEngine Target database engine.
	 * @param array<string, mixed> $connectionParams Connection parameters.
	 * @return array{success: bool, message: string, connection?: Connection}
	 */
	public function validateTargetConnection(string $targetEngine, array $connectionParams): array
	{
		try {
			// Build connection params based on target engine.
			$params = $this->buildConnectionParams($targetEngine, $connectionParams);
			
			$connection = DriverManager::getConnection($params);
			
			// Test connection by executing a simple query.
			$connection->fetchOne('SELECT 1');
			
			return [
				'success' => true,
				'message' => \__('Connection successful', 'wp-dbal'),
				'connection' => $connection,
			];
		} catch (DBALException $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Build connection parameters for target engine.
	 *
	 * @param string $targetEngine Target database engine.
	 * @param array<string, mixed> $userParams User-provided connection parameters.
	 * @return array<string, mixed> Connection parameters.
	 */
	private function buildConnectionParams(string $targetEngine, array $userParams): array
	{
		// If custom DBAL options provided, use them.
		if (! empty($userParams['dbal_options']) && \is_array($userParams['dbal_options'])) {
			return $userParams['dbal_options'];
		}

		$engine = \strtolower($targetEngine);
		
		switch ($engine) {
			case 'filedb':
				$storagePath = $userParams['path'] ?? null;
				if (null === $storagePath) {
					if (\defined('WP_CONTENT_DIR')) {
						$storagePath = WP_CONTENT_DIR . '/file-db';
					} else {
						$storagePath = ABSPATH . 'wp-content/file-db';
					}
				}

				return [
					'driverClass' => \WP_DBAL\FileDB\Driver::class,
					'path'        => $storagePath,
					'format'      => $userParams['format'] ?? 'json',
				];

			case 'sqlite':
				$dbPath = $userParams['path'] ?? null;
				if (null === $dbPath) {
					if (\defined('WP_CONTENT_DIR')) {
						$dbPath = WP_CONTENT_DIR . '/database/.ht.sqlite';
					} else {
						$dbPath = ABSPATH . 'wp-content/database/.ht.sqlite';
					}
				}

				// Ensure directory exists.
				$dbDir = \dirname($dbPath);
				if (! \is_dir($dbDir)) {
					\wp_mkdir_p($dbDir);
				}

				return [
					'driver' => 'pdo_sqlite',
					'path'   => $dbPath,
				];

			case 'd1':
				return [
					'driverClass' => \WP_DBAL\D1\Driver::class,
					'account_id'  => $userParams['account_id'] ?? '',
					'database_id' => $userParams['database_id'] ?? '',
					'api_token'   => $userParams['api_token'] ?? '',
				];

			case 'pgsql':
			case 'postgresql':
				return [
					'driver'   => 'pdo_pgsql',
					'host'     => $userParams['host'] ?? 'localhost',
					'port'     => $userParams['port'] ?? 5432,
					'dbname'   => $userParams['dbname'] ?? '',
					'user'     => $userParams['user'] ?? '',
					'password' => $userParams['password'] ?? '',
				];

			case 'mysql':
			default:
				return [
					'driver'   => 'pdo_mysql',
					'host'     => $userParams['host'] ?? 'localhost',
					'port'     => $userParams['port'] ?? 3306,
					'dbname'   => $userParams['dbname'] ?? '',
					'user'     => $userParams['user'] ?? '',
					'password' => $userParams['password'] ?? '',
					'charset'  => $userParams['charset'] ?? 'utf8mb4',
				];
		}
	}

	/**
	 * Start migration.
	 *
	 * @param string $targetEngine Target database engine.
	 * @param array<string, mixed> $connectionParams Connection parameters.
	 * @return array{success: bool, session_id?: string, error?: string}
	 */
	public function startMigration(string $targetEngine, array $connectionParams): array
	{
		$sessionId = \wp_generate_uuid4();
		$sourceEngine = $this->getCurrentEngine();

		// Validate target connection.
		$validation = $this->validateTargetConnection($targetEngine, $connectionParams);
		if (! $validation['success']) {
			return [
				'success' => false,
				'error' => $validation['message'],
			];
		}

		// Initialize progress.
		$progress = [
			'session_id' => $sessionId,
			'user_id' => \get_current_user_id(),
			'created_at' => \time(),
			'status' => 'running',
			'step' => 'schema_export',
			'current_table' => '',
			'tables_total' => 0,
			'tables_completed' => 0,
			'rows_total' => 0,
			'rows_completed' => 0,
			'error' => null,
			'source_engine' => $sourceEngine,
			'target_engine' => $targetEngine,
			'connection_params' => $connectionParams,
		];

		$this->saveProgress($sessionId, $progress);

		return [
			'success' => true,
			'session_id' => $sessionId,
		];
	}

	/**
	 * Get migration progress.
	 *
	 * @param string $sessionId Session ID.
	 * @return array<string, mixed>|null Progress data or null if not found.
	 */
	public function getProgress(string $sessionId): ?array
	{
		$file = $this->getProgressFile($sessionId);
		
		if (! \file_exists($file)) {
			return null;
		}

		$content = \file_get_contents($file);
		if (false === $content) {
			return null;
		}

		$progress = \json_decode($content, true);
		if (! \is_array($progress)) {
			return null;
		}

		// Check expiration (1 hour).
		if (isset($progress['created_at'])) {
			$age = \time() - $progress['created_at'];
			if ($age > HOUR_IN_SECONDS) {
				$this->deleteProgress($sessionId);
				return null;
			}
		}

		return $progress;
	}

	/**
	 * Save migration progress.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Progress data.
	 * @return bool True on success, false on failure.
	 */
	public function saveProgress(string $sessionId, array $progress): bool
	{
		$file = $this->getProgressFile($sessionId);
		$content = \wp_json_encode($progress, JSON_PRETTY_PRINT);
		
		return (bool) \file_put_contents($file, $content, LOCK_EX);
	}

	/**
	 * Delete migration progress.
	 *
	 * @param string $sessionId Session ID.
	 * @return bool True on success, false on failure.
	 */
	public function deleteProgress(string $sessionId): bool
	{
		$file = $this->getProgressFile($sessionId);
		
		if (\file_exists($file)) {
			return \unlink($file);
		}

		return true;
	}

	/**
	 * Get progress file path.
	 *
	 * @param string $sessionId Session ID.
	 * @return string File path.
	 */
	private function getProgressFile(string $sessionId): string
	{
		return $this->progressDir . '/migration-' . \sanitize_file_name($sessionId) . '.json';
	}

	/**
	 * Process migration chunk.
	 *
	 * @param string $sessionId Session ID.
	 * @return array{complete: bool, progress: array<string, mixed>}
	 */
	public function processChunk(string $sessionId): array
	{
		$progress = $this->getProgress($sessionId);
		if (! $progress) {
			return [
				'complete' => true,
				'progress' => [
					'status' => 'failed',
					'error' => \__('Migration session not found or expired', 'wp-dbal'),
				],
			];
		}

		// Get source and target connections.
		global $wpdb;
		$sourceConnection = null;
		if ($wpdb instanceof \WP_DBAL\WP_DBAL_DB) {
			$sourceConnection = $wpdb->getDbalConnection();
		}
		
		if (! $sourceConnection) {
			$progress['status'] = 'failed';
			$progress['error'] = \__('Source database connection not available', 'wp-dbal');
			$this->saveProgress($sessionId, $progress);
			return [
				'complete' => true,
				'progress' => $progress,
			];
		}

		$targetParams = $this->buildConnectionParams($progress['target_engine'], $progress['connection_params']);
		$targetConnection = DriverManager::getConnection($targetParams);

		try {
			// Process based on current step.
			switch ($progress['step']) {
				case 'schema_export':
					$result = $this->exportSchema($sessionId, $progress, $sourceConnection, $targetConnection);
					break;
				case 'data_export':
					$result = $this->exportData($sessionId, $progress, $sourceConnection, $targetConnection);
					break;
				case 'schema_import':
					$result = $this->importSchema($sessionId, $progress, $sourceConnection, $targetConnection);
					break;
				case 'data_import':
					$result = $this->importData($sessionId, $progress, $sourceConnection, $targetConnection);
					break;
				case 'finalize':
					$result = $this->finalizeMigration($sessionId, $progress, $targetConnection);
					break;
				default:
					$progress['status'] = 'failed';
					$progress['error'] = \sprintf(\__('Unknown migration step: %s', 'wp-dbal'), $progress['step']);
					$this->saveProgress($sessionId, $progress);
					return [
						'complete' => true,
						'progress' => $progress,
					];
			}

			// Update progress.
			$progress = \array_merge($progress, $result);
			$this->saveProgress($sessionId, $progress);

			return [
				'complete' => $progress['status'] === 'completed' || $progress['status'] === 'failed',
				'progress' => $progress,
			];
		} catch (\Exception $e) {
			$progress['status'] = 'failed';
			$progress['error'] = $e->getMessage();
			$this->saveProgress($sessionId, $progress);
			
			return [
				'complete' => true,
				'progress' => $progress,
			];
		}
	}

	/**
	 * Export schema.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Current progress.
	 * @param Connection $sourceConnection Source database connection.
	 * @param Connection $targetConnection Target database connection.
	 * @return array<string, mixed> Updated progress.
	 */
	private function exportSchema(string $sessionId, array $progress, Connection $sourceConnection, Connection $targetConnection): array
	{
		$exporter = new SchemaExporter();
		$tables = $this->getWordPressTables($sourceConnection);
		
		if (empty($tables)) {
			$progress['step'] = 'data_export';
			$progress['tables_total'] = 0;
			return $progress;
		}

		$progress['tables_total'] = \count($tables);
		$schemas = $exporter->exportSchemas($sourceConnection, $tables);
		
		// Store schemas in progress for later use.
		$progress['schemas'] = $schemas;
		$progress['step'] = 'schema_import';
		
		return $progress;
	}

	/**
	 * Import schema.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Current progress.
	 * @param Connection $sourceConnection Source database connection.
	 * @param Connection $targetConnection Target database connection.
	 * @return array<string, mixed> Updated progress.
	 */
	private function importSchema(string $sessionId, array $progress, Connection $sourceConnection, Connection $targetConnection): array
	{
		if (empty($progress['schemas'])) {
			$progress['step'] = 'data_export';
			return $progress;
		}

		$importer = new SchemaImporter();
		$importer->importSchemas($targetConnection, $progress['schemas'], $progress['target_engine']);
		
		$progress['step'] = 'data_export';
		
		return $progress;
	}

	/**
	 * Export data.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Current progress.
	 * @param Connection $sourceConnection Source database connection.
	 * @param Connection $targetConnection Target database connection.
	 * @return array<string, mixed> Updated progress.
	 */
	private function exportData(string $sessionId, array $progress, Connection $sourceConnection, Connection $targetConnection): array
	{
		$tables = $this->getWordPressTables($sourceConnection);
		
		if (empty($tables)) {
			$progress['step'] = 'data_import';
			$progress['current_table_index'] = 0;
			return $progress;
		}

		// Initialize table list if not set.
		if (! isset($progress['tables_list'])) {
			$progress['tables_list'] = $tables;
			$progress['tables_total'] = \count($tables);
			$progress['current_table_index'] = 0;
			$progress['table_offsets'] = [];
			$progress['rows_total'] = 0;
			$progress['rows_completed'] = 0;
			
			// Calculate total rows.
			$exporter = new DataExporter();
			foreach ($tables as $table) {
				$progress['rows_total'] += $exporter->getTableRowCount($sourceConnection, $table);
			}
		}

		$exporter = new DataExporter();
		$currentTableIndex = $progress['current_table_index'] ?? 0;
		
		if ($currentTableIndex >= \count($progress['tables_list'])) {
			// All tables exported, move to import.
			$progress['step'] = 'data_import';
			$progress['current_table_index'] = 0;
			return $progress;
		}

		$table = $progress['tables_list'][$currentTableIndex];
		$progress['current_table'] = $table;
		
		// Export chunk of data from current table.
		$offset = $progress['table_offsets'][$table] ?? 0;
		$batchSize = 1000;
		
		$data = $exporter->exportTableChunk($sourceConnection, $table, $offset, $batchSize);
		
		if (empty($data)) {
			// Table complete, move to next.
			$currentTableIndex++;
			$progress['current_table_index'] = $currentTableIndex;
			$progress['tables_completed'] = $currentTableIndex;
			$progress['current_table'] = $currentTableIndex < \count($progress['tables_list']) 
				? $progress['tables_list'][$currentTableIndex] 
				: '';
			unset($progress['table_offsets'][$table]);
		} else {
			// Store data chunk.
			if (! isset($progress['data_chunks'])) {
				$progress['data_chunks'] = [];
			}
			$progress['data_chunks'][] = [
				'table' => $table,
				'data' => $data,
			];
			$progress['table_offsets'][$table] = $offset + \count($data);
			$progress['rows_completed'] += \count($data);
		}

		return $progress;
	}

	/**
	 * Import data.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Current progress.
	 * @param Connection $sourceConnection Source database connection.
	 * @param Connection $targetConnection Target database connection.
	 * @return array<string, mixed> Updated progress.
	 */
	private function importData(string $sessionId, array $progress, Connection $sourceConnection, Connection $targetConnection): array
	{
		if (empty($progress['data_chunks'])) {
			// All data imported, finalize.
			$progress['step'] = 'finalize';
			return $progress;
		}

		$importer = new DataImporter();
		$chunk = \array_shift($progress['data_chunks']);
		
		try {
			$importer->importTableChunk($targetConnection, $chunk['table'], $chunk['data']);
		} catch (\Exception $e) {
			$progress['status'] = 'failed';
			$progress['error'] = \sprintf(
				\__('Failed to import data to table %s: %s', 'wp-dbal'),
				$chunk['table'],
				$e->getMessage()
			);
			return $progress;
		}
		
		return $progress;
	}

	/**
	 * Finalize migration.
	 *
	 * @param string $sessionId Session ID.
	 * @param array<string, mixed> $progress Current progress.
	 * @param Connection $targetConnection Target database connection.
	 * @return array<string, mixed> Updated progress.
	 */
	private function finalizeMigration(string $sessionId, array $progress, Connection $targetConnection): array
	{
		// Mark migration as completed.
		// Note: wp-config.php update is handled separately via user consent in the UI.
		$progress['status'] = 'completed';
		$progress['step'] = 'completed';
		
		return $progress;
	}

	/**
	 * Cancel migration.
	 *
	 * @param string $sessionId Session ID.
	 * @return bool True if cancelled successfully.
	 */
	public function cancelMigration(string $sessionId): bool
	{
		$progress = $this->getProgress($sessionId);
		if (! $progress) {
			return false;
		}

		// Verify session belongs to current user.
		if (isset($progress['user_id']) && $progress['user_id'] !== \get_current_user_id()) {
			return false;
		}

		// Update progress to cancelled.
		$progress['status'] = 'cancelled';
		$progress['error'] = \__('Migration cancelled by user', 'wp-dbal');
		$this->saveProgress($sessionId, $progress);

		// Delete progress file after a short delay (optional cleanup).
		// For now, we'll keep it so user can see it was cancelled.
		
		return true;
	}
}

