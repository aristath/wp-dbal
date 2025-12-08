<?php

/**
 * Query Executor - Routes SQL queries to appropriate executors.
 *
 * @package WP_DBAL
 */

declare(strict_types=1);

namespace WP_DBAL\FileDB\SQL;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\ReplaceStatement;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;
use PhpMyAdmin\SqlParser\Statements\AlterStatement;
use PhpMyAdmin\SqlParser\Statements\DropStatement;
use PhpMyAdmin\SqlParser\Statements\TruncateStatement;
use PhpMyAdmin\SqlParser\Statements\ShowStatement;
use PhpMyAdmin\SqlParser\Statements\SetStatement;
use WP_DBAL\FileDB\Connection;
use WP_DBAL\FileDB\Result;
use WP_DBAL\FileDB\Storage\StorageManager;

/**
 * Routes SQL queries to the appropriate executor.
 */
class QueryExecutor
{
	/**
	 * Storage manager instance.
	 *
	 * @var StorageManager
	 */
	protected StorageManager $storage;

	/**
	 * Connection instance.
	 *
	 * @var Connection
	 */
	protected Connection $connection;

	/**
	 * Expression evaluator instance.
	 *
	 * @var ExpressionEvaluator
	 */
	protected ExpressionEvaluator $evaluator;

	/**
	 * Select executor instance.
	 *
	 * @var SelectExecutor
	 */
	protected SelectExecutor $selectExecutor;

	/**
	 * Insert executor instance.
	 *
	 * @var InsertExecutor
	 */
	protected InsertExecutor $insertExecutor;

	/**
	 * Update executor instance.
	 *
	 * @var UpdateExecutor
	 */
	protected UpdateExecutor $updateExecutor;

	/**
	 * Delete executor instance.
	 *
	 * @var DeleteExecutor
	 */
	protected DeleteExecutor $deleteExecutor;

	/**
	 * DDL executor instance.
	 *
	 * @var DDLExecutor
	 */
	protected DDLExecutor $ddlExecutor;

	/**
	 * Constructor.
	 *
	 * @param StorageManager $storage    The storage manager.
	 * @param Connection     $connection The connection.
	 */
	public function __construct(StorageManager $storage, Connection $connection)
	{
		$this->storage    = $storage;
		$this->connection = $connection;
		$this->evaluator  = new ExpressionEvaluator();

		// Initialize executors.
		$this->selectExecutor = new SelectExecutor($storage, $this->evaluator);
		$this->insertExecutor = new InsertExecutor($storage, $connection, $this->evaluator);
		$this->updateExecutor = new UpdateExecutor($storage, $this->evaluator);
		$this->deleteExecutor = new DeleteExecutor($storage, $this->evaluator);
		$this->ddlExecutor    = new DDLExecutor($storage);
	}

	/**
	 * Execute a SQL query.
	 *
	 * @param string $sql The SQL query.
	 * @return Result The query result.
	 */
	public function execute(string $sql): Result
	{
		// Handle special queries that don't need parsing.
		$handled = $this->handleSpecialQuery($sql);
		if (null !== $handled) {
			return $handled;
		}

		// Parse the SQL.
		$parser = new Parser($sql);

		if (empty($parser->statements)) {
			// Parsing failed.
			return new Result([]);
		}

		$stmt = $parser->statements[0];

		// Route to appropriate executor.
		return match (true) {
			$stmt instanceof SelectStatement   => $this->selectExecutor->execute($stmt),
			$stmt instanceof InsertStatement   => $this->insertExecutor->execute($stmt),
			$stmt instanceof UpdateStatement   => $this->updateExecutor->execute($stmt),
			$stmt instanceof DeleteStatement   => $this->deleteExecutor->execute($stmt),
			$stmt instanceof ReplaceStatement  => $this->executeReplace($stmt),
			$stmt instanceof CreateStatement   => $this->ddlExecutor->executeCreate($stmt),
			$stmt instanceof AlterStatement    => $this->ddlExecutor->executeAlter($stmt),
			$stmt instanceof DropStatement     => $this->ddlExecutor->executeDrop($stmt),
			$stmt instanceof TruncateStatement => $this->ddlExecutor->executeTruncate($stmt),
			$stmt instanceof ShowStatement     => $this->executeShow($stmt, $sql),
			$stmt instanceof SetStatement      => new Result([]), // SET is a no-op for FileDB.
			default                            => new Result([]),
		};
	}

