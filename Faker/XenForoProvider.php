<?php namespace Hampel\Seeder\Faker;

use Faker\Provider\Base;

/**
 * What stock Faker gets wrong for a forum.
 */
class XenForoProvider extends Base
{
	/**
	 * Real crawlers' user agents, commonest first. A forum's robot list decides which of them it
	 * recognises.
	 */
	private const CRAWLERS = [
		'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.116 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
		'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
		'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
		'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0.6045.214 Safari/537.36',
		'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
		'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
		'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
		'Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
		'Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)',
		'Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot; help@moz.com)',
		'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
		'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
		'CCBot/2.0 (https://commoncrawl.org/faq/)',
	];

	/** @var array|null the robot list robotFor() last compiled */
	private $robotList;

	/** @var string */
	private $robotPattern = '';

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

	/**
	 * An address from the ranges set aside for documentation - 192.0.2.0/24, 198.51.100.0/24,
	 * 203.0.113.0/24 and 2001:db8::/32 - which no visitor can come from, so a seeded address never
	 * matches a real one. With 762 addresses in the three IPv4 ranges, a run of any size draws the
	 * same one twice.
	 *
	 * @param float $ipv6 the share that are IPv6
	 */
	public function documentationIp(float $ipv6 = 0.1): string
	{
		if (mt_rand() / mt_getrandmax() < $ipv6)
		{
			return sprintf('2001:db8:%x:%x::%x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(1, 0xffff));
		}

		return ['192.0.2', '198.51.100', '203.0.113'][mt_rand(0, 2)] . '.' . mt_rand(1, 254);
	}

	/**
	 * The user agent of a real crawler that this forum recognises as a robot - commonest first,
	 * as a forum sees them. Only agents the forum's robot list matches are drawn, and that list is
	 * one other add-ons can extend, so which robot an agent is depends on the forum.
	 *
	 * @param string|null $robot only an agent this forum takes for this robot, by its key: `google`,
	 *                           `bing` on XenForo's own list
	 */
	public function crawlerUserAgent(?string $robot = null): string
	{
		$agents = [];
		foreach (self::CRAWLERS AS $agent)
		{
			$key = $this->robotFor($agent);
			if ($key !== '' && ($robot === null || $key === $robot))
			{
				$agents[] = $agent;
			}
		}

		if (!$agents)
		{
			throw new \InvalidArgumentException($robot === null
				? 'This forum takes none of the crawlers Seeder knows for a robot'
				: "Seeder knows no crawler this forum takes for the robot '{$robot}'");
		}

		// the first few far more often than the rest
		return $agents[(int) floor(count($agents) * (mt_rand() / (mt_getrandmax() + 1)) ** 2.5)];
	}

	/**
	 * A browser's user agent that this forum does not take for a robot. Faker's own are browsers,
	 * but a forum's robot list can be extended to match almost anything, so one it would match is
	 * drawn again.
	 */
	public function browserUserAgent(): string
	{
		for ($tries = 0; $tries < 100; $tries++)
		{
			$agent = $this->generator->userAgent();
			if ($this->robotFor($agent) === '')
			{
				return $agent;
			}
		}

		throw new \RuntimeException("This forum's robot list matches every browser user agent Faker drew");
	}

	/**
	 * The key of the robot this forum takes a user agent for, or an empty string - matched as
	 * XenForo matches it, against the list as installed add-ons have extended it. Nothing is
	 * written, which asking XenForo itself may do where an add-on extends the check.
	 */
	public function robotFor(string $userAgent): string
	{
		$list = \XF::app()->data('XF:Robot')->getRobotUserAgents();
		if ($this->robotList !== $list)
		{
			$this->robotList = $list;
			$this->robotPattern = '#(' . implode('|', array_map(function ($fragment)
			{
				return preg_quote($fragment, '#');
			}, array_keys($list))) . ')#i';
		}

		return $list && preg_match($this->robotPattern, strtolower($userAgent), $match) ? $list[$match[1]] : '';
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
