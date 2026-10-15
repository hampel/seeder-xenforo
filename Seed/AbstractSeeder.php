<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Context;

/**
 * A recipe for a set of content, in the manner of a Laravel seeder: run() builds it, usually with
 * factories, and call() runs other seeders.
 *
 * Unlike a Laravel seeder it takes parameters, so the same recipe can be asked for 20 members or
 * 2,000 without a new class.
 */
abstract class AbstractSeeder
{
	/** @var array */
	protected $params;

	/** @var array<string, true> every parameter name any seeder has asked for since the last reset */
	private static $asked = [];

	final public function __construct(array $params = [])
	{
		$this->params = $params;
	}

	abstract public function run();

	/**
	 * Anything whoever runs this should be told before it starts - what it will set off
	 * elsewhere on this forum, say. Printed before anything is created.
	 *
	 * @return string[]
	 */
	public function notices(): array
	{
		return [];
	}

	/**
	 * Run other seeders. Each gets this seeder's parameters, with the ones given here on top.
	 *
	 * @param string|string[] $seeders class names
	 */
	public function call($seeders, array $params = []): void
	{
		foreach ((array) $seeders AS $class)
		{
			if (!is_subclass_of($class, self::class))
			{
				throw new \InvalidArgumentException("{$class} is not a seeder");
			}

			(new $class($params + $this->params))->run();
		}
	}

	/**
	 * Which of these parameter names no seeder has asked for since the last call. A name nobody
	 * asked for had no effect on what was made - mistyped, or not one this seeder takes - and
	 * whoever gave it should be told.
	 *
	 * @param string[] $names
	 *
	 * @return string[]
	 */
	public static function neverAskedFor(array $names): array
	{
		$unused = array_values(array_diff($names, array_keys(self::$asked)));
		self::$asked = [];

		return $unused;
	}

	/**
	 * @return mixed
	 */
	protected function param(string $name, $default = null)
	{
		self::$asked[$name] = true;

		return $this->params[$name] ?? $default;
	}

	/**
	 * A parameter that has to be a whole number of things to make.
	 */
	protected function count(string $name, int $default): int
	{
		$value = $this->param($name, $default);
		if (!is_int($value) && !ctype_digit((string) $value))
		{
			throw new \InvalidArgumentException("The '{$name}' parameter must be a whole number, not '{$value}'");
		}

		return (int) $value;
	}

	protected function context(): Context
	{
		return Context::current();
	}

	/**
	 * The forum a `forum` parameter names, or an exception saying what the node is instead.
	 *
	 * @param mixed $nodeId
	 */
	protected function existingForum($nodeId): \XF\Entity\Forum
	{
		$forum = \XF::em()->find('XF:Forum', $nodeId);
		if ($forum)
		{
			return $forum;
		}

		$node = \XF::em()->find('XF:Node', $nodeId);
		if (!$node)
		{
			throw new \InvalidArgumentException("The 'forum' parameter must be the node id of a forum; there is no node {$nodeId}");
		}

		throw new \InvalidArgumentException("The 'forum' parameter must be the node id of a forum; node {$nodeId} is "
			. ($node->node_type_id === 'Forum' ? 'a forum node with no forum record behind it' : "a {$node->node_type_id}, not a forum"));
	}
}
