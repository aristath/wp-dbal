<?php

/**
 * FileDB Platform - MySQL-compatible database platform for file-based storage.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\Platform;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\MySQLKeywords;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\SQL\Builder\WithSQLBuilder;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\TextType;

/**
 * FileDB Platform implementation.
 *
 * Extends MySQL platform to maintain full compatibility with WordPress SQL queries.
 * The platform defines SQL dialect but doesn't affect how data is actually stored.
 */
class FileDBPlatform extends AbstractMySQLPlatform
{
	/**
	 * Get the platform name.
	 *
	 * @return string The platform name.
	 */
	public function getName(): string
	{
		return 'filedb';
	}

	/**
	 * Get default value declaration SQL.
	 *
	 * @param array<string, mixed> $column Column definition.
	 * @return string The SQL declaration.
	 */
	public function getDefaultValueDeclarationSQL(array $column): string
	{
		// Handle TEXT/BLOB columns like MySQL 5.x.
		if (isset($column['type']) && ($column['type'] instanceof TextType || $column['type'] instanceof BlobType)) {
			unset($column['default']);
		}

		return parent::getDefaultValueDeclarationSQL($column);
	}

	/**
	 * Create a WITH SQL builder.
	 *
	 * @return WithSQLBuilder The builder.
	 * @throws NotSupported WITH is not supported.
	 */
	public function createWithSQLBuilder(): WithSQLBuilder
	{
		throw NotSupported::new(__METHOD__);
	}

	/**
	 * Get the SQL to rename an index.
	 *
	 * @param string $oldIndexName The old index name.
	 * @param Index  $index        The new index.
	 * @param string $tableName    The table name.
	 * @return list<string> The SQL statements.
	 */
	protected function getRenameIndexSQL(string $oldIndexName, Index $index, string $tableName): array
	{
		return [
			'ALTER TABLE ' . $tableName . ' RENAME INDEX ' . $oldIndexName . ' TO ' . $index->getQuotedName($this),
		];
	}

	/**
	 * Create the reserved keywords list.
	 *
	 * @return KeywordList The keyword list.
	 * @deprecated
	 */
	protected function createReservedKeywordsList(): KeywordList
	{
		return new MySQLKeywords();
	}
}
