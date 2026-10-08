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

	/**
	 * A thread title in English, not lorem ipsum: a line of real prose with its ends tidied.
	 */
	public function threadTitle(): string
	{
		$text = $this->generator->realTextBetween(25, 70);
		$text = preg_replace(['/["\x27()]|--/', '/\s+/'], [' ', ' '], $text);
		$text = trim($text, " \t\n.,;:!?-");

		return ucfirst($text);
	}

	/**
	 * A post in BB code, in English prose.
	 *
	 * @param string[] $parts what to include besides a paragraph or two: short, long, quote, list,
	 *                        image, links, formatting
	 */
	public function bbCodeMessage(array $parts = []): string
	{
		$faker = $this->generator;
		$paragraphs = in_array('long', $parts) ? self::numberBetween(8, 14) : (in_array('short', $parts) ? 1 : self::numberBetween(2, 3));

		$blocks = [];
		for ($i = 0; $i < $paragraphs; $i++)
		{
			$blocks[] = $this->prose(self::numberBetween(120, 420));
		}

		foreach ($parts AS $part)
		{
			switch ($part)
			{
				case 'quote':
					array_unshift($blocks, '[QUOTE="' . $faker->firstName() . '"]' . $this->prose(self::numberBetween(80, 200)) . '[/QUOTE]');
					break;

				case 'list':
					$items = [];
					for ($i = self::numberBetween(3, 6); $i > 0; $i--)
					{
						$items[] = '[*]' . $this->prose(self::numberBetween(30, 80));
					}
					$blocks[] = "[LIST]\n" . implode("\n", $items) . "\n[/LIST]";
					break;

				case 'image':
					$blocks[] = '[IMG]https://example.com/images/' . self::numberBetween(1000, 9999) . '.png[/IMG]';
					break;

				case 'links':
					// always with link text: a bare address is turned into a preview, which XenForo fetches
					$blocks[] = 'See [URL=\'https://example.com/' . $faker->slug(3) . '\']' . $faker->words(3, true)
						. '[/URL] and [URL=\'https://example.org/' . $faker->slug(2) . '\']' . $faker->words(2, true) . '[/URL].';
					break;

				case 'formatting':
					array_unshift($blocks, '[HEADING=2]' . ucfirst($faker->words(3, true)) . '[/HEADING]');
					$blocks[] = '[B]' . $this->prose(40) . '[/B] [I]' . $this->prose(40) . '[/I]';
					break;
			}
		}

		return implode("\n\n", $blocks);
	}

	private function prose(int $length): string
	{
		return trim(preg_replace('/\s+/', ' ', $this->generator->realText(max(20, $length))));
	}

	private static function toAscii(string $text): string
	{
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

		return $ascii === false ? $text : $ascii;
	}
}
