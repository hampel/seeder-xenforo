<?php namespace Hampel\Seeder\Record;

use Hampel\Seeder\Factory\AbstractBulkFactory;
use Hampel\Seeder\Factory\AbstractFactory;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use XF\Mvc\Entity\Entity;

/**
 * Removes what a batch recorded, newest record first. Creation order is dependency order - a
 * thread is recorded before its replies, a member before anything they posted - so walking it
 * backwards deletes children before parents without a table of which type depends on which.
 *
 * Deletion goes through the entity, so XenForo's own cascades and search index clean-up run.
 */
class Cleaner
{
	protected const CHUNK = 500;

	/** how many times one removal is tried when it is the loser of a deadlock */
	protected const ATTEMPTS = 3;

	/**
	 * @return array ['removed' => [shortName => int], 'missing' => [shortName => int],
	 *                'failed' => [['entity' => string, 'id' => string, 'error' => string], ...]]
	 */
	public function clean(string $batch): array
	{
		$result = ['removed' => [], 'missing' => [], 'failed' => []];
		$db = \XF::db();
		$below = PHP_INT_MAX;

		// Entities made earlier in this process remember relations as they were when first read -
		// a prefix made before its title phrase still believes it has none, and would leave the
		// phrase behind. Removal has to see the database as it is.
		\XF::em()->clearEntityCache();

		$jobs = new JobDrain();
		$jobs->start();

		do
		{
			// by cursor rather than by offset: a record that fails stays in the table, and must
			// not be offered again on the next pass
			$records = $db->fetchAll(
				'SELECT record_id, entity, entity_id, factory
					FROM ' . TableRecorder::TABLE . '
					WHERE batch = ? AND record_id < ?
					ORDER BY record_id DESC
					LIMIT ' . static::CHUNK,
				[$batch, $below]
			);

			// rows written in bulk are removed in bulk: a run of them is gathered until something
			// that is an entity comes along, so that everything still goes in recorded order
			$bulk = [];
			foreach ($records AS $record)
			{
				$below = (int) $record['record_id'];

				if ($this->isBulk($record))
				{
					if ($bulk && ($bulk[0]['factory'] !== $record['factory'] || $bulk[0]['entity'] !== $record['entity']))
					{
						$this->cleanBulk($bulk, $result);
						$bulk = [];
					}
					$bulk[] = $record;
					continue;
				}

				if ($bulk)
				{
					$this->cleanBulk($bulk, $result);
					$bulk = [];
				}
				$this->cleanRecord($record, $result);
			}

			if ($bulk)
			{
				$this->cleanBulk($bulk, $result);
			}
		}
		while ($records);

		$jobs->finish();

		return $result;
	}

	protected function isBulk(array $record): bool
	{
		return $record['factory'] !== null && is_subclass_of($record['factory'], AbstractBulkFactory::class);
	}

	/**
	 * Remove a run of rows one bulk factory wrote, and their records, together or not at all.
	 *
	 * @param array $records records sharing one factory and one entity
	 */
	protected function cleanBulk(array $records, array &$result): void
	{
		$entity = $records[0]['entity'];
		$factory = $records[0]['factory'];
		$db = \XF::db();
		$depth = self::transactionDepth($db);

		$db->beginTransaction();

		try
		{
			$keys = [];
			foreach ($records AS $record)
			{
				$keys[] = EntityKey::decode($record['entity_id']);
			}

			$removed = $factory::removeMany($entity, $keys);

			$db->delete(TableRecorder::TABLE, 'record_id IN (' . $db->quote(array_column($records, 'record_id')) . ')');
			$db->commit();
		}
		catch (\Throwable $e)
		{
			while (self::transactionDepth($db) > $depth)
			{
				$db->rollback();
			}

			$result['failed'][] = [
				'entity' => $entity,
				'id' => count($records) . ' rows',
				'error' => get_class($e) . ': ' . strtok($e->getMessage(), "\n"),
			];

			return;
		}

		if ($removed)
		{
			$result['removed'][$entity] = ($result['removed'][$entity] ?? 0) + $removed;
		}
		if ($removed < count($records))
		{
			$result['missing'][$entity] = ($result['missing'][$entity] ?? 0) + count($records) - $removed;
		}
	}

	/**
	 * Whether a removal failed only because another connection held the rows: a deadlock, or
	 * MySQL error 1205, a lock that was waited for and never came free.
	 */
	protected static function lostToAnother(\Throwable $e): bool
	{
		return $e instanceof DeadlockException
			|| ($e instanceof \XF\Db\Exception && strpos($e->getMessage(), '[1205]') !== false);
	}

	protected function cleanRecord(array $record, array &$result, int $attempt = 1): void
	{
		$shortName = $record['entity'];
		$db = \XF::db();
		$depth = self::transactionDepth($db);

		// the removal and the loss of its record happen together or not at all
		$db->beginTransaction();

		try
		{
			$entity = \XF::em()->find($shortName, EntityKey::decode($record['entity_id']));
			if ($entity)
			{
				$this->remove($entity, $record['factory']);

				// Some add-ons put off part of a delete until the request ends - the gallery's
				// recount of its owner's quota, for one. In a clean, the owner is gone by then.
				// So each removal is finished as its own request would have finished it.
				\XF::triggerRunOnce();
			}

			$db->delete(TableRecorder::TABLE, 'record_id = ?', $record['record_id']);
			$db->commit();
		}
		catch (\Throwable $e)
		{
			// An entity that throws part-way through its delete leaves the transaction it opened
			// open. Left like that, everything after it - every later removal in the run - would
			// be discarded when the command ended, having been reported as done.
			while (self::transactionDepth($db) > $depth)
			{
				$db->rollback();
			}

			// A deadlock, or a wait for a lock that ran out, is somebody else writing the same rows
			// at the same moment, and this removal has been undone whole - so it can be tried again. Only where
			// this was the outermost transaction: inside someone else's, the deadlock ended theirs.
			if (self::lostToAnother($e) && $depth === 0 && $attempt < self::ATTEMPTS)
			{
				\XF::em()->clearEntityCache();
				$this->cleanRecord($record, $result, $attempt + 1);

				return;
			}

			// the record stays, so a later run can try again
			$result['failed'][] = [
				'entity' => $shortName,
				'id' => $record['entity_id'],
				'error' => get_class($e) . ': ' . strtok($e->getMessage(), "\n"),
			];

			return;
		}

		$outcome = $entity ? 'removed' : 'missing';
		$result[$outcome][$shortName] = ($result[$outcome][$shortName] ?? 0) + 1;
	}

	/**
	 * How many transactions and savepoints are open. The adapter keeps its savepoints to itself,
	 * and there is no other way to get back to exactly where a failed removal started.
	 */
	private static function transactionDepth(AbstractAdapter $db): int
	{
		if (!$db->inTransaction())
		{
			return 0;
		}

		$savePoints = new \ReflectionProperty(AbstractAdapter::class, 'savePoints');
		$savePoints->setAccessible(true);

		return 1 + count($savePoints->getValue($db));
	}

	/**
	 * @param string|null $factory the class of the factory that made the entity, if one did
	 */
	protected function remove(Entity $entity, ?string $factory): void
	{
		// the factory that made it knows what it leaves behind; anything else is a plain delete
		if ($factory !== null && is_subclass_of($factory, AbstractFactory::class))
		{
			$factory::remove($entity);

			return;
		}

		AbstractFactory::silence($entity);
		$entity->delete();
	}
}
