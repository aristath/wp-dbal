<?php

/**
 * D1 Driver - Doctrine DBAL Driver for Cloudflare D1.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\D1;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use SensitiveParameter;

/**
 * D1 Driver implementation.
 *
 * This driver enables WordPress to use Cloudflare D1 as its database.
 * D1 is a serverless SQLite database, so we extend AbstractSQLiteDriver
 * to inherit SQLite platform support and exception handling.
 *
 * Connection is made via Cloudflare's REST API, making this driver
 * compatible with both standard PHP environments and WordPress Playground.
 */
class Driver extends AbstractSQLiteDriver
{
	/**
	 * Connect to the D1 database.
	 *
	 * @param array<string, mixed> $params Connection parameters.
	 *                                     Required:
	 *                                     - account_id: Cloudflare account ID
	 *                                     - database_id: D1 database ID
	 *                                     - api_token: Cloudflare API token
	 * @return DriverConnection The connection instance.
	 */
	public function connect(
		#[SensitiveParameter]
		array $params,
	): DriverConnection {
		return new Connection($params);
	}
}
