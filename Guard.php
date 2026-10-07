<?php namespace Hampel\Seeder;

use XF\PrintableException;

/**
 * Seeding is refused unless this forum's own config.php switches it on:
 *
 *     $config['hampelSeeder']['enabled'] = true;
 *
 * The flag lives on the forum rather than with whoever runs the command, so a forum that never had
 * the line cannot be seeded by a mistyped path. Only a literal `true` counts: a truthy string is
 * what a half-edited config file holds.
 *
 * Every command that writes calls assertEnabled() first. Nothing in Factory/, Seed/ or Faker/ may
 * call this class - see CLAUDE.md, "Two halves".
 */
class Guard
{
	public static function isEnabled(): bool
	{
		return Config::get('enabled') === true;
	}

	/**
	 * @throws PrintableException
	 */
	public static function assertEnabled(): void
	{
		if (!self::isEnabled())
		{
			throw new PrintableException(self::refusal());
		}
	}

	public static function refusal(): string
	{
		return "Seeding is switched off on this forum. To allow it, add this line to src/config.php: "
			. "\$config['" . Config::KEY . "']['enabled'] = true;";
	}
}
