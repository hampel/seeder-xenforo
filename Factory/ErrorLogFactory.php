<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * An entry in the server error log, written as data. Nothing is thrown and nothing fails: the
 * forum does not log a real error, it gains a row that reads like one.
 *
 * By default each entry is drawn from a pool of made-up faults - in installed add-ons, in
 * XenForo itself, in a template - with a few of them accounting for most entries, as in a real
 * log. Each fault comes mostly from where it would: a job's class from jobs, a template from a
 * page view, and now and then from somewhere else.
 *
 * Besides the entry's columns, the attributes are:
 *
 *     signature   the fault: [type, file, line, message, class, method, usual origin, how usual]
 *     origin      web, cli, job or unknown - which decides the request state and the trace
 *
 * **No entry is ever dated inside the quiet period before now** (two hours unless set). Things
 * that report on this log watch for recent entries, and a seeded one must not look recent: an
 * add-on that posts new errors to a chat channel would post every one. Older than the quiet
 * period, they are history. This is enforced when the entry is saved, whatever date was asked for.
 */
class ErrorLogFactory extends AbstractFactory
{
	protected $entity = 'XF:ErrorLog';

	/** the shortest the quiet period may be made, in seconds */
	public const MINIMUM_QUIET = 7200;

	private const ORIGINS = [['web', 70], ['job', 15], ['cli', 12], ['unknown', 3]];

	private const COMMANDS = ['cmd.php xf:rebuild-forums', 'cmd.php xf:run-jobs', 'cmd.php xf-rebuild:search', 'cmd.php xf:addon-upgrade Vendor/AddOn'];

	private const PAGES = ['/threads/example.123/', '/forums/general.4/', '/members/someone.56/', '/whats-new/', '/search/1234/?q=example', '/account/preferences'];

	/** @var int seconds before now that no entry may be dated inside */
	private static $quiet = self::MINIMUM_QUIET;

	/** @var array|null the faults entries are drawn from, built once per process */
	private static $pool;

	/**
	 * @param int $seconds how long before now no entry may be dated; never less than two hours
	 */
	public static function quietFor(int $seconds): void
	{
		self::$quiet = max(self::MINIMUM_QUIET, $seconds);
	}

	public static function quiet(): int
	{
		return self::$quiet;
	}

	public function definition()
	{
		$context = $this->context();

		return [
			'signature' => function ()
			{
				return self::drawn();
			},
			'origin' => function (array $attributes)
			{
				return self::originOf($attributes['signature']);
			},
			'exception_date' => function () use ($context)
			{
				return min($context->timeline->at(), self::latest());
			},
			'user_id' => 0,
			'ip_address' => function (array $attributes)
			{
				return $attributes['origin'] === 'cli' ? '' : pack('C4', 198, 18 + mt_rand(0, 1), mt_rand(0, 255), mt_rand(1, 254));
			},
			'exception_type' => function (array $attributes) { return $attributes['signature'][0]; },
			'filename' => function (array $attributes) { return $attributes['signature'][1]; },
			'line' => function (array $attributes) { return $attributes['signature'][2]; },
			'message' => function (array $attributes) { return $attributes['signature'][3]; },
			'trace_string' => function (array $attributes)
			{
				return self::trace($attributes['signature'], $attributes['origin']);
			},
			'request_state' => function (array $attributes)
			{
				return self::requestState($attributes['origin']);
			},
		];
	}

	/** @return static raised by a page view */
	public function web() { return $this->state(['origin' => 'web']); }

	/** @return static raised by a command */
	public function cli() { return $this->state(['origin' => 'cli']); }

	/** @return static raised inside a job */
	public function job() { return $this->state(['origin' => 'job']); }

	/** @return static with a request state that says neither */
	public function unknownOrigin() { return $this->state(['origin' => 'unknown']); }

	/**
	 * Raised while this member was logged in.
	 *
	 * @return static
	 */
	public function forUser(User $user)
	{
		return $this->state(['user_id' => $user->user_id, 'origin' => 'web']);
	}

	/**
	 * A fault in an add-on's own code, such as `Vendor/AddOn`. The add-on need not be installed.
	 *
	 * @return static
	 */
	public function inAddOn(string $addOnId)
	{
		return $this->state(['signature' => self::addOnFault($addOnId, 'Service/Example', 120)]);
	}

