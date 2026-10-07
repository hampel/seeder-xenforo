<?php namespace Hampel\Seeder;

/**
 * This add-on's block in config.php: `$config['hampelSeeder']`. Everything it reads is here and
 * nowhere else - there are no options, because a setting an administrator could change from the
 * control panel is one a copy of a live forum would bring with it.
 */
class Config
{
	public const KEY = 'hampelSeeder';

	public const DEFAULT_LOCALE = 'en_AU';

	public static function all(): array
	{
		$config = \XF::config(self::KEY);

		return is_array($config) ? $config : [];
	}

	/**
	 * @return mixed|null
	 */
	public static function get(string $key)
	{
		return self::all()[$key] ?? null;
	}

	public static function has(string $key): bool
	{
		return array_key_exists($key, self::all());
	}

	public static function locale(): string
	{
		$locale = self::get('locale');

		return is_string($locale) && $locale !== '' ? $locale : self::DEFAULT_LOCALE;
	}
}
