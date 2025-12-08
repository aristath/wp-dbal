<?php

/**
 * D1 HTTP Client - HTTP transport for Cloudflare D1 API.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\D1;

use RuntimeException;

/**
 * HTTP Client for communicating with Cloudflare D1 REST API.
 *
 * This client is designed to work in both standard PHP environments
 * and WordPress Playground (WebAssembly). It uses wp_safe_remote_post()
 * when available, which Playground translates to JavaScript fetch().
 */
class HttpClient
{
	/**
	 * Cloudflare account ID.
	 *
	 * @var string
	 */
	protected string $accountId;

	/**
	 * D1 database ID.
	 *
	 * @var string
	 */
	protected string $databaseId;

	/**
	 * Cloudflare API token.
	 *
	 * @var string
	 */
	protected string $apiToken;

	/**
	 * Base URL for the Cloudflare API.
	 *
	 * @var string
	 */
	protected string $baseUrl = 'https://api.cloudflare.com/client/v4';

	/**
	 * Last insert ID from the most recent query.
	 *
	 * @var integer|string
	 */
	protected int|string $lastInsertId = 0;

	/**
	 * Number of rows affected by the last query.
	 *
	 * @var integer
	 */
	protected int $rowsAffected = 0;

	/**
	 * Constructor.
	 *
	 * @param string $accountId  Cloudflare account ID.
	 * @param string $databaseId D1 database ID.
	 * @param string $apiToken   Cloudflare API token.
	 */
	public function __construct(string $accountId, string $databaseId, string $apiToken)
	{
		$this->accountId  = $accountId;
		$this->databaseId = $databaseId;
		$this->apiToken   = $apiToken;
	}

	/**
	 * Execute a SQL query against the D1 database.
	 *
	 * @param string       $sql    The SQL query to execute.
	 * @param array<mixed> $params Parameters for prepared statement.
	 * @return array{
	 *     success: bool,
	 *     results: array<int, array<string, mixed>>,
	 *     meta: array{
	 *         duration: float,
	 *         changes: int,
	 *         last_row_id: int,
	 *         served_by: string,
	 *         rows_read: int,
	 *         rows_written: int
	 *     }
	 * } The query result.
	 * @throws RuntimeException If the query fails.
	 */
	public function query(string $sql, array $params = []): array
	{
		$url = $this->buildQueryUrl();

		$body = ['sql' => $sql];
		if (! empty($params)) {
			$body['params'] = $this->normalizeParams($params);
		}

		$response = $this->makeRequest($url, $body);

		// D1 returns an array of results (one per statement).
		// For single queries, we return the first result.
		if (isset($response['result'][0])) {
			$result = $response['result'][0];

			// Track metadata for lastInsertId() and rowCount().
			if (isset($result['meta'])) {
				$this->lastInsertId = $result['meta']['last_row_id'] ?? 0;
				$this->rowsAffected = $result['meta']['changes'] ?? 0;
			}

			return $result;
		}

		// Fallback for unexpected response format.
		return [
			'success' => $response['success'] ?? false,
			'results' => [],
			'meta'    => [
				'duration'     => 0,
				'changes'      => 0,
				'last_row_id'  => 0,
				'served_by'    => '',
				'rows_read'    => 0,
				'rows_written' => 0,
			],
		];
	}

	/**
	 * Execute multiple SQL statements in a batch.
	 *
	 * @param array<array{sql: string, params?: array<mixed>}> $statements Array of statements.
	 * @return array<array{success: bool, results: array<mixed>, meta: array<string, mixed>}> Results for each statement.
	 * @throws RuntimeException If the batch request fails.
	 */
	public function batch(array $statements): array
	{
		// D1 REST API doesn't have a dedicated batch endpoint.
		// We concatenate statements with semicolons.
		$combinedSql = '';
		$allParams   = [];

		foreach ($statements as $stmt) {
			$combinedSql .= $stmt['sql'] . '; ';
			if (isset($stmt['params'])) {
				$allParams = array_merge($allParams, $stmt['params']);
			}
		}

		$url  = $this->buildQueryUrl();
		$body = ['sql' => trim($combinedSql)];
		if (! empty($allParams)) {
			$body['params'] = $this->normalizeParams($allParams);
		}

		$response = $this->makeRequest($url, $body);

		return $response['result'] ?? [];
	}

	/**
	 * Get the last insert ID.
	 *
	 * @return int|string
	 */
	public function getLastInsertId(): int|string
	{
		return $this->lastInsertId;
	}

	/**
	 * Get the number of affected rows from the last query.
	 *
	 * @return int
	 */
	public function getRowsAffected(): int
	{
		return $this->rowsAffected;
	}

