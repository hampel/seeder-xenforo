<?php namespace Hampel\Seeder;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Hampel\Seeder\Faker\XenForoProvider;

/**
 * What every factory and seeder in one run shares: the generator, where to record what is made,
 * the window content is dated across, and the password seeded members are given.
 *
 * A run installs its own with Context::use(). Without one, current() supplies a default that
 * records nothing - which suits a test that rolls its work back, and nothing else. Used anywhere
 * else, it says so once: see UnbatchedRecorder.
 */
class Context
{
	public const DEFAULT_LOCALE = 'en_US';

	/** @var Generator */
	public $faker;

	/** @var Recorder */
	public $recorder;

	/** @var Timeline */
	public $timeline;

	/** @var string */
	public $password;

	/** @var self|null */
	private static $current;

	public function __construct(Generator $faker, Recorder $recorder, Timeline $timeline, string $password)
	{
		$this->faker = $faker;
		$this->recorder = $recorder;
		$this->timeline = $timeline;
		$this->password = $password;
	}

	/**
	 * A generator for the locale with this add-on's provider attached. With a seed, everything it
	 * - and the timeline - produces is the same on every run.
	 */
	public static function faker(string $locale = self::DEFAULT_LOCALE, ?int $seed = null): Generator
	{
		$faker = FakerFactory::create($locale);
		$faker->addProvider(new XenForoProvider($faker));
		if ($seed !== null)
		{
			// A Faker generator reseeds mt_rand() at random from its destructor, and holds a cycle
			// with its providers, so an old one is destroyed whenever the collector next runs -
			// which would be part-way through this run. Collect them now, before seeding.
			gc_collect_cycles();
			$faker->seed($seed);
		}

		return $faker;
	}

	public static function current(): self
	{
		if (!self::$current)
		{
			self::$current = new self(
				self::faker(),
				new UnbatchedRecorder(),
				Timeline::span(Timeline::DEFAULT_SPAN),
				'password'
			);
		}

		return self::$current;
	}

	/**
	 * Runs something while XenForo's request is one made from these server values, and puts the
	 * real one back - for code that reads the visitor's address, user agent or headers, which in a
	 * command are the command's own.
	 *
	 *     Context::withRequest(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => $agent], function () { ... });
	 *
	 * **`REQUEST_METHOD` is GET unless given**: without one, XenForo's request reports no method at
	 * all. Nothing else is filled in, and the request carries no input, files or cookies.
	 *
	 * @param array<string, string> $server values as PHP's `$_SERVER` holds them
	 *
	 * @return mixed whatever the callback returned
	 */
	public static function withRequest(array $server, callable $callback)
	{
		$app = \XF::app();
		$container = $app->container();
		// the request already made, so whatever holds it still has the one in use afterwards
		$real = $container->isCached('request') ? $container['request'] : $container->getOriginal('request');

		$container['request'] = new \XF\Http\Request($app->inputFilterer(), [], [], [], $server + ['REQUEST_METHOD' => 'GET']);

		try
		{
			return $callback();
		}
		finally
		{
			$container['request'] = $real;
		}
	}

	/**
	 * @return self|null the context this one replaced, to hand back to use() afterwards
	 */
	public static function use(?self $context): ?self
	{
		$previous = self::$current;
		self::$current = $context;

		return $previous;
	}
}