	/**
	 * A fault in XenForo's own code that no add-on appears in.
	 *
	 * @return static
	 */
	public function inCore()
	{
		return $this->state(['signature' => ['XF\Db\Exception', 'src/XF/Db/AbstractStatement.php', 225,
			'MySQL query error [1213]: Deadlock found when trying to get lock; try restarting transaction',
			'XF\Db\AbstractStatement', 'getException', 'web', 0.7]]);
	}

	/**
	 * A PHP warning, notice or deprecation, as XenForo logs one.
	 *
	 * @param string $level a PHP constant name: E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING ...
	 *
	 * @return static
	 */
	public function php(string $level = 'E_WARNING')
	{
		// a different line for each level, so that two levels are two faults and not one
		return $this->state(['signature' => ['ErrorException', 'src/XF/Mvc/Entity/Entity.php', 200 + crc32($level) % 400,
			"[{$level}] Undefined array key \"example\"", 'XF\Mvc\Entity\Entity', 'get', 'web', 0.85]]);
	}

	/**
	 * @return static a fatal error, as XenForo logs one at shutdown
	 */
	public function fatal()
	{
		return $this->state(['signature' => ['ErrorException', 'src/XF/Mvc/Entity/Finder.php', 1432,
			'Fatal Error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
			'XF\Mvc\Entity\Finder', 'fetch', 'cli', 0.7]]);
	}

	/**
	 * An error raised while a template was rendering.
	 *
	 * @param string $template type and title, such as `public:thread_view`. It need not exist.
	 *
	 * @return static
	 */
	public function inTemplate(string $template = 'public:thread_view', string $level = 'E_WARNING')
	{
		[$type, $title] = array_pad(explode(':', $template, 2), 2, '');
		if ($title === '' || preg_match('/[^a-z0-9_.]/i', $type . $title))
		{
			throw new \InvalidArgumentException("'{$template}' is not a template: write it as type:title, such as public:thread_view");
		}

		return $this->state(['signature' => ['ErrorException', "internal_data/code_cache/templates/l1/s0/{$type}/{$title}.php", 87,
			"Template error: [{$level}] Undefined array key \"example\"", 'XF\Template\Templater', '{closure}', 'web', 1.0]]);
	}

	/**
	 * A class an add-on should have supplied that is not there - a class extension whose file
	 * is missing. The file is XenForo's; only the message names the add-on.
	 *
	 * @return static
	 */
	public function missingClass(string $addOnId)
	{
		$namespace = str_replace('/', '\\', $addOnId);

		return $this->state(['signature' => ['Error', 'src/XF/Extension.php', 174,
			"Class \"{$namespace}\\XF\\Entity\\User\" not found", 'XF\Extension', 'extendClass', 'web', 0.85]]);
	}

	/**
	 * An add-on whose Composer files are missing. The file is XenForo's; the message names the
	 * add-on by path.
	 *
	 * @return static
	 */
	public function missingAutoload(string $addOnId)
	{
		return $this->state(['signature' => ['ErrorException', 'src/XF/ComposerAutoload.php', 91,
			"[E_WARNING] require(src/addons/{$addOnId}/vendor/composer/autoload_files.php): Failed to open stream: No such file or directory",
			'XF\ComposerAutoload', 'autoloadFiles', 'web', 0.85]]);
	}

	/**
	 * @return static a fault in a file outside the forum altogether
	 */
	public function outside()
	{
		return $this->state(['signature' => ['RuntimeException', '/usr/share/php/Example/Library.php', 44,
			'The library could not be initialised', 'Example\Library', 'boot', 'cli', 0.9]]);
	}

	public static function describe(Entity $entity): array
	{
		return ['type' => $entity->exception_type, 'file' => $entity->filename, 'line' => $entity->line];
	}

	protected function newEntity(array $attributes): Entity
	{
		unset($attributes['signature'], $attributes['origin']);

		return parent::newEntity($attributes);
	}

	protected function store(Entity $entity): void
	{
		// the rule this factory exists to keep, applied last so that no state or override gets past it
		if ($entity->exception_date > self::latest())
		{
			$entity->exception_date = self::latest() - mt_rand(0, 3600);
		}

		$entity->save();
	}