	/**
	 * Handle special queries that don't need parsing.
	 *
	 * @param string $sql The SQL query.
	 * @return Result|null The result or null if not a special query.
	 */
	protected function handleSpecialQuery(string $sql): ?Result
	{
		$sql = \trim($sql);
		$upper = \strtoupper($sql);

		// START/BEGIN/COMMIT/ROLLBACK TRANSACTION.
		if (
			\str_starts_with($upper, 'START TRANSACTION') ||
			\str_starts_with($upper, 'BEGIN') ||
			\str_starts_with($upper, 'COMMIT') ||
			\str_starts_with($upper, 'ROLLBACK')
		) {
			return new Result([]);
		}

		// SET commands.
		if (\str_starts_with($upper, 'SET ')) {
			return new Result([]);
		}

		// SELECT VERSION().
		if (\preg_match('/^SELECT\s+VERSION\s*\(\s*\)/i', $sql)) {
			return new Result([['VERSION()' => 'FileDB 1.0.0']]);
		}

		// SELECT DATABASE().
		if (\preg_match('/^SELECT\s+DATABASE\s*\(\s*\)/i', $sql)) {
			return new Result([['DATABASE()' => 'filedb']]);
		}

		// SELECT @@.
		if (\preg_match('/^SELECT\s+@@(\w+)/i', $sql, $m)) {
			$var = \strtolower($m[1]);
			$value = match ($var) {
				'version'            => 'FileDB 1.0.0',
				'sql_mode'           => '',
				'max_allowed_packet' => 67108864,
				'character_set_client', 'character_set_connection', 'character_set_results' => 'utf8mb4',
				'collation_connection' => 'utf8mb4_unicode_ci',
				'time_zone'          => 'SYSTEM',
				'autocommit'         => 1,
				default              => '',
			};
			return new Result([['@@' . $var => $value]]);
		}

		// DESCRIBE / DESC / EXPLAIN table.
		if (\preg_match('/^(DESCRIBE|DESC|EXPLAIN)\s+`?(\w+)`?/i', $sql, $m)) {
			return $this->describeTable($m[2]);
		}

		return null;
	}

	/**
	 * Execute a REPLACE statement.
	 *
	 * REPLACE is like INSERT but deletes existing row with same primary key first.
	 *
	 * @param ReplaceStatement $stmt The parsed statement.
	 * @return Result The query result.
	 */
	protected function executeReplace(ReplaceStatement $stmt): Result
	{
		$table = $stmt->into->dest->table ?? '';
		if ('' === $table) {
			return new Result([], 0);
		}

		// Get columns.
		$columns = [];
		if (!empty($stmt->into->columns)) {
			foreach ($stmt->into->columns as $col) {
				$columns[] = $col;
			}
		}

		// Get primary key.
		$pk = $this->storage->getSchemaManager()->getPrimaryKey($table);

		$replacedCount = 0;
		$lastInsertId  = 0;

		if (!empty($stmt->values)) {
			foreach ($stmt->values as $valueSet) {
				$row = $this->buildRow($columns, $valueSet);

				if (!empty($row)) {
					// Check if row exists with this primary key.
					$primaryId = $this->getPrimaryKeyValue($row, $pk);
					if ($primaryId && $this->storage->getRow($table, $primaryId)) {
						// Delete existing row.
						$this->storage->deleteRow($table, $primaryId);
					}

					// Insert new row.
					$id = $this->storage->insertRow($table, $row);
					$replacedCount++;
					$lastInsertId = $id;
				}
			}
		}

		// Flush changes.
		$this->storage->flush();

		// Set last insert ID.
		if ($lastInsertId) {
			$this->connection->setLastInsertId($lastInsertId);
		}

		return new Result([], $replacedCount);
	}

	/**
	 * Build a row from columns and values.
	 *
	 * @param list<string> $columns  The column names.
	 * @param mixed        $valueSet The value set.
	 * @return array<string, mixed> The row data.
	 */
	protected function buildRow(array $columns, mixed $valueSet): array
	{
		$row    = [];
		$values = $valueSet->raw ?? [];

		foreach ($columns as $i => $column) {
			if (isset($values[$i])) {
				$row[$column] = $this->parseValue($values[$i]);
			}
		}

		return $row;
	}

