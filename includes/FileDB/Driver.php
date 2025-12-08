<?php

/**
 * FileDB Driver - Doctrine DBAL Driver for file-based storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use SensitiveParameter;
use WP_DBAL\FileDB\Platform\FileDBPlatform;

/**
 * FileDB Driver implementation.
 *
 * This driver stores WordPress data in files instead of a traditional database.
 * It parses SQL queries and executes them against file-based storage.
 */
class Driver implements DriverInterface
{
	/**
	 * Connect to the file-based database.
	 *
	 * @param array<string, mixed> $params Connection parameters.
	 * @return DriverConnection The connection instance.
	 */
	public function connect(
		#[SensitiveParameter]
		array $params,
	): DriverConnection {
		return new Connection($params);
	}

	/**
	 * Get the database platform for FileDB.
	 *
	 * Returns a MySQL-compatible platform since WordPress expects MySQL syntax.
	 *
	 * @param ServerVersionProvider $versionProvider Version provider.
	 * @return AbstractPlatform The platform instance.
	 */
	public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
	{
		return new FileDBPlatform();
	}

	/**
	 * Get the exception converter.
	 *
	 * @return ExceptionConverterInterface The exception converter.
	 */
	public function getExceptionConverter(): ExceptionConverterInterface
	{
		return new ExceptionConverter();
	}
}
