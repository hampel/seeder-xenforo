<?php namespace Hampel\Seeder;

use Hampel\Seeder\Factory\AbstractFactory;
use XF\Mvc\Entity\Entity;

/**
 * Passes every record on to another recorder, and keeps a list of what went past so a run can
 * report what it created.
 */
class CollectingRecorder implements Recorder
{
	/** @var Recorder */
	protected $inner;

	/** @var array [shortName => [description, ...]] */
	protected $created = [];

	/** @var array [shortName => int] rows written in bulk, which are counted and not listed */
	protected $bulk = [];

	public function __construct(Recorder $inner)
	{
		$this->inner = $inner;
	}

	public function record(Entity $entity, ?string $factory = null): void
	{
		$this->inner->record($entity, $factory);

		$description = ['id' => $this->id($entity)];
		if ($factory !== null && is_subclass_of($factory, AbstractFactory::class))
		{
			$description += $factory::describe($entity);
		}

		$this->created[$entity->structure()->shortName][] = $description;
	}

	public function recordRows(string $entity, array $keys, ?string $factory = null): void
	{
		$this->inner->recordRows($entity, $keys, $factory);

		$this->bulk[$entity] = ($this->bulk[$entity] ?? 0) + count($keys);
	}

	/**
	 * @return array [shortName => [['id' => ..., ...], ...]] in the order things were created.
	 *               Rows written in bulk are not here; see counts().
	 */
	public function created(): array
	{
		return $this->created;
	}

	/**
	 * @return array [shortName => int] how many of each kind were created, bulk rows included
	 */
	public function counts(): array
	{
		$counts = [];
		foreach ($this->created AS $entity => $list)
		{
			$counts[$entity] = count($list);
		}
		foreach ($this->bulk AS $entity => $count)
		{
			$counts[$entity] = ($counts[$entity] ?? 0) + $count;
		}

		return $counts;
	}

	/**
	 * @return int|string|array
	 */
	protected function id(Entity $entity)
	{
		$values = $entity->getIdentifierValues() ?: [];

		return count($values) === 1 ? reset($values) : $values;
	}
}
