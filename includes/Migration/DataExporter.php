<?php

/**
 * Data Exporter
 *
 * Exports data from source database in batches.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/**
 * Data Exporter class.
 */
class DataExporter
{
	/**
	 * Export a chunk of data from a table.
	 *
	 * @param Connection $connection Source database connection.
	 * @param string $table Table name.
	 * @param int $offset Offset for pagination.
	 * @param int $limit Number of rows to fetch.
	 * @return array<int, array<string, mixed>> Array of rows.
	 */
	public function exportTableChunk(Connection $connection, string $table, int $offset, int $limit): array
	{
		$queryBuilder = $connection->createQueryBuilder();
		$queryBuilder
			->select('*')
			->from($connection->quoteIdentifier($table))
			->setFirstResult($offset)
			->setMaxResults($limit);

		$result = $queryBuilder->executeQuery();
		$rows = [];

		while ($row = $result->fetchAssociative()) {
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Get total row count for a table.
	 *
	 * @param Connection $connection Source database connection.
	 * @param string $table Table name.
	 * @return int Total row count.
	 */
	public function getTableRowCount(Connection $connection, string $table): int
	{
		$queryBuilder = $connection->createQueryBuilder();
		$queryBuilder
			->select('COUNT(*)')
			->from($connection->quoteIdentifier($table));

		$result = $queryBuilder->executeQuery();
		return (int) $result->fetchOne();
	}
}

