<?php

/**
 * Query Converter - Converts parsed MySQL AST to DBAL QueryBuilder.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\Translator;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\ReplaceStatement;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\SetOperation;

/**
 * Converts MySQL queries to DBAL QueryBuilder for cross-platform execution.
 */
class QueryConverter
{
	/**
	 * DBAL Connection.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Function mapper for cross-platform function translation.
	 *
	 * @var FunctionMapper
	 */
	protected FunctionMapper $functionMapper;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection DBAL connection.
	 */
	public function __construct(Connection $connection)
	{
		$this->connection     = $connection;
		$this->functionMapper = new FunctionMapper($connection);
	}

	/**
	 * Convert a MySQL query to platform-appropriate SQL.
	 *
	 * @param string $query The MySQL query.
	 * @return string|array The converted query or array of queries.
	 */
	public function convert(string $query): string|array
	{
		// Parse the MySQL query.
		$parser = new Parser($query);

		if (empty($parser->statements)) {
			// Parsing failed, return original query.
			return $query;
		}

		$statement = $parser->statements[0];

		// Convert based on statement type.
		return match (true) {
			$statement instanceof SelectStatement  => $this->convertSelect($statement),
			$statement instanceof InsertStatement  => $this->convertInsert($statement),
			$statement instanceof UpdateStatement  => $this->convertUpdate($statement),
			$statement instanceof DeleteStatement  => $this->convertDelete($statement),
			$statement instanceof ReplaceStatement => $this->convertReplace($statement),
			default                                => $query, // Pass through unsupported statements.
		};
	}

	/**
	 * Convert SELECT statement to DBAL QueryBuilder.
	 *
	 * @param SelectStatement $stmt The parsed SELECT statement.
	 * @return string The converted SQL.
	 */
	protected function convertSelect(SelectStatement $stmt): string
	{
		$qb = $this->connection->createQueryBuilder();

		// Handle SELECT DISTINCT.
		if (! empty($stmt->options) && $stmt->options->has('DISTINCT')) {
			$qb->distinct();
		}

		// SELECT columns.
		$columns = [];
		if (! empty($stmt->expr)) {
			foreach ($stmt->expr as $expr) {
				$columns[] = $this->convertExpression($expr);
			}
		}
		$qb->select(...$columns);

		// FROM clause - track the primary table alias for JOINs.
		$fromAlias = null;
		if (! empty($stmt->from)) {
			foreach ($stmt->from as $i => $from) {
				$table = $this->getTableReference($from);
				if (0 === $i) {
					// Use alias if provided, otherwise use table name as alias.
					$fromAlias = $table['alias'] ?? $table['table'];
					$qb->from($table['table'], $fromAlias);
				}
			}
		}

		// JOIN clauses.
		// DBAL signature: innerJoin($fromAlias, $joinTable, $joinAlias, $condition)
		// $fromAlias must be an alias already registered (from FROM or previous JOIN).
		if (! empty($stmt->join) && null !== $fromAlias) {
			foreach ($stmt->join as $join) {
				$joinTable = $this->getTableReference($join->expr);
				$joinType  = \strtoupper($join->type);
				$joinOn    = '';

				// Use alias if provided, otherwise use table name as alias.
				$joinAlias = $joinTable['alias'] ?? $joinTable['table'];

				if (! empty($join->on)) {
					$conditions = [];
					foreach ($join->on as $cond) {
						$conditions[] = $cond->expr;
					}
					$joinOn = \implode(' AND ', $conditions);
				}

				// First argument is the FROM alias (the table we're joining FROM).
				match ($joinType) {
					'LEFT', 'LEFT OUTER'   => $qb->leftJoin($fromAlias, $joinTable['table'], $joinAlias, $joinOn),
					'RIGHT', 'RIGHT OUTER' => $qb->rightJoin($fromAlias, $joinTable['table'], $joinAlias, $joinOn),
					default                => $qb->innerJoin($fromAlias, $joinTable['table'], $joinAlias, $joinOn),
				};
			}
		}

		// WHERE clause.
		if (! empty($stmt->where)) {
			$whereParts = [];
			foreach ($stmt->where as $cond) {
				if ($cond->isOperator) {
					$whereParts[] = $cond->expr;
				} else {
					$whereParts[] = $this->convertConditionExpression($cond->expr);
				}
			}
			$qb->where(\implode(' ', $whereParts));
		}

		// GROUP BY clause.
		if (! empty($stmt->group)) {
			foreach ($stmt->group as $group) {
				$qb->addGroupBy($group->expr->expr);
			}
		}

		// HAVING clause.
		if (! empty($stmt->having)) {
			$havingParts = [];
			foreach ($stmt->having as $cond) {
				if ($cond->isOperator) {
					$havingParts[] = $cond->expr;
				} else {
					$havingParts[] = $this->convertConditionExpression($cond->expr);
				}
			}
			$qb->having(\implode(' ', $havingParts));
		}

		// ORDER BY clause.
		if (! empty($stmt->order)) {
			foreach ($stmt->order as $order) {
				$qb->addOrderBy($order->expr->expr, $order->type);
			}
		}

		// LIMIT clause.
		if (null !== $stmt->limit) {
			$qb->setMaxResults((int) $stmt->limit->rowCount);
			if ($stmt->limit->offset > 0) {
				$qb->setFirstResult((int) $stmt->limit->offset);
			}
		}

		return $qb->getSQL();
	}

