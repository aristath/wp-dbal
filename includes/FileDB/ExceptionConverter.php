<?php

/**
 * FileDB Exception Converter - Converts driver exceptions to DBAL exceptions.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Query;

/**
 * FileDB Exception Converter implementation.
 *
 * Converts FileDB-specific exceptions to appropriate DBAL exception types.
 */
class ExceptionConverter implements ExceptionConverterInterface
{
	/**
	 * Convert a driver exception to a DBAL exception.
	 *
	 * @param Exception  $exception The driver exception.
	 * @param Query|null $query     The query that caused the exception.
	 * @return DriverException The converted exception.
	 */
	public function convert(Exception $exception, ?Query $query): DriverException
	{
		$message = $exception->getMessage();

		// Check for specific error patterns.
		if (\str_contains($message, 'Table not found') || \str_contains($message, 'does not exist')) {
			return new TableNotFoundException($exception, $query);
		}

		if (\str_contains($message, 'Table already exists')) {
			return new TableExistsException($exception, $query);
		}

		if (\str_contains($message, 'Syntax error') || \str_contains($message, 'Parse error')) {
			return new SyntaxErrorException($exception, $query);
		}

		if (\str_contains($message, 'Connection') || \str_contains($message, 'Storage path')) {
			return new ConnectionException($exception, $query);
		}

		// Invalid argument errors fall through to generic DriverException.

		// Default to generic driver exception.
		return new DriverException($exception, $query);
	}
}
