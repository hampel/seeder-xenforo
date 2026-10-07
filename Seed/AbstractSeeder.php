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

	final public function __construct(array $params = [])
	{
		$this->params = $params;
	}

	abstract public function run();

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
	 * @return mixed
	 */
	protected function param(string $name, $default = null)
	{
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
}