	/**
	 * Convert INSERT statement.
	 *
	 * @param InsertStatement $stmt The parsed INSERT statement.
	 * @return string The converted SQL.
	 */
	protected function convertInsert(InsertStatement $stmt): string
	{
		$table = $stmt->into->dest->table ?? '';

		// Get columns.
		$columns = [];
		if (! empty($stmt->into->columns)) {
			foreach ($stmt->into->columns as $col) {
				$columns[] = $col;
			}
		}

		// Handle INSERT ... SELECT syntax - pass through the original query.
		// Action Scheduler uses this pattern: INSERT INTO ... SELECT ... FROM DUAL WHERE ...
		if (isset($stmt->select)) {
			// Build the query from the statement.
			return $stmt->build();
		}

		// Get values - use raw to preserve quotes.
		$allValues = [];
		if (! empty($stmt->values)) {
			foreach ($stmt->values as $valueSet) {
				$values = [];
				// Use raw values to preserve string quotes.
				// ArrayObj has 'raw' property, Array2d elements are also ArrayObj.
				/** @var \PhpMyAdmin\SqlParser\Components\ArrayObj $valueSet */
				$rawValues = $valueSet->raw;
				foreach ($rawValues as $val) {
					$values[] = $this->convertValueExpression($val);
				}
				$allValues[] = $values;
			}
		}

		// Build INSERT statement.
		$platform     = $this->connection->getDatabasePlatform();
		$quotedTable = $platform->quoteIdentifier($table);

		$quotedColumns = \array_map(
			fn($col) => $platform->quoteIdentifier($col),
			$columns
		);

		$sqlParts = [];
		foreach ($allValues as $values) {
			$sqlParts[] = '(' . \implode(', ', $values) . ')';
		}

		// Handle INSERT IGNORE for SQLite.
		$insertKeyword = 'INSERT';
		if (! empty($stmt->options) && $stmt->options->has('IGNORE')) {
			$platformName = $this->getPlatformName();
			if ('sqlite' === $platformName) {
				$insertKeyword = 'INSERT OR IGNORE';
			} else {
				$insertKeyword = 'INSERT IGNORE';
			}
		}

		return \sprintf(
			'%s INTO %s (%s) VALUES %s',
			$insertKeyword,
			$quotedTable,
			\implode(', ', $quotedColumns),
			\implode(', ', $sqlParts)
		);
	}

	/**
	 * Convert UPDATE statement.
	 *
	 * @param UpdateStatement $stmt The parsed UPDATE statement.
	 * @return string The converted SQL.
	 */
	protected function convertUpdate(UpdateStatement $stmt): string
	{
		$qb = $this->connection->createQueryBuilder();

		// Table (DBAL 4.x doesn't support alias in update).
		if (! empty($stmt->tables)) {
			$table = $stmt->tables[0];
			$qb->update($table->table);
		}

		// SET clause.
		if (! empty($stmt->set)) {
			foreach ($stmt->set as $set) {
				$qb->set($set->column, $this->convertValueExpression($set->value));
			}
		}

		// WHERE clause.
		if (! empty($stmt->where)) {
			$whereParts = [];
			foreach ($stmt->where as $cond) {
				if ($cond->isOperator) {
					$whereParts[] = $cond->expr;
				} else {
					$whereParts[] = $this->convertConditionExpression($cond->expr);
				}
			}
			$qb->where(\implode(' ', $whereParts));
		}

		// ORDER BY clause.
		if (! empty($stmt->order)) {
			foreach ($stmt->order as $order) {
				$qb->addOrderBy($order->expr->expr, $order->type);
			}
		}

		// LIMIT clause.
		if (null !== $stmt->limit) {
			$qb->setMaxResults((int) $stmt->limit->rowCount);
		}

		return $qb->getSQL();
	}

