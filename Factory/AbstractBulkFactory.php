<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Context;

/**
 * Writes many rows of one table at once, for the kind of data that is rows and nothing more -
 * who is online, say - where saving an entity for each would take minutes and gain nothing.
 *
 * **This is the wrong tool for content.** A row written here goes straight into the table: no
 * entity is saved, so nothing XenForo does when one is - counters, the search index, other
 * add-ons' own bookkeeping - happens. Use it only for a table whose rows stand alone.
 *
 * Rows are still recorded, as they are written, and removed by seeder:clean. A row whose key is
 * already in the table is left exactly as it is and not recorded: it is somebody else's.
 */
abstract class AbstractBulkFactory
{
	protected const CHUNK = 1000;

	/** @var array [table => true] tables already checked for columns this would leave out */
	private static $checked = [];

	/**
	 * @var string the short name of the entity whose table this writes, such as XF:SessionActivity -
	 *             or, for a table XenForo gives no entity, the table's own name, with tableOf()
	 *             saying what its key is
	 */
	protected $entity;

	final public function __construct()
	{
	}

	/**
	 * @return static
	 */
	public static function new()
	{
		return new static();
	}

	/**
	 * The rows to write, each [column => value] as the database stores it - an address already
	 * binary, a list already encoded.
	 */
	abstract protected function rows(): iterable;

	/**
	 * Write and record the rows.
	 *
	 * @return int how many were written
	 */
	public function create(): int
	{
		$written = 0;
		$chunk = [];

		foreach ($this->rows() AS $row)
		{
			$chunk[] = $row;
			if (count($chunk) >= static::CHUNK)
			{
				$written += $this->write($chunk);
				$chunk = [];
			}
		}

		return $written + ($chunk ? $this->write($chunk) : 0);
	}

	/**
	 * Delete rows this factory wrote. seeder:clean calls this with the keys it recorded.
	 *
	 * @param string $entity the entity's short name
	 * @param array $keys one [column => value] primary key per row, or the value alone where the
	 *                    key is one column
	 *
	 * @return int how many were still there to delete
	 */
	public static function removeMany(string $entity, array $keys): int
	{
		if (!$keys)
		{
			return 0;
		}

		[$table, $columns] = static::tableOf($entity);

		// a key of one column is recorded, and so comes back, as its value alone
		foreach ($keys AS &$key)
		{
			if (!is_array($key))
			{
				$key = [reset($columns) => $key];
			}
		}
		unset($key);

		return (int) \XF::db()->query(
			"DELETE FROM `{$table}` WHERE " . self::whereKeys($columns, $keys)
		)->rowsAffected();
	}

	/**
	 * The table an entity's rows are in, and its primary key. A factory for a table XenForo gives
	 * no entity answers for it here.
	 *
	 * @return array [table, [column, ...]]
	 */
	protected static function tableOf(string $entity): array
	{
		$structure = \XF::em()->getEntityStructure($entity);

		return [$structure->table, (array) $structure->primaryKey];
	}

	protected function context(): Context
	{
		return Context::current();
	}

	/**
	 * @return int how many of the chunk were written
	 */
	protected function write(array $rows): int
	{
		$db = \XF::db();
		[$table, $primaryKey] = static::tableOf($this->entity);

		// one row per key: a second with the same key in this chunk would be dropped by the insert
		$byKey = [];
		foreach ($rows AS $row)
		{
			$key = array_intersect_key($row, array_flip($primaryKey));
			$byKey[serialize(array_map('strval', $key))] = [$key, $row];
		}

		// and none whose key is already in the table: that row is somebody else's, or an earlier
		// chunk's, and either way is not to be overwritten or recorded again
		$existing = $db->fetchAll(
			'SELECT `' . implode('`, `', $primaryKey) . "` FROM `{$table}` WHERE "
			. self::whereKeys($primaryKey, array_column($byKey, 0))
		);
		foreach ($existing AS $key)
		{
			unset($byKey[serialize(array_map('strval', $key))]);
		}

		if (!$byKey)
		{
			return 0;
		}

		self::assertWritable($table, array_keys(reset($byKey)[1]));
		$db->insertBulk($table, array_column($byKey, 1), false, false, 'IGNORE');
		$this->context()->recorder->recordRows($this->entity, array_column($byKey, 0), static::class);

		return count($byKey);
	}

	/**
	 * Write rows one at a time into a table whose key the database gives, so that the id each
	 * insert was given is the one recorded - never a guess that another writer to the table could
	 * have taken. A factory for such a table calls this from its own write().
	 *
	 * @return int how many were written
	 */
	protected function insertEach(array $rows): int
	{
		$db = \XF::db();
		[$table, $primaryKey] = static::tableOf($this->entity);
		self::assertWritable($table, array_keys(reset($rows)));

		foreach ($rows AS $row)
		{
			$db->insert($table, $row);
			$this->context()->recorder->recordRows($this->entity, [[reset($primaryKey) => (int) $db->lastInsertId()]], static::class);
		}

		return count($rows);
	}

	/**
	 * Refuse a table another add-on has given a column these rows would leave out and the
	 * database cannot fill: the insert would fail on it anyway, and the add-on that added it, not
	 * this one, would look broken.
	 *
	 * @param string[] $columns the columns each row names
	 */
	protected static function assertWritable(string $table, array $columns): void
	{
		if (isset(self::$checked[$table]))
		{
			return;
		}

		foreach (\XF::db()->fetchAll("SHOW COLUMNS FROM `{$table}`") AS $column)
		{
			if ($column['Null'] === 'NO' && $column['Default'] === null
				&& strpos($column['Extra'], 'auto_increment') === false && !in_array($column['Field'], $columns, true))
			{
				throw new \RuntimeException("Seeder will not write to {$table}: another add-on has given it the column "
					. "'{$column['Field']}', which has no default, so every row Seeder wrote would be refused");
			}
		}

		self::$checked[$table] = true;
	}

	/**
	 * @param string[] $columns the primary key's columns
	 * @param array $keys one [column => value] per row
	 */
	protected static function whereKeys(array $columns, array $keys): string
	{
		$db = \XF::db();

		$tuples = [];
		foreach ($keys AS $key)
		{
			$values = [];
			foreach ($columns AS $column)
			{
				$values[] = $db->quote($key[$column]);
			}
			$tuples[] = '(' . implode(', ', $values) . ')';
		}

		return '(`' . implode('`, `', $columns) . '`) IN (' . implode(', ', $tuples) . ')';
	}
}
