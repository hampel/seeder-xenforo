<?php namespace Hampel\Seeder\Record;

use Hampel\Seeder\Recorder;
use XF\Mvc\Entity\Entity;

/**
 * Records each entity as a row in xf_hampel_seeder_record, under one batch name, so that
 * seeder:clean can remove exactly what a run created.
 */
class TableRecorder implements Recorder
{
	public const TABLE = 'xf_hampel_seeder_record';

	/** @var string */
	protected $batch;

	public function __construct(string $batch)
	{
		if (!self::isValidBatchName($batch))
		{
			throw new \InvalidArgumentException(
				"'{$batch}' is not a batch name: use 1 to 50 letters, digits, dots, hyphens or underscores"
			);
		}

		$this->batch = $batch;
	}

	public static function isValidBatchName(string $batch): bool
	{
		return (bool) preg_match('/^[A-Za-z0-9._-]{1,50}$/', $batch);
	}

	public function getBatch(): string
	{
		return $this->batch;
	}

	/**
	 * A plain insert rather than an entity save: this runs once per seeded entity. The date is the
	 * real one - a factory moves XenForo's clock while it saves, and a record is not content.
	 *
	 * Recording the same entity twice is ignored. It stays in the batch that recorded it first.
	 */
	public function record(Entity $entity, ?string $factory = null): void
	{
		\XF::db()->insert(self::TABLE, [
			'batch' => $this->batch,
			'entity' => $entity->structure()->shortName,
			'entity_id' => EntityKey::of($entity),
			'factory' => $factory,
			'record_date' => time(),
		], false, false, 'IGNORE');
	}
}
