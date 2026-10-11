<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Clock;
use Hampel\Seeder\Context;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Entity;

/**
 * Builds one kind of content, in the manner of a Laravel model factory: definition() gives the
 * defaults, state() and sequence() vary them, count() asks for several, recycle(), for() and has()
 * relate one kind to another, make() builds without saving and create() saves.
 *
 * Unlike a Laravel factory, this builds a content unit rather than a table row - a member is four
 * tables, a thread is a thread and its first post - so a subclass may override newEntity() and
 * store() to say how its entity is really put together and written.
 *
 * create() does three things no subclass has to remember: it switches the entity's webhooks off,
 * it saves with XenForo's clock moved to the entity's own date, and it tells the recorder.
 *
 * Every modifier returns a changed copy, so a factory can be configured once and reused.
 */
abstract class AbstractFactory
{
	/** @var string the entity's short name, such as XF:User */
	protected $entity;

	/** @var int|int[]|null a number, or a [fewest, most] range drawn from each time */
	protected $count;

	/** @var array [shortName => Entity[]] existing entities to use where a factory would be created */
	protected $recycled = [];

	/** @var array arrays, or closures given the attributes so far and returning an array */
	protected $states = [];

	/** @var \Closure[] */
	protected $afterMaking = [];

	/** @var \Closure[] */
	protected $afterCreating = [];

	final public function __construct()
	{
	}

	/**
	 * @return static
	 */
	public static function new(array $state = [])
	{
		$factory = new static();

		return $state ? $factory->state($state) : $factory;
	}

	/**
	 * The default attributes. A value may be a closure, which is given the other attributes and
	 * returns the value, or another factory, which is created and replaced by its entity's id.
	 * A key written `Relation.column` sets a column on a related entity - see fill().
	 */
	abstract public function definition();

	/**
	 * @param array|\Closure $state attributes to override, or a closure given the attributes so
	 *                              far and returning them
	 *
	 * @return static
	 */
	public function state($state)
	{
		$factory = clone $this;
		$factory->states[] = $state;

		return $factory;
	}

	/**
	 * Cycle through the given states, one per entity built. A closure is given the index.
	 *
	 * @param array|\Closure ...$sequence
	 *
	 * @return static
	 */
	public function sequence(...$sequence)
	{
		$index = 0;

		return $this->state(function () use (&$index, $sequence)
		{
			$state = $sequence[$index % count($sequence)];
			$state = $state instanceof \Closure ? $state($index) : $state;
			$index++;

			return $state;
		});
	}

	/**
	 * @param int|int[]|null $count how many to build, or a [fewest, most] range - drawn afresh each
	 *                              time, so each parent in has() gets its own number of children
	 *
	 * @return static
	 */
	public function count($count)
	{
		if (is_array($count) && (count($count) !== 2 || $count[0] > $count[1]))
		{
			throw new \InvalidArgumentException('A count range is [fewest, most]');
		}

		$factory = clone $this;
		$factory->count = $count;

		return $factory;
	}

	/**
	 * Use these existing entities wherever this factory, or one it creates, would otherwise create
	 * one of the same kind - authors drawn from a pool of members, say. One is picked at random
	 * each time.
	 *
	 * @param Entity|iterable $entities
	 *
	 * @return static
	 */
	public function recycle($entities)
	{
		$factory = clone $this;
		foreach ($entities instanceof Entity ? [$entities] : $entities AS $entity)
		{
			$factory->recycled[$entity->structure()->shortName][] = $entity;
		}

		return $factory;
	}

	/**
	 * Make everything built belong to this one entity - every thread in this forum.
	 *
	 * @return static
	 */
	public function for(Entity $entity)
	{
		$factory = clone $this;
		$factory->recycled[$entity->structure()->shortName] = [$entity];

		return $factory;
	}

	/**
	 * After each entity is created, create these belonging to it - replies for each thread. The
	 * child factory inherits whatever this one was given to recycle.
	 *
	 * @return static
	 */
	public function has(self $children)
	{
		return $this->afterCreating(function (Entity $parent, self $factory) use ($children)
		{
			$children = clone $children;
			$children->recycled += $factory->recycled;

			$children->for($parent)->create();
		});
	}

	/**
	 * @return static
	 */
	public function afterMaking(\Closure $callback)
	{
		$factory = clone $this;
		$factory->afterMaking[] = $callback;

		return $factory;
	}

	/**
	 * @return static
	 */
	public function afterCreating(\Closure $callback)
	{
		$factory = clone $this;
		$factory->afterCreating[] = $callback;

		return $factory;
	}

	/**
	 * The attributes one entity would be built from: the definition, then each state in the order
	 * it was added, then the overrides given here.
	 */
	public function raw(array $state = []): array
	{
		$attributes = $this->definition();

		foreach (array_merge($this->states, [$state]) AS $apply)
		{
			if ($apply instanceof \Closure)
			{
				$apply = $apply($attributes);
			}

			$attributes = array_merge($attributes, (array) $apply);
		}

		foreach ($attributes AS $key => $value)
		{
			if ($value instanceof \Closure)
			{
				$attributes[$key] = $value = $value($attributes);
			}

			if ($value instanceof self)
			{
				$attributes[$key] = $this->related($value)->getEntityId();
			}
		}

		return $attributes;
	}

