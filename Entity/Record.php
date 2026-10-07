<?php namespace Hampel\Seeder\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $record_id
 * @property string $batch
 * @property string $entity
 * @property string $entity_id
 * @property string|null $factory
 * @property int $record_date
 */
class Record extends Entity
{
	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_hampel_seeder_record';
		$structure->shortName = 'Hampel\Seeder:Record';
		$structure->primaryKey = 'record_id';
		$structure->columns = [
			'record_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'batch' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
			'entity' => ['type' => self::STR, 'maxLength' => 100, 'required' => true],
			'entity_id' => ['type' => self::STR, 'maxLength' => 100, 'required' => true],
			'factory' => ['type' => self::STR, 'maxLength' => 150, 'nullable' => true, 'default' => null],
			'record_date' => ['type' => self::UINT, 'default' => \XF::$time],
		];

		return $structure;
	}
}
