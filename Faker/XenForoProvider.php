<?php namespace Hampel\Seeder\Faker;

use Faker\Provider\Base;

/**
 * What stock Faker gets wrong for a forum.
 */
class XenForoProvider extends Base
{
	/**
	 * A member name of the kinds a forum actually has - not Faker's userName(), which is always
	 * `first.last`. Letters, digits, spaces, dots, hyphens and underscores only.
	 *
	 * @param int $max the longest name the forum allows; XenForo's default is 50
	 */
	public function forumUsername(int $max = 25): string
	{
		$faker = $this->generator;

		switch (self::numberBetween(1, 6))
		{
			case 1: $name = $faker->firstName() . self::numberBetween(1, 99); break;
			case 2: $name = $faker->firstName() . ' ' . $faker->lastName(); break;
			case 3: $name = strtolower($faker->firstName() . '.' . $faker->lastName()); break;
			case 4: $name = ucfirst($faker->word()) . ucfirst($faker->word()); break;
			case 5: $name = strtolower($faker->word() . '_' . $faker->word()) . self::numberBetween(1, 999); break;
			default: $name = $faker->lastName() . self::numberBetween(1950, 2010);
		}

		$name = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', self::toAscii($name)));

		$name = rtrim(substr($name, 0, max(3, $max)), ' ._-');

		return strlen($name) < 3 ? $name . self::numberBetween(100, 999) : $name;
	}

	private static function toAscii(string $text): string
	{
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

		return $ascii === false ? $text : $ascii;
	}
}
