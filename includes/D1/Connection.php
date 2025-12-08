<?php

/**
 * D1 Connection - Doctrine DBAL Connection for Cloudflare D1.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\D1;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use RuntimeException;

/**
 * D1 Connection implementation.
 *
 * Manages the connection to Cloudflare D1 via the REST API
 * and routes queries through the HTTP client.
 */
class Connection implements ConnectionInterface
{
	/**
	 * HTTP client for D1 API communication.
	 *
	 * @var HttpClient
	 */
	protected HttpClient $httpClient;

	/**
	 * Connection parameters.
	 *
	 * @var array<string, mixed>
	 */
	protected array $params;

	/**
	 * Transaction nesting level.
	 *
	 * Note: D1 REST API does not support transactions.
	 * This is tracked for compatibility but has no effect.
	 *
	 * @var integer
	 */
	protected int $transactionLevel = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params Connection parameters.
	 *                                     Required: account_id, database_id, api_token.
	 * @throws RuntimeException If required parameters are missing.
	 */
	public function __construct(array $params)
	{
		$this->params = $params;
		$this->validateParams();

		$this->httpClient = new HttpClient(
			$params['account_id'],
			$params['database_id'],
			$params['api_token']
		);
	}

	/**
	 * Validate required connection parameters.
	 *
	 * @return void
	 * @throws RuntimeException If required parameters are missing.
	 */
	protected function validateParams(): void
	{
		$required = ['account_id', 'database_id', 'api_token'];

		foreach ($required as $param) {
			if (empty($this->params[$param])) {
				throw new RuntimeException(
					sprintf('D1 connection requires "%s" parameter.', $param)
				);
			}
		}
	}

	/**
	 * Prepare a statement for execution.
	 *
	 * @param string $sql The SQL query.
	 * @return StatementInterface The prepared statement.
	 */
	public function prepare(string $sql): StatementInterface
	{
		return new Statement($sql, $this);
	}

	/**
	 * Execute a query directly and return results.
	 *
	 * @param string $sql The SQL query.
	 * @return ResultInterface The query result.
	 */
	public function query(string $sql): ResultInterface
	{
		$response = $this->httpClient->query($sql);

		return new Result($response);
	}

	/**
	 * Quote a string for use in a query.
	 *
	 * SQLite uses single quotes for string literals.
	 *
	 * @param string $value The value to quote.
	 * @return string The quoted value.
	 */
	public function quote(string $value): string
	{
		// SQLite escaping: double single quotes.
		return "'" . str_replace("'", "''", $value) . "'";
	}

	/**
	 * Execute a statement and return the number of affected rows.
	 *
	 * @param string $sql The SQL statement.
	 * @return int The number of affected rows.
	 */
	public function exec(string $sql): int
	{
		$this->httpClient->query($sql);

		return $this->httpClient->getRowsAffected();
	}

	/**
	 * Get the ID of the last inserted row.
	 *
	 * @return int|string The last insert ID.
	 */
	public function lastInsertId(): int|string
	{
		return $this->httpClient->getLastInsertId();
	}

	/**
	 * Begin a transaction.
	 *
	 * Note: D1 REST API does not support explicit transactions.
	 * Each query is auto-committed. This method is a no-op that
	 * tracks nesting level for compatibility.
	 *
	 * @return void
	 */
	public function beginTransaction(): void
	{
		++$this->transactionLevel;

		// Log a warning in debug mode.
		if (1 === $this->transactionLevel && defined('WP_DEBUG') && WP_DEBUG) {
			error_log(
				'WP-DBAL D1: beginTransaction() called but D1 REST API does not support transactions. ' .
				'Each query is auto-committed.'
			);
		}
	}

	/**
	 * Commit a transaction.
	 *
	 * Note: D1 REST API does not support explicit transactions.
	 * This method is a no-op.
	 *
	 * @return void
	 */
	public function commit(): void
	{
		if ($this->transactionLevel > 0) {
			--$this->transactionLevel;
		}
	}

	/**
	 * Roll back a transaction.
	 *
	 * Note: D1 REST API does not support transactions or rollback.
	 * This method is a no-op that logs a warning.
	 *
	 * @return void
	 */
	public function rollBack(): void
	{
		if ($this->transactionLevel > 0) {
			--$this->transactionLevel;
		}

		// Log a warning since rollback cannot actually undo changes.
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(
				'WP-DBAL D1: rollBack() called but D1 REST API does not support transactions. ' .
				'Changes cannot be rolled back.'
			);
		}
	}

	/**
	 * Get the server version.
	 *
	 * Returns a SQLite-compatible version string since D1 is SQLite-based.
	 *
	 * @return string The server version string.
	 */
	public function getServerVersion(): string
	{
		// D1 uses SQLite under the hood.
		// Return a version that satisfies SQLite compatibility checks.
		return '3.45.0-D1';
	}

	/**
	 * Get the native connection.
	 *
	 * Returns the HTTP client as the "native" connection since D1
	 * uses HTTP rather than a traditional database connection.
	 *
	 * @return HttpClient The HTTP client.
	 */
	public function getNativeConnection(): HttpClient
	{
		return $this->httpClient;
	}

	/**
	 * Get the HTTP client.
	 *
	 * @return HttpClient The HTTP client instance.
	 */
	public function getHttpClient(): HttpClient
	{
		return $this->httpClient;
	}
}
