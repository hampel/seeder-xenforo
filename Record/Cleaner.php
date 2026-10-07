<?php namespace Hampel\Seeder\Record;

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
	protected const CHUNK = 250;

	/**
	 * @return array ['removed' => [shortName => int], 'missing' => [shortName => int],
	 *                'failed' => [['entity' => string, 'id' => string, 'error' => string], ...]]
	 */
	public function clean(string $batch): array
	{
		$result = ['removed' => [], 'missing' => [], 'failed' => []];
		$db = \XF::db();
		$below = PHP_INT_MAX;

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

			foreach ($records AS $record)
			{
				$below = (int) $record['record_id'];
				$this->cleanRecord($record, $result);
			}
		}
		while ($records);

		return $result;
	}

	protected function cleanRecord(array $record, array &$result): void
	{
		$shortName = $record['entity'];

		try
		{
			$entity = \XF::em()->find($shortName, EntityKey::decode($record['entity_id']));
			if ($entity)
			{
				$this->remove($entity, $record['factory']);
			}
		}
		catch (\Throwable $e)
		{
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

		\XF::db()->delete(TableRecorder::TABLE, 'record_id = ?', $record['record_id']);
	}

	/**
	 * @param string|null $factory the class of the factory that made the entity, if one did
	 */
	protected function remove(Entity $entity, ?string $factory): void
	{
		$entity->delete();
	}
}
