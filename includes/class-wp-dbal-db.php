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
			case 'filedb':
				// File-based database storage.
				$storagePath = \defined('DB_FILEDB_PATH') ? DB_FILEDB_PATH : null;
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
					'format'      => \defined('DB_FILEDB_FORMAT') ? DB_FILEDB_FORMAT : 'json',
				];

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

			case 'd1':
				// Cloudflare D1 database via REST API.
				return [
					'driverClass' => \WP_DBAL\D1\Driver::class,
					'account_id'  => \defined('DB_D1_ACCOUNT_ID') ? DB_D1_ACCOUNT_ID : '',
					'database_id' => \defined('DB_D1_DATABASE_ID') ? DB_D1_DATABASE_ID : '',
					'api_token'   => \defined('DB_D1_API_TOKEN') ? DB_D1_API_TOKEN : '',
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

		// Return a MySQL-compatible version string for all engines.
		// WordPress checks for MySQL 5.5.5+ during installation, so we need
		// to return a compatible version even for non-MySQL backends.
		// DBAL 4.x doesn't have getName() on platform.
		switch ($this->dbEngine) {
			case 'filedb':
				// Return MySQL 8.0 compatible version for WordPress compatibility.
				return '8.0.0-FileDB';

			case 'sqlite':
				return '8.0.0-SQLite';

			case 'd1':
				// D1 is SQLite-based.
				return '8.0.0-D1';

			case 'pgsql':
			case 'postgresql':
				return '8.0.0-PostgreSQL';

			case 'mysql':
			default:
				return '8.0';
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
			case 'd1':
				// SQLite and D1 (SQLite-based) have limited capability support.
				if (\in_array($capability, [ 'found_rows', 'utf8mb4' ], true)) {
					return false;
				}
				return true;

			case 'filedb':
				// FileDB has limited capability support.
				if (\in_array($capability, [ 'found_rows' ], true)) {
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

		// Whitelist of allowed charsets to prevent SQL injection.
		$allowedCharsets = [
			'armscii8', 'ascii', 'big5', 'binary', 'cp1250', 'cp1251', 'cp1256', 'cp1257',
			'cp850', 'cp852', 'cp866', 'cp932', 'dec8', 'eucjpms', 'euckr', 'gb18030',
			'gb2312', 'gbk', 'geostd8', 'greek', 'hebrew', 'hp8', 'keybcs2', 'koi8r',
			'koi8u', 'latin1', 'latin2', 'latin5', 'latin7', 'macce', 'macroman',
			'sjis', 'swe7', 'tis620', 'ucs2', 'ujis', 'utf16', 'utf16le', 'utf32',
			'utf8', 'utf8mb3', 'utf8mb4',
		];

		// Normalize charset name (lowercase, no spaces).
		$charset = \strtolower(\trim($charset));

		if (! \in_array($charset, $allowedCharsets, true)) {
			if (\defined('WP_DEBUG') && WP_DEBUG) {
				\error_log('WP-DBAL: Invalid charset "' . $charset . '" - not in allowed list.');
			}
			return false;
		}

		try {
			if (null !== $this->dbalConnection) {
				// Charset is now validated against whitelist, safe to use directly.
				$this->dbalConnection->executeStatement("SET NAMES '{$charset}'");
			}
			return true;
		} catch (\Exception $e) {
			if (\defined('WP_DEBUG') && WP_DEBUG) {
				\error_log('WP-DBAL: Failed to set charset: ' . $e->getMessage());
			}
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
			case 'filedb':
				return 'FileDB 1.0.0';
			case 'sqlite':
				return 'SQLite 3.0';
			case 'd1':
				// D1 is SQLite-based.
				return 'D1 1.0.0';
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

	/**
	 * Retrieve an entire SQL result set from the database.
	 *
	 * Executes a SQL query and returns the entire query result.
	 *
	 * @param string|null $query  SQL query.
	 * @param string      $output Optional. Any of ARRAY_A | ARRAY_N | OBJECT | OBJECT_K.
	 *                            Default OBJECT.
	 * @return array<int|string, object|array<string|int, mixed>>|null Database query results.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_results($query = null, $output = OBJECT)
	{
		if ($query) {
			if (! $this->query($query)) {
				return null;
			}
		}

		$results = [];

		if (OBJECT === $output) {
			// Return as array of row objects.
			return $this->last_result;
		}

		foreach ($this->last_result as $row) {
			if (OBJECT_K === $output) {
				// Return as associative array keyed by first column.
				$rowArray = (array) $row;
				$key      = \reset($rowArray);
				$results[ $key ] = $row;
			} elseif (ARRAY_A === $output) {
				// Return as array of associative arrays.
				$results[] = (array) $row;
			} elseif (ARRAY_N === $output) {
				// Return as array of numeric arrays.
				$results[] = \array_values((array) $row);
			}
		}

		return $results;
	}

	/**
	 * Retrieve one row from the database.
	 *
	 * Executes a SQL query and returns the row from the SQL result.
	 *
	 * @param string|null $query  SQL query.
	 * @param string      $output Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N.
	 *                            Default OBJECT.
	 * @param int         $y      Optional. Row to return. Indexed from 0. Default 0.
	 * @return object|array<string|int, mixed>|null Database query result in format specified by $output.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_row($query = null, $output = OBJECT, $y = 0)
	{
		if ($query) {
			if (! $this->query($query)) {
				return null;
			}
		}

		if (! isset($this->last_result[ $y ])) {
			return null;
		}

		$row = $this->last_result[ $y ];

		if (OBJECT === $output) {
			return $row;
		} elseif (ARRAY_A === $output) {
			return (array) $row;
		} elseif (ARRAY_N === $output) {
			return \array_values((array) $row);
		}

		return $row;
	}

	/**
	 * Retrieve one variable from the database.
	 *
	 * Executes a SQL query and returns the value from the SQL result.
	 * If the SQL result contains more than one column and/or more than one row,
	 * the value in the column and row specified is returned. If $query is null,
	 * the value in the specified column and row from the previous SQL result is returned.
	 *
	 * @param string|null $query Optional. SQL query. Defaults to null, use the result from previous query.
	 * @param int         $x     Optional. Column of value to return. Indexed from 0. Default 0.
	 * @param int         $y     Optional. Row of value to return. Indexed from 0. Default 0.
	 * @return string|null Database query result (as string), or null on failure.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_var($query = null, $x = 0, $y = 0)
	{
		if ($query) {
			if (! $this->query($query)) {
				return null;
			}
		}

		if (! isset($this->last_result[ $y ])) {
			return null;
		}

		$values = \array_values((array) $this->last_result[ $y ]);

		return isset($values[ $x ]) ? (string) $values[ $x ] : null;
	}

	/**
	 * Retrieve one column from the database.
	 *
	 * Executes a SQL query and returns the column from the SQL result.
	 * If the SQL result contains more than one column, the column specified is returned.
	 * If $query is null, the specified column from the previous SQL result is returned.
	 *
	 * @param string|null $query Optional. SQL query. Defaults to null, use the result from the previous query.
	 * @param int         $x     Optional. Column to return. Indexed from 0. Default 0.
	 * @return array<int, string|null> Database query result. Array indexed from 0 by SQL result row number.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_col($query = null, $x = 0): array
	{
		if ($query) {
			if (! $this->query($query)) {
				return [];
			}
		}

		$column = [];

		foreach ($this->last_result as $row) {
			$values   = \array_values((array) $row);
			$column[] = isset($values[ $x ]) ? (string) $values[ $x ] : null;
		}

		return $column;
	}

	/**
	 * Retrieve the character set for a given table and column.
	 *
	 * @return string The table CHARACTER SET and COLLATE clause.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function get_charset_collate(): string
	{
		$charset_collate = '';

		// For non-MySQL engines, charset/collate syntax doesn't apply.
		if (! \in_array($this->dbEngine, [ 'mysql' ], true)) {
			return $charset_collate;
		}

		if (! empty($this->charset)) {
			$charset_collate = "DEFAULT CHARACTER SET {$this->charset}";
		}

		if (! empty($this->collate)) {
			$charset_collate .= " COLLATE {$this->collate}";
		}

		return $charset_collate;
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * Uses sprintf()-like syntax. Accepts a format string and a variable number
	 * of arguments. The format specifiers supported are:
	 * - %d (integer)
	 * - %f (float)
	 * - %s (string)
	 * - %i (identifier, e.g. table/field names)
	 *
	 * @param string      $query   Query statement with sprintf()-like placeholders.
	 * @param mixed       ...$args Arguments to substitute into the query.
	 * @return string|void Sanitized query string, or void if no arguments.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function prepare($query, ...$args)
	{
		if (empty($args)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This matches wpdb behavior
			\_doing_it_wrong(
				'wpdb::prepare',
				'wpdb::prepare() requires at least two arguments.',
				'6.2.0'
			);
			return;
		}

		// Handle array argument (for backwards compatibility).
		if (\is_array($args[0]) && 1 === \count($args)) {
			$args = $args[0];
		}

		$argIndex = 0;

		// Replace format placeholders with escaped values.
		$query = \preg_replace_callback(
			'/%([%dfsi])/',
			function ($matches) use (&$args, &$argIndex) {
				$type = $matches[1];

				// %% is a literal %.
				if ('%' === $type) {
					return '%';
				}

				if (! isset($args[ $argIndex ])) {
					return $matches[0];
				}

				$value = $args[ $argIndex ];
				$argIndex++;

				switch ($type) {
					case 'd':
						return (int) $value;

					case 'f':
						return (float) $value;

					case 's':
						return "'" . $this->_real_escape((string) $value) . "'";

					case 'i':
						// Identifier (table/column name) - backtick escape.
						$identifier = \str_replace('`', '``', (string) $value);
						return "`{$identifier}`";

					default:
						return $matches[0];
				}
			},
			$query
		);

		return $query;
	}

	/**
	 * Insert a row into a table.
	 *
	 * @param string                      $table  The table name.
	 * @param array<string, mixed>        $data   Data to insert (column => value pairs).
	 * @param array<string>|string|null   $format Optional. An array of formats to be mapped to each value in $data.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function insert($table, $data, $format = null)
	{
		return $this->_insert_replace_helper($table, $data, $format, 'INSERT');
	}

	/**
	 * Replace a row in a table.
	 *
	 * @param string                      $table  The table name.
	 * @param array<string, mixed>        $data   Data to insert (column => value pairs).
	 * @param array<string>|string|null   $format Optional. An array of formats to be mapped to each value in $data.
	 * @return int|false The number of rows affected, or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function replace($table, $data, $format = null)
	{
		return $this->_insert_replace_helper($table, $data, $format, 'REPLACE');
	}

	/**
	 * Helper function for insert and replace.
	 *
	 * @param string                      $table  The table name.
	 * @param array<string, mixed>        $data   Data to insert (column => value pairs).
	 * @param array<string>|string|null   $format Optional. An array of formats.
	 * @param string                      $type   Type of operation (INSERT or REPLACE).
	 * @return int|false The number of rows inserted, or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps, PSR2.Methods.MethodDeclaration.Underscore, Squiz.NamingConventions.ValidFunctionName.PublicUnderscore -- wpdb helper
	protected function _insert_replace_helper($table, $data, $format, $type)
	{
		if (empty($data)) {
			return false;
		}

		$columns = [];
		$values  = [];
		$formats = $this->normalizeFormats($format, \count($data));

		$i = 0;
		foreach ($data as $column => $value) {
			$columns[] = "`" . \str_replace('`', '``', $column) . "`";
			$values[]  = $this->formatValue($value, $formats[ $i ] ?? '%s');
			$i++;
		}

		$sql = \sprintf(
			'%s INTO `%s` (%s) VALUES (%s)',
			$type,
			\str_replace('`', '``', $table),
			\implode(', ', $columns),
			\implode(', ', $values)
		);

		$result = $this->query($sql);

		if (false === $result) {
			return false;
		}

		return $this->rows_affected;
	}

	/**
	 * Update a row in the table.
	 *
	 * @param string                      $table        The table name.
	 * @param array<string, mixed>        $data         Data to update (column => value pairs).
	 * @param array<string, mixed>        $where        WHERE clause (column => value pairs).
	 * @param array<string>|string|null   $format       Optional. An array of formats for $data values.
	 * @param array<string>|string|null   $where_format Optional. An array of formats for $where values.
	 * @return int|false The number of rows updated, or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function update($table, $data, $where, $format = null, $where_format = null)
	{
		if (empty($data) || empty($where)) {
			return false;
		}

		$setClauses   = [];
		$whereClauses = [];

		$formats      = $this->normalizeFormats($format, \count($data));
		$whereFormats = $this->normalizeFormats($where_format, \count($where));

		$i = 0;
		foreach ($data as $column => $value) {
			$quotedColumn = "`" . \str_replace('`', '``', $column) . "`";
			$formattedValue = $this->formatValue($value, $formats[ $i ] ?? '%s');
			$setClauses[] = "{$quotedColumn} = {$formattedValue}";
			$i++;
		}

		$i = 0;
		foreach ($where as $column => $value) {
			$quotedColumn = "`" . \str_replace('`', '``', $column) . "`";
			$formattedValue = $this->formatValue($value, $whereFormats[ $i ] ?? '%s');
			$whereClauses[] = "{$quotedColumn} = {$formattedValue}";
			$i++;
		}

		$sql = \sprintf(
			'UPDATE `%s` SET %s WHERE %s',
			\str_replace('`', '``', $table),
			\implode(', ', $setClauses),
			\implode(' AND ', $whereClauses)
		);

		$result = $this->query($sql);

		if (false === $result) {
			return false;
		}

		return $this->rows_affected;
	}

	/**
	 * Delete a row from the table.
	 *
	 * @param string                      $table        The table name.
	 * @param array<string, mixed>        $where        WHERE clause (column => value pairs).
	 * @param array<string>|string|null   $where_format Optional. An array of formats for $where values.
	 * @return int|false The number of rows deleted, or false on error.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- wpdb override
	public function delete($table, $where, $where_format = null)
	{
		if (empty($where)) {
			return false;
		}

		$whereClauses = [];
		$whereFormats = $this->normalizeFormats($where_format, \count($where));

		$i = 0;
		foreach ($where as $column => $value) {
			$quotedColumn = "`" . \str_replace('`', '``', $column) . "`";
			$formattedValue = $this->formatValue($value, $whereFormats[ $i ] ?? '%s');
			$whereClauses[] = "{$quotedColumn} = {$formattedValue}";
			$i++;
		}

		$sql = \sprintf(
			'DELETE FROM `%s` WHERE %s',
			\str_replace('`', '``', $table),
			\implode(' AND ', $whereClauses)
		);

		$result = $this->query($sql);

		if (false === $result) {
			return false;
		}

		return $this->rows_affected;
	}

	/**
	 * Normalize format specification to array.
	 *
	 * @param array<string>|string|null $format The format specification.
	 * @param int                        $count  Expected number of format items.
	 * @return array<string> Normalized array of format strings.
	 */
	protected function normalizeFormats($format, int $count): array
	{
		if (null === $format) {
			return \array_fill(0, $count, '%s');
		}

		if (\is_string($format)) {
			return \array_fill(0, $count, $format);
		}

		return $format;
	}

	/**
	 * Format a value according to its format specifier.
	 *
	 * @param mixed  $value  The value to format.
	 * @param string $format The format specifier (%d, %f, %s).
	 * @return string The formatted value for SQL.
	 */
	protected function formatValue($value, string $format): string
	{
		if (null === $value) {
			return 'NULL';
		}

		switch ($format) {
			case '%d':
				return (string) (int) $value;

			case '%f':
				return (string) (float) $value;

			case '%s':
			default:
				return "'" . $this->_real_escape((string) $value) . "'";
		}
	}
}