	/**
	 * Parse a value expression.
	 *
	 * @param string $value The value expression.
	 * @return mixed The parsed value.
	 */
	protected function parseValue(string $value): mixed
	{
		$value = \trim($value);

		if ('NULL' === \strtoupper($value)) {
			return null;
		}

		if (\preg_match('/^[\'"](.*)[\'"]\s*$/s', $value, $m)) {
			return $this->unescapeString($m[1]);
		}

		if (\is_numeric($value)) {
			return \str_contains($value, '.') ? (float) $value : (int) $value;
		}

		$upper = \strtoupper($value);
		if ('TRUE' === $upper) {
			return true;
		}
		if ('FALSE' === $upper) {
			return false;
		}

		if (\preg_match('/^(\w+)\s*\((.*)?\)\s*$/i', $value)) {
			return $this->evaluator->evaluateValue($value, [], []);
		}

		return $value;
	}

	/**
	 * Unescape a MySQL string literal character by character.
	 *
	 * This properly handles MySQL escape sequences and avoids the issue where
	 * str_replace() would convert \"\" (empty string) to " instead of "".
	 *
	 * @param string $str The string content (without surrounding quotes).
	 * @return string The unescaped string.
	 */
	protected function unescapeString(string $str): string
	{
		$result = '';
		$len    = \strlen($str);
		$i      = 0;

		while ($i < $len) {
			// Check for escape sequence.
			if ('\\' === $str[$i] && $i + 1 < $len) {
				$next = $str[$i + 1];
				switch ($next) {
					case '\\':
						$result .= '\\';
						$i += 2;
						break;
					case "'":
						$result .= "'";
						$i += 2;
						break;
					case '"':
						$result .= '"';
						$i += 2;
						break;
					case '0':
						$result .= "\x00";
						$i += 2;
						break;
					case 'n':
						$result .= "\n";
						$i += 2;
						break;
					case 'r':
						$result .= "\r";
						$i += 2;
						break;
					case 't':
						$result .= "\t";
						$i += 2;
						break;
					case 'Z':
						$result .= "\x1a";
						$i += 2;
						break;
					default:
						// Unknown escape, keep the backslash and move forward.
						$result .= $str[$i];
						$i++;
						break;
				}
			} elseif ("'" === $str[$i] && $i + 1 < $len && "'" === $str[$i + 1]) {
				// MySQL doubled single quote '' => '.
				$result .= "'";
				$i += 2;
			} elseif ('"' === $str[$i] && $i + 1 < $len && '"' === $str[$i + 1]) {
				// MySQL doubled double quote "" => ".
				$result .= '"';
				$i += 2;
			} else {
				$result .= $str[$i];
				$i++;
			}
		}

		return $result;
	}

	/**
	 * Get the primary key value from a row.
	 *
	 * @param array<string, mixed> $row The row data.
	 * @param list<string>         $pk  Primary key columns.
	 * @return int|string|null The primary key value.
	 */
	protected function getPrimaryKeyValue(array $row, array $pk): int|string|null
	{
		if (empty($pk)) {
			return null;
		}

		if (1 === \count($pk)) {
			return $row[$pk[0]] ?? null;
		}

		$parts = [];
		foreach ($pk as $col) {
			if (!isset($row[$col])) {
				return null;
			}
			$parts[] = $row[$col];
		}

		return \implode('_', $parts);
	}

