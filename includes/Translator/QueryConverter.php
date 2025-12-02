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
class QueryConverter {

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
	protected FunctionMapper $function_mapper;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection DBAL connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection      = $connection;
		$this->function_mapper = new FunctionMapper( $connection );
	}

	/**
	 * Convert a MySQL query to platform-appropriate SQL.
	 *
	 * @param string $query The MySQL query.
	 * @return string|array The converted query or array of queries.
	 */
	public function convert( string $query ): string|array {
		// Parse the MySQL query.
		$parser = new Parser( $query );

		if ( empty( $parser->statements ) ) {
			// Parsing failed, return original query.
			return $query;
		}

		$statement = $parser->statements[0];

		// Convert based on statement type.
		return match ( true ) {
			$statement instanceof SelectStatement  => $this->convert_select( $statement ),
			$statement instanceof InsertStatement  => $this->convert_insert( $statement ),
			$statement instanceof UpdateStatement  => $this->convert_update( $statement ),
			$statement instanceof DeleteStatement  => $this->convert_delete( $statement ),
			$statement instanceof ReplaceStatement => $this->convert_replace( $statement ),
			default                                => $query, // Pass through unsupported statements.
		};
	}

	/**
	 * Convert SELECT statement to DBAL QueryBuilder.
	 *
	 * @param SelectStatement $stmt The parsed SELECT statement.
	 * @return string The converted SQL.
	 */
	protected function convert_select( SelectStatement $stmt ): string {
		$qb = $this->connection->createQueryBuilder();

		// SELECT columns.
		$columns = [];
		if ( ! empty( $stmt->expr ) ) {
			foreach ( $stmt->expr as $expr ) {
				$columns[] = $this->convert_expression( $expr );
			}
		}
		$qb->select( ...$columns );

		// FROM clause - track the primary table alias for JOINs.
		$from_alias = null;
		if ( ! empty( $stmt->from ) ) {
			foreach ( $stmt->from as $i => $from ) {
				$table = $this->get_table_reference( $from );
				if ( 0 === $i ) {
					// Use alias if provided, otherwise use table name as alias.
					$from_alias = $table['alias'] ?? $table['table'];
					$qb->from( $table['table'], $from_alias );
				}
			}
		}

		// JOIN clauses.
		// DBAL signature: innerJoin($fromAlias, $joinTable, $joinAlias, $condition)
		// $fromAlias must be an alias already registered (from FROM or previous JOIN).
		if ( ! empty( $stmt->join ) && null !== $from_alias ) {
			foreach ( $stmt->join as $join ) {
				$join_table = $this->get_table_reference( $join->expr );
				$join_type  = strtoupper( $join->type );
				$join_on    = '';

				// Use alias if provided, otherwise use table name as alias.
				$join_alias = $join_table['alias'] ?? $join_table['table'];

				if ( ! empty( $join->on ) ) {
					$conditions = [];
					foreach ( $join->on as $cond ) {
						$conditions[] = $cond->expr;
					}
					$join_on = implode( ' AND ', $conditions );
				}

				// First argument is the FROM alias (the table we're joining FROM).
				match ( $join_type ) {
					'LEFT', 'LEFT OUTER'   => $qb->leftJoin( $from_alias, $join_table['table'], $join_alias, $join_on ),
					'RIGHT', 'RIGHT OUTER' => $qb->rightJoin( $from_alias, $join_table['table'], $join_alias, $join_on ),
					default                => $qb->innerJoin( $from_alias, $join_table['table'], $join_alias, $join_on ),
				};
			}
		}

		// WHERE clause.
		if ( ! empty( $stmt->where ) ) {
			$where_parts = [];
			foreach ( $stmt->where as $cond ) {
				if ( $cond->isOperator ) {
					$where_parts[] = $cond->expr;
				} else {
					$where_parts[] = $this->convert_condition_expression( $cond->expr );
				}
			}
			$qb->where( implode( ' ', $where_parts ) );
		}

		// GROUP BY clause.
		if ( ! empty( $stmt->group ) ) {
			foreach ( $stmt->group as $group ) {
				$qb->addGroupBy( $group->expr->expr );
			}
		}

		// HAVING clause.
		if ( ! empty( $stmt->having ) ) {
			$having_parts = [];
			foreach ( $stmt->having as $cond ) {
				if ( $cond->isOperator ) {
					$having_parts[] = $cond->expr;
				} else {
					$having_parts[] = $this->convert_condition_expression( $cond->expr );
				}
			}
			$qb->having( implode( ' ', $having_parts ) );
		}

		// ORDER BY clause.
		if ( ! empty( $stmt->order ) ) {
			foreach ( $stmt->order as $order ) {
				$qb->addOrderBy( $order->expr->expr, $order->type );
			}
		}

		// LIMIT clause.
		if ( null !== $stmt->limit ) {
			$qb->setMaxResults( (int) $stmt->limit->rowCount );
			if ( $stmt->limit->offset > 0 ) {
				$qb->setFirstResult( (int) $stmt->limit->offset );
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
	protected function convert_insert( InsertStatement $stmt ): string {
		$table = $stmt->into->dest->table ?? '';

		// Get columns.
		$columns = [];
		if ( ! empty( $stmt->into->columns ) ) {
			foreach ( $stmt->into->columns as $col ) {
				$columns[] = $col;
			}
		}

		// Get values - use raw to preserve quotes.
		$all_values = [];
		if ( ! empty( $stmt->values ) ) {
			foreach ( $stmt->values as $value_set ) {
				$values = [];
				// Use raw values to preserve string quotes.
				// ArrayObj has 'raw' property, Array2d elements are also ArrayObj.
				/** @var \PhpMyAdmin\SqlParser\Components\ArrayObj $value_set */
				$raw_values = $value_set->raw;
				foreach ( $raw_values as $val ) {
					$values[] = $this->convert_value_expression( $val );
				}
				$all_values[] = $values;
			}
		}

		// Build INSERT statement.
		$platform     = $this->connection->getDatabasePlatform();
		$quoted_table = $platform->quoteIdentifier( $table );

		$quoted_columns = array_map(
			fn( $col ) => $platform->quoteIdentifier( $col ),
			$columns
		);

		$sql_parts = [];
		foreach ( $all_values as $values ) {
			$sql_parts[] = '(' . implode( ', ', $values ) . ')';
		}

		// Handle INSERT IGNORE for SQLite.
		$insert_keyword = 'INSERT';
		if ( ! empty( $stmt->options ) && $stmt->options->has( 'IGNORE' ) ) {
			$platform_name = $this->get_platform_name();
			if ( 'sqlite' === $platform_name ) {
				$insert_keyword = 'INSERT OR IGNORE';
			} else {
				$insert_keyword = 'INSERT IGNORE';
			}
		}

		return sprintf(
			'%s INTO %s (%s) VALUES %s',
			$insert_keyword,
			$quoted_table,
			implode( ', ', $quoted_columns ),
			implode( ', ', $sql_parts )
		);
	}

	/**
	 * Convert UPDATE statement.
	 *
	 * @param UpdateStatement $stmt The parsed UPDATE statement.
	 * @return string The converted SQL.
	 */
	protected function convert_update( UpdateStatement $stmt ): string {
		$qb = $this->connection->createQueryBuilder();

		// Table (DBAL 4.x doesn't support alias in update).
		if ( ! empty( $stmt->tables ) ) {
			$table = $stmt->tables[0];
			$qb->update( $table->table );
		}

		// SET clause.
		if ( ! empty( $stmt->set ) ) {
			foreach ( $stmt->set as $set ) {
				$qb->set( $set->column, $this->convert_value_expression( $set->value ) );
			}
		}

		// WHERE clause.
		if ( ! empty( $stmt->where ) ) {
			$where_parts = [];
			foreach ( $stmt->where as $cond ) {
				if ( $cond->isOperator ) {
					$where_parts[] = $cond->expr;
				} else {
					$where_parts[] = $this->convert_condition_expression( $cond->expr );
				}
			}
			$qb->where( implode( ' ', $where_parts ) );
		}

		// ORDER BY clause.
		if ( ! empty( $stmt->order ) ) {
			foreach ( $stmt->order as $order ) {
				$qb->addOrderBy( $order->expr->expr, $order->type );
			}
		}

		// LIMIT clause.
		if ( null !== $stmt->limit ) {
			$qb->setMaxResults( (int) $stmt->limit->rowCount );
		}

		return $qb->getSQL();
	}

	/**
	 * Convert DELETE statement.
	 *
	 * @param DeleteStatement $stmt The parsed DELETE statement.
	 * @return string The converted SQL.
	 */
	protected function convert_delete( DeleteStatement $stmt ): string {
		$qb = $this->connection->createQueryBuilder();

		// FROM table (DBAL 4.x doesn't support alias in delete).
		if ( ! empty( $stmt->from ) ) {
			$table = $stmt->from[0];
			$qb->delete( $table->table );
		}

		// WHERE clause.
		if ( ! empty( $stmt->where ) ) {
			$where_parts = [];
			foreach ( $stmt->where as $cond ) {
				if ( $cond->isOperator ) {
					$where_parts[] = $cond->expr;
				} else {
					$where_parts[] = $this->convert_condition_expression( $cond->expr );
				}
			}
			$qb->where( implode( ' ', $where_parts ) );
		}

		// ORDER BY clause.
		if ( ! empty( $stmt->order ) ) {
			foreach ( $stmt->order as $order ) {
				$qb->addOrderBy( $order->expr->expr, $order->type );
			}
		}

		// LIMIT clause.
		if ( null !== $stmt->limit ) {
			$qb->setMaxResults( (int) $stmt->limit->rowCount );
		}

		return $qb->getSQL();
	}

	/**
	 * Convert REPLACE statement (MySQL-specific).
	 *
	 * @param ReplaceStatement $stmt The parsed REPLACE statement.
	 * @return string The converted SQL.
	 */
	protected function convert_replace( ReplaceStatement $stmt ): string {
		$table = $stmt->into->dest->table ?? '';

		// Get columns.
		$columns = [];
		if ( ! empty( $stmt->into->columns ) ) {
			foreach ( $stmt->into->columns as $col ) {
				$columns[] = $col;
			}
		}

		// Get values - use raw to preserve quotes.
		$all_values = [];
		if ( ! empty( $stmt->values ) ) {
			foreach ( $stmt->values as $value_set ) {
				$values = [];
				// Use raw values to preserve string quotes.
				/** @var \PhpMyAdmin\SqlParser\Components\ArrayObj $value_set */
				$raw_values = $value_set->raw;
				foreach ( $raw_values as $val ) {
					$values[] = $this->convert_value_expression( $val );
				}
				$all_values[] = $values;
			}
		}

		$platform     = $this->connection->getDatabasePlatform();
		$quoted_table = $platform->quoteIdentifier( $table );

		$quoted_columns = array_map(
			fn( $col ) => $platform->quoteIdentifier( $col ),
			$columns
		);

		$sql_parts = [];
		foreach ( $all_values as $values ) {
			$sql_parts[] = '(' . implode( ', ', $values ) . ')';
		}

		// REPLACE INTO is MySQL-specific. Convert for other platforms.
		$platform_name = $this->get_platform_name();

		if ( 'sqlite' === $platform_name ) {
			// SQLite uses INSERT OR REPLACE.
			return sprintf(
				'INSERT OR REPLACE INTO %s (%s) VALUES %s',
				$quoted_table,
				implode( ', ', $quoted_columns ),
				implode( ', ', $sql_parts )
			);
		}

		// For MySQL, keep as REPLACE.
		return sprintf(
			'REPLACE INTO %s (%s) VALUES %s',
			$quoted_table,
			implode( ', ', $quoted_columns ),
			implode( ', ', $sql_parts )
		);
	}

	/**
	 * Convert an expression to SQL string.
	 *
	 * @param Expression $expr The expression.
	 * @return string The SQL string.
	 */
	protected function convert_expression( Expression $expr ): string {
		$result = $expr->expr;

		// Handle function calls.
		if ( ! empty( $expr->function ) ) {
			$result = $this->function_mapper->translate( $result );
		}

		// Handle alias.
		if ( ! empty( $expr->alias ) ) {
			$result .= ' AS ' . $expr->alias;
		}

		return $result;
	}

	/**
	 * Convert a condition expression, translating functions.
	 *
	 * @param string $expr The condition expression.
	 * @return string The converted expression.
	 */
	protected function convert_condition_expression( string $expr ): string {
		return $this->function_mapper->translate( $expr );
	}

	/**
	 * Convert a value expression.
	 *
	 * @param mixed $val The value.
	 * @return string The SQL representation.
	 */
	protected function convert_value_expression( mixed $val ): string {
		if ( is_string( $val ) ) {
			// Check if it's a function call.
			return $this->function_mapper->translate( $val );
		}

		return (string) $val;
	}

	/**
	 * Get table reference from expression.
	 *
	 * @param Expression $expr The expression.
	 * @return array{table: string, alias: string|null} Table info.
	 */
	protected function get_table_reference( Expression $expr ): array {
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
	protected function get_platform_name(): string {
		$platform = $this->connection->getDatabasePlatform();
		$class    = get_class( $platform );

		return match ( true ) {
			str_contains( $class, 'SQLite' )     => 'sqlite',
			str_contains( $class, 'PostgreSQL' ) => 'pgsql',
			str_contains( $class, 'MySQL' )      => 'mysql',
			str_contains( $class, 'MariaDB' )    => 'mysql',
			default                              => 'unknown',
		};
	}
}