	/**
	 * Build without saving.
	 *
	 * @return Entity|ArrayCollection one entity, or a collection of them when count() was given
	 */
	public function make(array $state = [])
	{
		return $this->build($state, false);
	}

	/**
	 * Build, save and record.
	 *
	 * @return Entity|ArrayCollection one entity, or a collection of them when count() was given
	 */
	public function create(array $state = [])
	{
		return $this->build($state, true);
	}

	/**
	 * How a run reports an entity this factory made, beyond its id.
	 */
	public static function describe(Entity $entity): array
	{
		return [];
	}

	/**
	 * Remove an entity this factory made, leaving nothing behind. seeder:clean calls this for
	 * every record that names the factory.
	 */
	public static function remove(Entity $entity): void
	{
		static::silence($entity);
		$entity->delete();
	}

	/**
	 * Nothing a seeded entity does may leave the forum, or be logged as someone's action.
	 */
	public static function silence(Entity $entity): void
	{
		if ($entity->hasBehavior('XF:Webhook'))
		{
			$entity->getBehavior('XF:Webhook')->setOption('enabled', false);
		}

		// and nothing it does is a moderator's action: creating or removing seeded content must
		// not write the moderator log
		if (array_key_exists('log_moderator', $entity->structure()->options))
		{
			$entity->setOption('log_moderator', false);
		}
	}

	/**
	 * The entity to use where the definition names another factory: one of the recycled ones if
	 * there are any of that kind, otherwise a new one - which inherits the recycled entities too.
	 */
	protected function related(self $factory): Entity
	{
		$pool = $this->recycled[$factory->entity] ?? [];
		if ($pool)
		{
			return $pool[mt_rand(0, count($pool) - 1)];
		}

		$factory = clone $factory;
		$factory->recycled += $this->recycled;

		return $factory->count(null)->create();
	}

	protected function context(): Context
	{
		return Context::current();
	}

	protected function build(array $state, bool $persist)
	{
		if ($this->count === null)
		{
			return $this->buildOne($state, $persist);
		}

		$count = is_array($this->count) ? mt_rand($this->count[0], $this->count[1]) : $this->count;

		$entities = [];
		for ($i = 0; $i < $count; $i++)
		{
			$entities[] = $this->buildOne($state, $persist);
		}

		return new ArrayCollection($entities);
	}

	protected function buildOne(array $state, bool $persist): Entity
	{
		$entity = $this->newEntity($this->raw($state));

		foreach ($this->afterMaking AS $callback)
		{
			$callback($entity, $this);
		}

		if (!$persist)
		{
			return $entity;
		}

		static::silence($entity);
		Clock::at($this->clockFor($entity) ?? \XF::$time, function () use ($entity)
		{
			$this->store($entity);
		});
		self::forgetCascade($entity);
		$this->context()->recorder->record($entity, static::class);

		foreach ($this->afterCreating AS $callback)
		{
			$callback($entity, $this);
		}

		$this->finish($entity);

		return $entity;
	}

	protected function newEntity(array $attributes): Entity
	{
		$entity = \XF::em()->create($this->entity);
		$this->fill($entity, $attributes);

		return $entity;
	}

	/**
	 * Set the attributes on the entity. A key written `Relation.column` is set on that related
	 * entity instead - `Option.receive_admin_email` on a member - which is created if need be and
	 * saved along with this one.
	 */
	protected function fill(Entity $entity, array $attributes): void
	{
		$own = [];
		foreach ($attributes AS $key => $value)
		{
			if (strpos($key, '.') === false)
			{
				$own[$key] = $value;
				continue;
			}

			[$relation, $column] = explode('.', $key, 2);
			$entity->getRelationOrDefault($relation)->set($column, $value);
		}

		$entity->bulkSet($own);
	}

	protected function store(Entity $entity): void
	{
		$entity->save();
	}

	/**
	 * Forget what the entity was told to save along with itself, now that it has been saved.
	 *
	 * XenForo's creators save a thread with its first post, a conversation with its first message,
	 * a member with their option and profile rows. Later XenForo releases drop that link once the
	 * save is done; 2.3.0 keeps it for the life of the entity. A run goes on using the same entity
	 * - so there, saving a thread again saved its first post again, and a reaction to that post,
	 * which saves the post and through it the thread, went round in a circle and threw.
	 */
	private static function forgetCascade(Entity $entity): void
	{
		static $cascade;
		if (!$cascade)
		{
			$cascade = new \ReflectionProperty(Entity::class, '_cascadeSave');
			$cascade->setAccessible(true);
		}

		$cascade->setValue($entity, []);
	}

	/**
	 * The last thing done to a created entity, after everything has() gave it exists - for what
	 * happens to content once it has had its replies and reactions, such as being deleted.
	 */
	protected function finish(Entity $entity): void
	{
	}

	/**
	 * The moment this entity is from, which XenForo's clock is moved to while it is saved. Null
	 * leaves the clock alone.
	 */
	protected function clockFor(Entity $entity): ?int
	{
		return null;
	}
}
