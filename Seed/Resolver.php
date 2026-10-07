<?php namespace Hampel\Seeder\Seed;

/**
 * Turns the name a seeder is asked for by into its class.
 *
 *     Default                    Hampel\Seeder\Seed\DefaultSeeder
 *     User                       Hampel\Seeder\Seed\UserSeeder
 *     Vendor/AddOn:Thing         Vendor\AddOn\Seed\ThingSeeder
 *     Vendor\AddOn\Seed\Thing    as given, if it is a seeder
 *
 * So any add-on can ship seeders for its own content in its Seed directory.
 */
class Resolver
{
	public const DEFAULT_SEEDER = 'Default';

	/**
	 * @return string the class name
	 *
	 * @throws \InvalidArgumentException when the name does not lead to a seeder
	 */
	public static function resolve(string $name): string
	{
		if (preg_match('/^([A-Za-z0-9]+(?:\/[A-Za-z0-9]+)?):([A-Za-z0-9]+)$/', $name, $match))
		{
			$class = str_replace('/', '\\', $match[1]) . '\\Seed\\' . $match[2] . 'Seeder';
		}
		else if (preg_match('/^[A-Za-z0-9]+$/', $name))
		{
			$class = __NAMESPACE__ . '\\' . $name . 'Seeder';
		}
		else
		{
			$class = ltrim($name, '\\');
		}

		if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) || !class_exists($class))
		{
			throw new \InvalidArgumentException("There is no seeder named '{$name}' - looked for the class {$class}");
		}
		if (!is_subclass_of($class, AbstractSeeder::class))
		{
			throw new \InvalidArgumentException("{$class} is not a seeder: it does not extend " . AbstractSeeder::class);
		}

		return $class;
	}
}
