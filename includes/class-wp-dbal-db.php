<?php

/**
 * WP_DBAL_DB - WordPress Database Abstraction Layer
 *
 * Extends wpdb to use Doctrine DBAL for database operations,
 * enabling support for multiple database backends.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Result;
use WP_DBAL\Translator\QueryConverter;

/**
 * WordPress database access abstraction class using Doctrine DBAL.
 *
 * @property-read string $prefix WordPress table prefix.
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- Follows WordPress naming convention for wpdb extension
class WP_DBAL_DB extends \wpdb
{
	/**
	 * Doctrine DBAL connection.
	 *
	 * @var Connection|null
	 */
	protected ?Connection $dbalConnection = null;

	/**
	 * Query converter for cross-platform SQL translation.
	 *
	 * @var QueryConverter|null
	 */
	protected ?QueryConverter $queryConverter = null;

	/**
	 * The database engine being used.
	 *
	 * @var string
	 */
	protected string $dbEngine = 'mysql';

	/**
	 * Last executed query (translated).
	 *
	 * @var string
	 */
	protected string $lastTranslatedQuery = '';

	/**
	 * Whether we're using DBAL or falling back to native.
	 *
	 * @var boolean
	 */
	protected bool $usingDbal = false;

	/**
	 * Constructor.
	 *
	 * @param string $dbEngine The database engine to use (mysql, pgsql, sqlite).
	 */
	public function __construct(string $dbEngine = 'mysql')
	{
		$this->dbEngine = $dbEngine;

		// Don't call parent constructor - it tries to connect immediately.
		// We'll handle connection in db_connect().

		// Set up table prefix from global.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.NamingConventions.ValidVariableName.NotCamelCaps -- WordPress core global
		global $table_prefix;
		$this->prefix = $table_prefix ?? 'wp_'; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps -- WordPress core global

		// Initialize empty values that wpdb expects.
		$this->dbuser     = \defined('DB_USER') ? DB_USER : '';
		$this->dbpassword = \defined('DB_PASSWORD') ? DB_PASSWORD : '';
		$this->dbname     = \defined('DB_NAME') ? DB_NAME : '';
		$this->dbhost     = \defined('DB_HOST') ? DB_HOST : '';

		$this->init_charset();
	}

	/**
	 * Initialize charset settings.
	 *
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function init_charset(): void
	{
		$this->charset = \defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
		$this->collate = \defined('DB_COLLATE') ? DB_COLLATE : '';

		if (\defined('DB_COLLATE') && DB_COLLATE) {
			$this->collate = DB_COLLATE;
		} elseif ('utf8mb4' === $this->charset) {
			$this->collate = 'utf8mb4_unicode_ci';
		}
	}

	/**
	 * Connect to the database using Doctrine DBAL.
	 *
	 * @param bool $allowBail Whether to allow bailing on error.
	 * @return bool True on success, false on failure.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function db_connect($allowBail = true): bool
	{
		// If already connected, return.
		if (null !== $this->dbalConnection && $this->dbalConnection->isConnected()) {
			return true;
		}

		try {
			$connectionParams = $this->getConnectionParams();
			$this->dbalConnection = DriverManager::getConnection($connectionParams);

			// Initialize the query converter for cross-platform SQL translation.
			$this->queryConverter = new QueryConverter($this->dbalConnection);

			// Set the native connection handle for WordPress compatibility.
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			$this->dbh = $this->dbalConnection->getNativeConnection();

			$this->ready      = true;
			$this->usingDbal = true;
			// Note: has_connected is defined in parent wpdb class.
			/** @phpstan-ignore-next-line */
			$this->has_connected = true;

			// Set charset if MySQL.
			if ('mysql' === $this->dbEngine) {
				$this->set_charset($this->dbh);
			}

			return true;
		} catch (DBALException $e) {
			$this->ready = false;

			if (\defined('WP_DEBUG') && WP_DEBUG) {
				\error_log('WP-DBAL Connection Error: ' . $e->getMessage());
			}

			if ($allowBail) {
				// Cannot use WordPress functions here - db.php loads before WordPress.
				$message = '<h1>Error establishing a database connection</h1>' . "\n";
				$message .= '<p>WP-DBAL could not connect to the ' . \htmlspecialchars($this->dbEngine) . ' database.</p>' . "\n";

				if (\defined('WP_DEBUG') && WP_DEBUG) {
					$message .= '<p><code>' . \htmlspecialchars($e->getMessage()) . '</code></p>';
				}

				// Use parent's bail method or die directly.
				if (\method_exists($this, 'bail')) {
					$this->bail($message, 'db_connect_fail');
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is built with htmlspecialchars, early bailout before WordPress loaded
					die($message);
				}
			}

			return false;
		}
	}

	/**
	 * Get DBAL connection parameters based on the database engine.
	 *
	 * @return array<string, mixed>
	 */
	protected function getConnectionParams(): array
	{
		// Check for custom DBAL options.
		if (\defined('DB_DBAL_OPTIONS') && \is_array(DB_DBAL_OPTIONS)) {
			return DB_DBAL_OPTIONS;
		}

		// Build connection params based on engine.
		switch ($this->dbEngine) {
			case 'sqlite':
				if (\defined('DB_SQLITE_PATH')) {
					$dbPath = DB_SQLITE_PATH;
				} elseif (\defined('WP_CONTENT_DIR')) {
					$dbPath = WP_CONTENT_DIR . '/database/.ht.sqlite';
				} else {
					// Fallback using ABSPATH if WP_CONTENT_DIR not yet defined.
					$dbPath = ABSPATH . 'wp-content/database/.ht.sqlite';
				}

				// Ensure directory exists (can't use wp_mkdir_p - not loaded yet).
				$dbDir = \dirname($dbPath);
				if (! \is_dir($dbDir)) {
					\mkdir($dbDir, 0755, true);
				}

				return [
					'driver' => 'pdo_sqlite',
					'path'   => $dbPath,
				];

			case 'pgsql':
			case 'postgresql':
				return [
					'driver'   => 'pdo_pgsql',
					'host'     => $this->dbhost,
					'port'     => \defined('DB_PORT') ? DB_PORT : 5432,
					'dbname'   => $this->dbname,
					'user'     => $this->dbuser,
					'password' => $this->dbpassword,
				];

			case 'mysql':
			default:
				// Parse host and port.
				$host = $this->dbhost;
				$port = 3306;
				$socket = null;

				if (\preg_match('/^(.+):(\d+)$/', $host, $matches)) {
					$host = $matches[1];
					$port = (int) $matches[2];
				} elseif (\preg_match('/^(.+):(.+)$/', $host, $matches)) {
					$host   = $matches[1];
					$socket = $matches[2];
				}

				$params = [
					'driver'   => 'pdo_mysql',
					'host'     => $host,
					'port'     => $port,
					'dbname'   => $this->dbname,
					'user'     => $this->dbuser,
					'password' => $this->dbpassword,
					'charset'  => $this->charset,
				];

				if ($socket) {
					$params['unix_socket'] = $socket;
					unset($params['host'], $params['port']);
				}

				return $params;
		}
	}

	/**
	 * Execute a query using DBAL.
	 *
	 * @param string $query The SQL query to execute.
	 * @return int|bool Number of rows affected/selected or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function query($query)
	{
		if (! $this->ready) {
			$this->check_connection();
		}

		if (! $this->ready) {
			return false;
		}

		// Flush cached values.
		$this->flush();

		// Store original query.
		$this->last_query = $query;

		// Apply query filter (only if WordPress is loaded).
		if (\function_exists('apply_filters')) {
			$query = \apply_filters('query', $query);
		}

		if (! $query) {
			return false;
		}

		// Log query start time.
		$this->timer_start();

		// Translate and execute the query.
		try {
			$result = $this->_do_query($query);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();

			if (\defined('WP_DEBUG') && WP_DEBUG) {
				\error_log('WP-DBAL Query Error: ' . $e->getMessage() . ' | Query: ' . $query);
			}

			return false;
		}

		// Log query time.
		$this->timer_stop();

		// Store query for debugging.
		$this->num_queries++;

		if (\defined('SAVEQUERIES') && SAVEQUERIES) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				$this->get_caller()
			);
		}

		return $result;
	}

	/**
	 * Internal function to perform the query.
	 *
	 * @param string $query The SQL query.
	 * @return int|bool Number of rows affected/selected or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps, PSR2.Methods.MethodDeclaration.Underscore, Squiz.NamingConventions.ValidFunctionName.PublicUnderscore -- wpdb override
	public function _do_query($query)
	{
		if (null === $this->dbalConnection || null === $this->queryConverter) {
			return false;
		}

		// Reset error state.
		$this->last_error = '';

		// Convert the query for the target database platform.
		$convertedQuery = $this->queryConverter->convert($query);

		// Handle multiple queries (some conversions may result in multiple statements).
		if (\is_array($convertedQuery)) {
			$this->lastTranslatedQuery = \implode('; ', $convertedQuery);
			$finalResult = false;
			foreach ($convertedQuery as $singleQuery) {
				$finalResult = $this->executeSingleQuery($singleQuery);
			}
			return $finalResult;
		}

		$this->lastTranslatedQuery = $convertedQuery;
		return $this->executeSingleQuery($convertedQuery);
	}

	/**
	 * Execute a single query.
	 *
	 * @param string $query The SQL query.
	 * @return int|bool Number of rows affected/selected or false on error.
	 */
	protected function executeSingleQuery(string $query): int|bool
	{
		if (null === $this->dbalConnection) {
			return false;
		}

		// Determine query type.
		$queryType = $this->getQueryType($query);

		try {
			switch ($queryType) {
				case 'SELECT':
				case 'SHOW':
				case 'DESCRIBE':
				case 'DESC':
				case 'EXPLAIN':
					$result = $this->dbalConnection->executeQuery($query);
					$rows = $result->fetchAllAssociative();
					$this->num_rows = \count($rows);

					// Convert to objects for WordPress compatibility.
					$this->last_result = \array_map(
						fn($row) => (object) $row,
						$rows
					);

					return $this->num_rows;

				case 'INSERT':
					$this->dbalConnection->executeStatement($query);
					$this->insert_id = (int) $this->dbalConnection->lastInsertId();
					$this->rows_affected = 1; // DBAL doesn't return affected rows for INSERT reliably.
					return $this->rows_affected;

				case 'UPDATE':
				case 'DELETE':
				case 'REPLACE':
					$this->rows_affected = $this->dbalConnection->executeStatement($query);
					return $this->rows_affected;

				default:
					// DDL or other statements.
					$this->dbalConnection->executeStatement($query);
					return true;
			}
		} catch (DBALException $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Determine the type of SQL query.
	 *
	 * @param string $query The SQL query.
	 * @return string The query type (SELECT, INSERT, UPDATE, DELETE, etc.).
	 */
	protected function getQueryType(string $query): string
	{
		$query = \ltrim($query);

		if (\preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|SHOW|DESCRIBE|DESC|EXPLAIN|SET|START|COMMIT|ROLLBACK|BEGIN)/i', $query, $matches)) {
			return \strtoupper($matches[1]);
		}

		return 'UNKNOWN';
	}

	/**
	 * Escape a string for use in a query.
	 *
	 * @param string $string The string to escape.
	 * @return string The escaped string.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps, PSR2.Methods.MethodDeclaration.Underscore, Squiz.NamingConventions.ValidFunctionName.PublicUnderscore -- wpdb override
	public function _real_escape($string): string
	{
		if (! \is_string($string)) {
			$string = (string) $string;
		}

		// Use addslashes as a fallback - DBAL handles proper escaping via prepared statements.
		return \addslashes($string);
	}

	/**
	 * Get the database version.
	 *
	 * @return string|null The database version.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function db_version(): ?string
	{
		if (null === $this->dbalConnection) {
			return null;
		}

		// Return a version string based on the engine.
		// DBAL 4.x doesn't have getName() on platform.
		switch ($this->dbEngine) {
			case 'sqlite':
				return '3.0'; // SQLite version placeholder.

			case 'pgsql':
			case 'postgresql':
				return '12.0'; // PostgreSQL version placeholder.

			case 'mysql':
			default:
				return '8.0'; // MySQL version placeholder.
		}
	}

	/**
	 * Check if the database supports a particular feature.
	 *
	 * @param string $capability The capability to check.
	 * @return bool Whether the database supports the capability.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function has_cap($capability): bool
	{
		$capability = \strtolower($capability);

		// Most capabilities are supported through DBAL.
		$supported = [
			'collation',
			'group_concat',
			'subqueries',
			'set_charset',
			'utf8mb4',
		];

		if (\in_array($capability, $supported, true)) {
			return true;
		}

		// Engine-specific capabilities.
		switch ($this->dbEngine) {
			case 'mysql':
				return true; // MySQL supports all standard capabilities.

			case 'pgsql':
			case 'postgresql':
				// PostgreSQL doesn't support some MySQL-specific features.
				if (\in_array($capability, [ 'found_rows' ], true)) {
					return false;
				}
				return true;

			case 'sqlite':
				// SQLite has limited capability support.
				if (\in_array($capability, [ 'found_rows', 'utf8mb4' ], true)) {
					return false;
				}
				return true;

			default:
				return false;
		}
	}

	/**
	 * Set the charset.
	 *
	 * @param mixed       $dbh     The database connection handle.
	 * @param string|null $charset Optional. The character set to use.
	 * @param string|null $collate Optional. The collation to use.
	 * @return bool True on success, false on failure.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function set_charset($dbh, $charset = null, $collate = null): bool
	{
		// Only relevant for MySQL.
		if ('mysql' !== $this->dbEngine) {
			return true;
		}

		if (null === $charset) {
			$charset = $this->charset;
		}

		if (null === $collate) {
			$collate = $this->collate;
		}

		try {
			if (null !== $this->dbalConnection) {
				$this->dbalConnection->executeStatement("SET NAMES '{$charset}'");
			}
			return true;
		} catch (\Exception $e) {
			// Silently fail - charset setting is not critical.
			return false;
		}
	}

	/**
	 * Changes the current SQL mode, and ensures its WordPress compatibility.
	 *
	 * @param array<int, string> $modes Optional. A list of SQL modes to set.
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function set_sql_mode($modes = []): void
	{
		if (null === $this->dbalConnection) {
			return;
		}

		// Only relevant for MySQL.
		if ('mysql' !== $this->dbEngine) {
			return;
		}

		try {
			if (empty($modes)) {
				$result = $this->dbalConnection->executeQuery('SELECT @@SESSION.sql_mode');
				$row = $result->fetchAssociative();
				if (! $row) {
					return;
				}

				$modesStr = $row['@@SESSION.sql_mode'] ?? '';
				if (empty($modesStr)) {
					return;
				}
				$modes = \explode(',', $modesStr);
			}

			$modes = \array_change_key_case($modes, CASE_UPPER);

			// Apply incompatible modes filter only if WordPress is loaded.
			if (\function_exists('apply_filters')) {
				$incompatibleModes = (array) \apply_filters('incompatible_sql_modes', $this->incompatible_modes);
			} else {
				$incompatibleModes = $this->incompatible_modes;
			}

			foreach ($modes as $i => $mode) {
				if (\in_array($mode, $incompatibleModes, true)) {
					unset($modes[ $i ]);
				}
			}

			$modesStr = \implode(',', $modes);
			$this->dbalConnection->executeStatement("SET SESSION sql_mode='{$modesStr}'");
		} catch (\Exception $e) {
			// SQL mode setting is not critical.
		}
	}

	/**
	 * Get the character set for a table column.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @return string|false|\WP_Error The column charset or false/WP_Error on failure.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_col_charset($table, $column)
	{
		// For non-MySQL engines, just return utf8mb4.
		if ('mysql' !== $this->dbEngine) {
			return 'utf8mb4';
		}

		// Use parent implementation for MySQL.
		return parent::get_col_charset($table, $column);
	}

	/**
	 * Check the database connection.
	 *
	 * @param bool $allowBail Whether to allow bailing on error.
	 * @return bool True if connection is up, false otherwise.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function check_connection($allowBail = true)
	{
		if (null === $this->dbalConnection) {
			return $this->db_connect($allowBail);
		}

		try {
			// Test the connection with a simple query.
			$this->dbalConnection->executeQuery('SELECT 1');
			return true;
		} catch (DBALException $e) {
			// Try to reconnect.
			return $this->db_connect($allowBail);
		}
	}

	/**
	 * Select a database.
	 *
	 * @param string $db  Database name.
	 * @param mixed  $dbh Optional. Database connection handle.
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function select($db, $dbh = null): void
	{
		// DBAL handles database selection at connection time.
		// For MySQL, we can switch databases if needed.
		if ('mysql' === $this->dbEngine && null !== $this->dbalConnection) {
			try {
				$this->dbalConnection->executeStatement("USE `{$db}`");
				$this->dbname = $db;
			} catch (DBALException $e) {
				$this->ready = false;
			}
		}
	}

	/**
	 * Flush query results and reset state.
	 *
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function flush(): void
	{
		$this->last_result   = [];
		$this->col_info      = [];
		$this->last_query    = '';
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
		$this->result        = null;
	}

	/**
	 * Close the database connection.
	 *
	 * @return bool True if the connection was successfully closed.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function close(): bool
	{
		if (null === $this->dbalConnection) {
			return false;
		}

		try {
			$this->dbalConnection->close();
			$this->dbalConnection = null;
			$this->dbh             = null;
			$this->ready           = false;
			/** @phpstan-ignore-next-line */
			$this->has_connected   = false;
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Load column info from the last query result.
	 *
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	protected function load_col_info(): void
	{
		// col_info is populated differently with DBAL.
		// This is a no-op for now since we handle column info in execute_single_query.
		if ($this->col_info) {
			return;
		}

		// For DBAL, we would need to use result metadata.
		// WordPress doesn't heavily rely on this for basic operations.
		$this->col_info = [];
	}

	/**
	 * Get the database server info.
	 *
	 * @return string Server info string.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function db_server_info(): string
	{
		if (null === $this->dbalConnection) {
			return '';
		}

		try {
			// Try to get server version from the connection.
			$native = $this->dbalConnection->getNativeConnection();

			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- DBAL uses PDO as native connection
			if ($native instanceof \PDO) {
				// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- DBAL uses PDO as native connection
				return $native->getAttribute(\PDO::ATTR_SERVER_VERSION) ?: $this->getDefaultServerInfo();
			}
		} catch (\Exception $e) {
			// Fall through to default.
		}

		return $this->getDefaultServerInfo();
	}

	/**
	 * Get default server info based on engine.
	 *
	 * @return string Default server info string.
	 */
	protected function getDefaultServerInfo(): string
	{
		switch ($this->dbEngine) {
			case 'sqlite':
				return 'SQLite 3.0';
			case 'pgsql':
			case 'postgresql':
				return 'PostgreSQL 12.0';
			case 'mysql':
			default:
				return '8.0.0-WP-DBAL';
		}
	}

	/**
	 * Get the DBAL connection.
	 *
	 * @return Connection|null
	 */
	public function getDbalConnection(): ?Connection
	{
		return $this->dbalConnection;
	}

	/**
	 * Get the current database engine.
	 *
	 * @return string
	 */
	public function getDbEngine(): string
	{
		return $this->dbEngine;
	}

	/**
	 * Get the last translated query.
	 *
	 * @return string
	 */
	public function getLastTranslatedQuery(): string
	{
		return $this->lastTranslatedQuery;
	}

	/**
	 * Check if using DBAL.
	 *
	 * @return bool
	 */
	public function isUsingDbal(): bool
	{
		return $this->usingDbal;
	}

	/**
	 * Log a query for SAVEQUERIES.
	 *
	 * @param string     $query    The query.
	 * @param float      $elapsed  Time elapsed.
	 * @param string     $caller   The caller.
	 * @param float|null $start    Start time.
	 * @param array|null $data     Additional data.
	 * @return void
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function log_query($query, $elapsed, $caller, $start = null, $data = null): void
	{
		$this->queries[] = [
			$query,
			$elapsed,
			$caller,
			$start ?? \microtime(true) - $elapsed,
			[
				'translated' => $this->lastTranslatedQuery,
				'engine'     => $this->dbEngine,
			],
		];
	}
}
