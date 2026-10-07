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

	/**
	 * @return array [shortName => [['id' => ..., ...], ...]] in the order things were created
	 */
	public function created(): array
	{
		return $this->created;
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