	/**
	 * Build the D1 query endpoint URL.
	 *
	 * @return string
	 */
	protected function buildQueryUrl(): string
	{
		return sprintf(
			'%s/accounts/%s/d1/database/%s/query',
			$this->baseUrl,
			$this->accountId,
			$this->databaseId
		);
	}

	/**
	 * Normalize parameters for the D1 API.
	 *
	 * D1 expects parameters as a flat array for positional params
	 * or as an object for named params.
	 *
	 * @param array<mixed> $params The parameters to normalize.
	 * @return array<mixed>
	 */
	protected function normalizeParams(array $params): array
	{
		// Check if we have named parameters (associative array).
		$isNamed = array_keys($params) !== range(0, count($params) - 1);

		if ($isNamed) {
			// Named params - return as-is for D1's object format.
			return $params;
		}

		// Positional params - ensure values are properly typed.
		return array_map(function ($value) {
			if (null === $value) {
				return null;
			}
			if (is_bool($value)) {
				return $value ? 1 : 0;
			}
			return $value;
		}, array_values($params));
	}

	/**
	 * Make an HTTP request to the D1 API.
	 *
	 * @param string              $url  The URL to request.
	 * @param array<string,mixed> $body The request body.
	 * @return array<string,mixed> The JSON response.
	 * @throws RuntimeException If the request fails.
	 */
	protected function makeRequest(string $url, array $body): array
	{
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		// Try WordPress HTTP API first (works in Playground).
		if (function_exists('wp_safe_remote_post')) {
			return $this->makeWordPressRequest($url, $jsonBody);
		}

		// Fall back to cURL for early bootstrap.
		return $this->makeCurlRequest($url, $jsonBody);
	}

	/**
	 * Make a request using WordPress HTTP API.
	 *
	 * @param string $url      The URL to request.
	 * @param string $jsonBody The JSON-encoded request body.
	 * @return array<string,mixed> The JSON response.
	 * @throws RuntimeException If the request fails.
	 */
	protected function makeWordPressRequest(string $url, string $jsonBody): array
	{
		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiToken,
					'Content-Type'  => 'application/json',
				],
				'body'    => $jsonBody,
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			throw new RuntimeException(
				'D1 API request failed: ' . $response->get_error_message()
			);
		}

		$statusCode = wp_remote_retrieve_response_code($response);
		$body       = wp_remote_retrieve_body($response);

		return $this->parseResponse($statusCode, $body);
	}

	/**
	 * Make a request using cURL.
	 *
	 * @param string $url      The URL to request.
	 * @param string $jsonBody The JSON-encoded request body.
	 * @return array<string,mixed> The JSON response.
	 * @throws RuntimeException If the request fails.
	 */
	protected function makeCurlRequest(string $url, string $jsonBody): array
	{
		if (! function_exists('curl_init')) {
			throw new RuntimeException(
				'Neither WordPress HTTP API nor cURL is available for D1 requests.'
			);
		}

		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $jsonBody,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $this->apiToken,
				'Content-Type: application/json',
			],
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
		]);

		$body       = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error      = curl_error($ch);

		curl_close($ch);

		if (false === $body) {
			throw new RuntimeException('D1 API request failed: ' . $error);
		}

		return $this->parseResponse($statusCode, $body);
	}

	/**
	 * Parse the API response.
	 *
	 * @param int    $statusCode The HTTP status code.
	 * @param string $body       The response body.
	 * @return array<string,mixed> The parsed JSON response.
	 * @throws RuntimeException If the response indicates an error.
	 */
	protected function parseResponse(int $statusCode, string $body): array
	{
		$data = json_decode($body, true);

		if (JSON_ERROR_NONE !== json_last_error()) {
			throw new RuntimeException(
				'D1 API returned invalid JSON: ' . json_last_error_msg()
			);
		}

		// Handle rate limiting.
		if (429 === $statusCode) {
			throw new RuntimeException(
				'D1 API rate limit exceeded. Please retry later.'
			);
		}

		// Handle other HTTP errors.
		if ($statusCode >= 400) {
			$errorMessage = 'D1 API error';
			if (isset($data['errors'][0]['message'])) {
				$errorMessage = $data['errors'][0]['message'];
			}
			throw new RuntimeException(
				sprintf('D1 API error (%d): %s', $statusCode, $errorMessage)
			);
		}

		// Check for API-level errors.
		if (isset($data['success']) && false === $data['success']) {
			$errorMessage = 'Unknown D1 error';
			if (isset($data['errors'][0]['message'])) {
				$errorMessage = $data['errors'][0]['message'];
			}
			throw new RuntimeException('D1 query failed: ' . $errorMessage);
		}

		return $data;
	}
}