	/**
	 * Convert DELETE statement.
	 *
	 * @param DeleteStatement $stmt The parsed DELETE statement.
	 * @return string The converted SQL.
	 */
	protected function convertDelete(DeleteStatement $stmt): string
	{
		$qb = $this->connection->createQueryBuilder();

		// FROM table (DBAL 4.x doesn't support alias in delete).
		if (! empty($stmt->from)) {
			$table = $stmt->from[0];
			$qb->delete($table->table);
		}

		// WHERE clause.
		if (! empty($stmt->where)) {
			$whereParts = [];
			foreach ($stmt->where as $cond) {
				if ($cond->isOperator) {
					$whereParts[] = $cond->expr;
				} else {
					$whereParts[] = $this->convertConditionExpression($cond->expr);
				}
			}
			$qb->where(\implode(' ', $whereParts));
		}

		// ORDER BY clause.
		if (! empty($stmt->order)) {
			foreach ($stmt->order as $order) {
				$qb->addOrderBy($order->expr->expr, $order->type);
			}
		}

		// LIMIT clause.
		if (null !== $stmt->limit) {
			$qb->setMaxResults((int) $stmt->limit->rowCount);
		}

		return $qb->getSQL();
	}

	/**
	 * Convert REPLACE statement (MySQL-specific).
	 *
	 * @param ReplaceStatement $stmt The parsed REPLACE statement.
	 * @return string The converted SQL.
	 */
	protected function convertReplace(ReplaceStatement $stmt): string
	{
		$table = $stmt->into->dest->table ?? '';

		// Get columns.
		$columns = [];
		if (! empty($stmt->into->columns)) {
			foreach ($stmt->into->columns as $col) {
				$columns[] = $col;
			}
		}

		// Get values - use raw to preserve quotes.
		$allValues = [];
		if (! empty($stmt->values)) {
			foreach ($stmt->values as $valueSet) {
				$values = [];
				// Use raw values to preserve string quotes.
				/** @var \PhpMyAdmin\SqlParser\Components\ArrayObj $valueSet */
				$rawValues = $valueSet->raw;
				foreach ($rawValues as $val) {
					$values[] = $this->convertValueExpression($val);
				}
				$allValues[] = $values;
			}
		}

		$platform     = $this->connection->getDatabasePlatform();
		$quotedTable = $platform->quoteIdentifier($table);

		$quotedColumns = \array_map(
			fn($col) => $platform->quoteIdentifier($col),
			$columns
		);

		$sqlParts = [];
		foreach ($allValues as $values) {
			$sqlParts[] = '(' . \implode(', ', $values) . ')';
		}

		// REPLACE INTO is MySQL-specific. Convert for other platforms.
		$platformName = $this->getPlatformName();

		if ('sqlite' === $platformName) {
			// SQLite uses INSERT OR REPLACE.
			return \sprintf(
				'INSERT OR REPLACE INTO %s (%s) VALUES %s',
				$quotedTable,
				\implode(', ', $quotedColumns),
				\implode(', ', $sqlParts)
			);
		}

		// For MySQL, keep as REPLACE.
		return \sprintf(
			'REPLACE INTO %s (%s) VALUES %s',
			$quotedTable,
			\implode(', ', $quotedColumns),
			\implode(', ', $sqlParts)
		);
	}

	/**
	 * Convert an expression to SQL string.
	 *
	 * @param Expression $expr The expression.
	 * @return string The SQL string.
	 */
	protected function convertExpression(Expression $expr): string
	{
		$result = $expr->expr;

		// Handle function calls.
		if (! empty($expr->function)) {
			$result = $this->functionMapper->translate($result);
		}

		// Handle alias.
		if (! empty($expr->alias)) {
			// Quote the alias in case it's a reserved keyword (e.g., 'group').
			$platform = $this->connection->getDatabasePlatform();
			$result .= ' AS ' . $platform->quoteIdentifier($expr->alias);
		}

		return $result;
	}

	/**
	 * Convert a condition expression, translating functions.
	 *
	 * @param string $expr The condition expression.
	 * @return string The converted expression.
	 */
	protected function convertConditionExpression(string $expr): string
	{
		return $this->functionMapper->translate($expr);
	}

	/**
	 * Convert a value expression.
	 *
	 * @param mixed $val The value.
	 * @return string The SQL representation.
	 */
	protected function convertValueExpression(mixed $val): string
	{
		if (\is_string($val)) {
			// Check if it's a function call.
			return $this->functionMapper->translate($val);
		}

		return (string) $val;
	}

	/**
	 * Get table reference from expression.
	 *
	 * @param Expression $expr The expression.
	 * @return array{table: string, alias: string|null} Table info.
	 */
	protected function getTableReference(Expression $expr): array
	{
		return [
			'table' => $expr->table ?? $expr->expr,
			'alias' => $expr->alias,
		];
	}

	/**
	 * Get the current platform name.
	 *
	 * @return string Platform name (mysql, sqlite, pgsql).
	 */
	protected function getPlatformName(): string
	{
		$platform = $this->connection->getDatabasePlatform();
		$class    = \get_class($platform);

		return match (true) {
			\str_contains($class, 'SQLite')     => 'sqlite',
			\str_contains($class, 'PostgreSQL') => 'pgsql',
			\str_contains($class, 'MySQL')      => 'mysql',
			\str_contains($class, 'MariaDB')    => 'mysql',
			default                              => 'unknown',
		};
	}
}
