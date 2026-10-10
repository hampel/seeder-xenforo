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

	/** @var string the short name of the entity whose table this writes, such as XF:SessionActivity */
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
	 * @param array $keys one [column => value] primary key per row
	 *
	 * @return int how many were still there to delete
	 */
	public static function removeMany(string $entity, array $keys): int
	{
		if (!$keys)
		{
			return 0;
		}

		$structure = \XF::em()->getEntityStructure($entity);

		return (int) \XF::db()->query(
			"DELETE FROM `{$structure->table}` WHERE " . self::whereKeys((array) $structure->primaryKey, $keys)
		)->rowsAffected();
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
		$structure = \XF::em()->getEntityStructure($this->entity);
		$primaryKey = (array) $structure->primaryKey;

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
			'SELECT `' . implode('`, `', $primaryKey) . "` FROM `{$structure->table}` WHERE "
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

		$db->insertBulk($structure->table, array_column($byKey, 1), false, false, 'IGNORE');
		$this->context()->recorder->recordRows($this->entity, array_column($byKey, 0), static::class);

		return count($byKey);
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
