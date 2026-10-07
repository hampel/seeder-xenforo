<?php namespace Hampel\Seeder\Repository;

use Hampel\Seeder\Record\TableRecorder;
use XF\Mvc\Entity\Repository;

class RecordRepository extends Repository
{
	public function tableExists(): bool
	{
		return $this->db()->getSchemaManager()->tableExists(TableRecorder::TABLE);
	}

	public function batchExists(string $batch): bool
	{
		return (bool) $this->db()->fetchOne(
			'SELECT 1 FROM ' . TableRecorder::TABLE . ' WHERE batch = ? LIMIT 1',
			$batch
		);
	}

	/**
	 * Every batch, oldest first, with what it holds:
	 *
	 *     [batch => ['records' => int, 'first' => int, 'last' => int, 'entities' => [shortName => int]]]
	 */
	public function getBatches(): array
	{
		$rows = $this->db()->fetchAll(
			'SELECT batch, entity, COUNT(*) AS records, MIN(record_id) AS first_id,
					MIN(record_date) AS first_date, MAX(record_date) AS last_date
				FROM ' . TableRecorder::TABLE . '
				GROUP BY batch, entity
				ORDER BY first_id'
		);

		$batches = [];
		foreach ($rows AS $row)
		{
			$name = $row['batch'];
			if (!isset($batches[$name]))
			{
				$batches[$name] = [
					'records' => 0,
					'first' => (int) $row['first_date'],
					'last' => (int) $row['last_date'],
					'entities' => [],
				];
			}

			$batches[$name]['records'] += (int) $row['records'];
			$batches[$name]['first'] = min($batches[$name]['first'], (int) $row['first_date']);
			$batches[$name]['last'] = max($batches[$name]['last'], (int) $row['last_date']);
			$batches[$name]['entities'][$row['entity']] = (int) $row['records'];
		}

		return $batches;
	}
}