	/**
	 * Execute a SHOW statement.
	 *
	 * @param ShowStatement $stmt The parsed statement.
	 * @param string        $sql  The original SQL.
	 * @return Result The query result.
	 */
	protected function executeShow(ShowStatement $stmt, string $sql): Result
	{
		$upper = \strtoupper($sql);

		// SHOW TABLES.
		if (\str_contains($upper, 'SHOW TABLES') || \str_contains($upper, 'SHOW FULL TABLES')) {
			$tables = $this->storage->getSchemaManager()->listTables();
			$rows = [];
			foreach ($tables as $table) {
				$rows[] = ['Tables_in_filedb' => $table, 'Table_type' => 'BASE TABLE'];
			}
			return new Result($rows);
		}

		// SHOW DATABASES.
		if (\str_contains($upper, 'SHOW DATABASES')) {
			return new Result([['Database' => 'filedb']]);
		}

		// SHOW COLUMNS / SHOW FIELDS.
		if (\preg_match('/SHOW\s+(FULL\s+)?(COLUMNS|FIELDS)\s+FROM\s+`?(\w+)`?/i', $sql, $m)) {
			return $this->describeTable($m[3]);
		}

		// SHOW CREATE TABLE.
		if (\preg_match('/SHOW\s+CREATE\s+TABLE\s+`?(\w+)`?/i', $sql, $m)) {
			return $this->showCreateTable($m[1]);
		}

		// SHOW INDEX / SHOW KEYS.
		if (\preg_match('/SHOW\s+(INDEX|INDEXES|KEYS)\s+FROM\s+`?(\w+)`?/i', $sql, $m)) {
			return $this->showIndex($m[2]);
		}

		// SHOW VARIABLES.
		if (\str_contains($upper, 'SHOW VARIABLES')) {
			return new Result([
				['Variable_name' => 'version', 'Value' => 'FileDB 1.0.0'],
				['Variable_name' => 'character_set_client', 'Value' => 'utf8mb4'],
				['Variable_name' => 'collation_connection', 'Value' => 'utf8mb4_unicode_ci'],
			]);
		}

		// SHOW STATUS.
		if (\str_contains($upper, 'SHOW STATUS')) {
			return new Result([
				['Variable_name' => 'Uptime', 'Value' => '0'],
				['Variable_name' => 'Threads_connected', 'Value' => '1'],
			]);
		}

		return new Result([]);
	}

	/**
	 * Describe a table's structure.
	 *
	 * @param string $table The table name.
	 * @return Result The table description.
	 */
	protected function describeTable(string $table): Result
	{
		$schema = $this->storage->getSchemaManager()->getSchema($table);

		if (null === $schema) {
			return new Result([]);
		}

		$rows = [];
		$pk = $schema['primaryKey'] ?? [];

		foreach ($schema['columns'] ?? [] as $name => $col) {
			$rows[] = [
				'Field'   => $name,
				'Type'    => $col['type'] ?? 'varchar(255)',
				'Null'    => ($col['nullable'] ?? true) ? 'YES' : 'NO',
				'Key'     => \in_array($name, $pk, true) ? 'PRI' : '',
				'Default' => $col['default'] ?? null,
				'Extra'   => ($col['autoIncrement'] ?? false) ? 'auto_increment' : '',
			];
		}

		return new Result($rows);
	}

	/**
	 * Show CREATE TABLE statement.
	 *
	 * @param string $table The table name.
	 * @return Result The CREATE TABLE statement.
	 */
	protected function showCreateTable(string $table): Result
	{
		$schema = $this->storage->getSchemaManager()->getSchema($table);

		if (null === $schema) {
			return new Result([]);
		}

		$lines = [];
		$pk = $schema['primaryKey'] ?? [];

		foreach ($schema['columns'] ?? [] as $name => $col) {
			$line = '  `' . $name . '` ' . ($col['type'] ?? 'varchar(255)');

			if (!($col['nullable'] ?? true)) {
				$line .= ' NOT NULL';
			}

			if ($col['autoIncrement'] ?? false) {
				$line .= ' AUTO_INCREMENT';
			}

			$lines[] = $line;
		}

		if (!empty($pk)) {
			$lines[] = '  PRIMARY KEY (`' . \implode('`, `', $pk) . '`)';
		}

		$createSql = "CREATE TABLE `{$table}` (\n" . \implode(",\n", $lines) . "\n) ENGINE=FileDB";

		return new Result([
			['Table' => $table, 'Create Table' => $createSql],
		]);
	}

	/**
	 * Show table indexes.
	 *
	 * Returns MySQL-compatible SHOW INDEX result format.
	 *
	 * @param string $table The table name.
	 * @return Result The index information.
	 */
	protected function showIndex(string $table): Result
	{
		$schema = $this->storage->getSchemaManager()->getSchema($table);

		if (null === $schema) {
			return new Result([]);
		}

		$rows = [];
		$pk = $schema['primaryKey'] ?? [];

		foreach ($pk as $i => $col) {
			$rows[] = [
				'Table'        => $table,
				'Non_unique'   => 0,
				'Key_name'     => 'PRIMARY',
				'Seq_in_index' => $i + 1,
				'Column_name'  => $col,
				'Collation'    => 'A',
				'Cardinality'  => 0,
				'Sub_part'     => null,
				'Packed'       => null,
				'Null'         => '',
				'Index_type'   => 'BTREE',
				'Comment'      => '',
				'Index_comment' => '',
			];
		}

		return new Result($rows);
	}
}