	/**
	 * Where an entry for this fault came from: usually where the fault lives, and otherwise drawn
	 * from the general mix - a job's code is also run by hand now and then.
	 */
	private static function originOf(array $signature): string
	{
		$usual = $signature[6] ?? null;
		$howUsual = $signature[7] ?? 0.85;

		if ($usual === null)
		{
			return self::weighted(self::ORIGINS);
		}
		if ($howUsual >= 1.0)
		{
			return $usual;
		}

		// now and then XenForo records nothing it can be told by, whatever the fault
		if (mt_rand(1, 100) <= 3)
		{
			return 'unknown';
		}

		return mt_rand() / mt_getrandmax() < $howUsual ? $usual : self::weighted(self::ORIGINS);
	}

	/**
	 * The newest moment an entry may carry.
	 */
	private static function latest(): int
	{
		return time() - self::$quiet;
	}

	/**
	 * One of the pool's faults, the first few far more often than the rest.
	 */
	private static function drawn(): array
	{
		$pool = self::pool();

		$total = 0.0;
		foreach ($pool AS $index => $fault)
		{
			$total += 1 / ($index + 1) ** 1.2;
		}

		$target = mt_rand() / mt_getrandmax() * $total;
		$sum = 0.0;
		foreach ($pool AS $index => $fault)
		{
			$sum += 1 / ($index + 1) ** 1.2;
			if ($sum >= $target)
			{
				return $fault;
			}
		}

		return end($pool);
	}

	/**
	 * The faults a log is drawn from: two in each of a handful of the forum's add-ons, some in
	 * XenForo itself, and one each of the kinds something reading the log has to tell apart.
	 */
	private static function pool(): array
	{
		if (self::$pool !== null)
		{
			return self::$pool;
		}

		$installed = [];
		foreach (array_keys(\XF::app()->container('addon.cache')) AS $addOnId)
		{
			if (!preg_match('/^XF[A-Z]*$/', $addOnId))
			{
				$installed[] = $addOnId;
			}
		}
		sort($installed);

		// a handful of them, chosen by the generator so that a seeded run chooses the same ones
		$chosen = [];
		for ($i = 0; $i < 6 && $installed; $i++)
		{
			$chosen[] = array_splice($installed, mt_rand(0, count($installed) - 1), 1)[0];
		}

		$pool = [];
		foreach ($chosen AS $addOnId)
		{
			$pool[] = self::addOnFault($addOnId, 'Service/Sync', mt_rand(20, 400));
			$pool[] = self::addOnFault($addOnId, 'Job/Process', mt_rand(20, 400), 'ErrorException', '[E_WARNING] Undefined array key "status"');
		}
		if ($chosen)
		{
			$pool[] = self::addOnFault($chosen[0], 'Cli/Command/Import', mt_rand(20, 400), 'RuntimeException', 'The import file could not be read');
		}

		// a page template: a stylesheet is an unlikely place for an undefined array key
		$templates = \XF::db()->fetchAllColumn(
			"SELECT title FROM xf_template WHERE type = 'public' AND style_id = 0
				AND title NOT LIKE '%.less' AND title NOT LIKE '%.css' ORDER BY title LIMIT 400"
		);
		$real = $templates ? $templates[mt_rand(0, count($templates) - 1)] : 'thread_view';

		$one = new static();
		foreach ([
			$one->inCore(), $one->php('E_DEPRECATED'), $one->php('E_NOTICE'), $one->fatal(),
			$one->inTemplate('public:' . $real), $one->inTemplate('public:seeder_no_such_template', 'E_USER_WARNING'),
			$one->inAddOn('Acme/NotInstalled'), $one->outside(),
			$one->missingClass($chosen[0] ?? 'Acme/NotInstalled'), $one->missingAutoload($chosen[1] ?? 'Acme/NotInstalled'),
		] AS $factory)
		{
			$pool[] = end($factory->states)['signature'];
		}

		// in an order of the generator's choosing: which faults are the common ones varies by seed
		for ($i = count($pool) - 1; $i > 0; $i--)
		{
			$j = mt_rand(0, $i);
			[$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
		}

		return self::$pool = $pool;
	}

	private static function addOnFault(string $addOnId, string $class, int $line, string $type = 'InvalidArgumentException', string $message = 'The record could not be processed'): array
	{
		if (!preg_match('/^[A-Za-z0-9]+(\/[A-Za-z0-9]+)?$/', $addOnId))
		{
			throw new \InvalidArgumentException("'{$addOnId}' is not an add-on id");
		}

		// a job's class is run by the job queue, a command's by the command line, the rest by pages
		$usual = strpos($class, 'Job/') === 0 ? 'job' : (strpos($class, 'Cli/') === 0 ? 'cli' : 'web');

		return [$type, "src/addons/{$addOnId}/{$class}.php", $line, $message,
			str_replace('/', '\\', $addOnId . '/' . $class), 'run', $usual, 0.85];
	}

	/**
	 * A stack trace in the form PHP gives one: the fault first, then how XenForo got there.
	 */
	private static function trace(array $signature, string $origin): string
	{
		[, $file, $line, , $class, $method] = $signature;

		$frames = ["{$file}({$line}): {$class}->{$method}()"];

		if ($origin === 'job')
		{
			$frames[] = 'src/XF/Job/Manager.php(275): XF\Job\AbstractJob->run(8)';
			$frames[] = 'src/XF/Job/Manager.php(205): XF\Job\Manager->runJobInternal(Array, 8)';
			$frames[] = 'src/XF/Job/Manager.php(89): XF\Job\Manager->runJobEntry(Array, 8)';
			$frames[] = 'job.php(46): XF\Job\Manager->runQueue(false, 8)';
		}
		else if ($origin === 'cli')
		{
			$frames[] = 'src/vendor/symfony/console/Command/Command.php(298): XF\Cli\Command\AbstractCommand->execute(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))';
			$frames[] = 'src/vendor/symfony/console/Application.php(1040): Symfony\Component\Console\Command\Command->run(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Component\Console\Output\ConsoleOutput))';
			$frames[] = 'src/XF/Cli/Runner.php(111): Symfony\Component\Console\Application->run()';
			$frames[] = 'cmd.php(15): XF\Cli\Runner->run()';
		}
		else
		{
			$frames[] = "src/XF/Mvc/Dispatcher.php(362): XF\\Pub\\Controller\\ThreadController->actionIndex(Object(XF\\Mvc\\ParameterBag))";
			$frames[] = "src/XF/Mvc/Dispatcher.php(264): XF\\Mvc\\Dispatcher->dispatchClass('XF:Thread', 'Index', Object(XF\\Mvc\\RouteMatch), Object(XF\\Pub\\Controller\\ThreadController), NULL)";
			$frames[] = 'src/XF/Mvc/Dispatcher.php(121): XF\Mvc\Dispatcher->dispatchFromMatch(Object(XF\Mvc\RouteMatch), Object(XF\Pub\Controller\ThreadController), NULL)';
			$frames[] = 'src/XF/App.php(2826): XF\Mvc\Dispatcher->run()';
			$frames[] = "src/XF.php(806): XF\\App->run()";
			$frames[] = "index.php(23): XF::runApp('XF\\\\Pub\\\\App')";
		}

		$lines = [];
		foreach ($frames AS $number => $frame)
		{
			$lines[] = "#{$number} {$frame}";
		}
		$lines[] = '#' . count($frames) . ' {main}';

		return implode("\n", $lines);
	}

	/**
	 * What XenForo records about the request: a page's address and input, or a command line.
	 */
	private static function requestState(string $origin): array
	{
		switch ($origin)
		{
			case 'cli':
				return ['cli' => self::COMMANDS[mt_rand(0, count(self::COMMANDS) - 1)]];

			case 'job':
				return ['url' => '/job.php', 'referrer' => 'https://example.com/', '_GET' => [], '_POST' => []];

			case 'unknown':
				return [];

			default:
				return [
					'url' => self::PAGES[mt_rand(0, count(self::PAGES) - 1)],
					'referrer' => 'https://example.com/forums/',
					'_GET' => [],
					'_POST' => [],
				];
		}
	}

	private static function weighted(array $choices): string
	{
		$target = mt_rand(1, array_sum(array_column($choices, 1)));
		foreach ($choices AS [$value, $weight])
		{
			$target -= $weight;
			if ($target <= 0)
			{
				return $value;
			}
		}

		return $choices[0][0];
	}
}
