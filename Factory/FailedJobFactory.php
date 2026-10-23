<?php namespace Hampel\Seeder\Factory;

/**
 * Failed job history: rows of the table XenForo 2.3 writes when a job throws, each with the
 * exception as PHP turns one into text.
 *
 *     FailedJobFactory::new()->count(60)->within(28)->secrets(0.1)->create();
 *
 * Most failures are a handful of faults that recur, in XenForo's jobs and in a few of the forum's
 * add-ons; the rest are one-offs. **A fault inside the forum names a real file where there is one**
 * - XenForo's job, the add-on's own code, a library in its `vendor/` - since whatever reads the
 * history may check that it is there. A made-up add-on's file is not. Between them they throw from XenForo's own code, from the
 * libraries it ships, from an add-on and an add-on's `vendor/`, from outside the install, and now
 * and then with no file and line at all.
 *
 * **Some messages quote a credential**, as real ones do - an address with a login in it, a webhook,
 * a key in a query string, a token. Every one is made up and says so, `SECRET` or `example.com`,
 * and the token shapes are put together as the run goes rather than written here.
 *
 * **No failure is dated within a day of now, or the quiet period if that is longer.** Something
 * that watches the job queue reports a failure from the last day; one older than that is history.
 * XenForo has no entity for this table, so the rows are recorded as `xf_failed_job`.
 */
class FailedJobFactory extends AbstractBulkFactory
{
	protected $entity = 'xf_failed_job';

	/** how recent a failure may be at the most, unless the quiet period is longer */
	public const RECENT = 86400 + 3600;

	/** XenForo's jobs that fail in a real forum: the ones that reach outside it, and the heavy ones */
	private const CORE = [
		['XF\Job\MailSend', 'Symfony\Component\Mailer\Exception\TransportException', 'src/vendor/symfony/mailer/Transport/Smtp/Stream/SocketStream.php', 154,
			'Connection could not be established with host "ssl://smtp.example.com:465": stream_socket_client(): Unable to connect to ssl://smtp.example.com:465 (Connection timed out)'],
		['XF\Job\Sitemap', 'XF\Db\Exception', 'src/XF/Db/AbstractStatement.php', 230,
			'MySQL query error [1205]: Lock wait timeout exceeded; try restarting transaction'],
		['XF\Job\AttachmentThumb', 'ErrorException', 'src/XF/Image/Gd.php', 77,
			'[E_WARNING] imagecreatefromstring(): Data is not in a recognized format'],
		['XF\Job\SearchIndex', 'XF\Db\DeadlockException', 'src/XF/Db/AbstractStatement.php', 230,
			'MySQL query error [1213]: Deadlock found when trying to get lock; try restarting transaction'],
	];

	/** made-up faults for a one-off: [type, message] */
	private const ONE_OFF = [
		['ErrorException', '[E_WARNING] Undefined array key "%s"'],
		['Error', 'Call to a member function %s() on null'],
		['TypeError', 'Argument #1 ($%s) must be of type array, null given'],
		['InvalidArgumentException', 'Unknown %s type'],
		['RuntimeException', 'The %s could not be processed'],
		['LogicException', 'No %s has been set'],
	];

	/** @var int */
	private $count = 1;

	/** @var int how many days back failures go */
	private $days = 28;

	/** @var float the share of one-off failures that quote a credential */
	private $secrets = 0.1;

	/**
	 * @return static
	 */
	public function count(int $count)
	{
		$this->count = $count;

		return $this;
	}

	/**
	 * Failures go back this many days, at least two.
	 *
	 * @return static
	 */
	public function within(int $days)
	{
		if ($days < 2)
		{
			throw new \InvalidArgumentException('Failed jobs go back at least two days, since none may be from the last day');
		}
		$this->days = $days;

		return $this;
	}

	/**
	 * The share of one-off failures, from 0 to 1, whose message quotes a credential. Two of the
	 * faults that recur always do.
	 *
	 * @return static
	 */
	public function secrets(float $share)
	{
		$this->secrets = max(0.0, min(1.0, $share));

		return $this;
	}

	protected static function tableOf(string $entity): array
	{
		return ['xf_failed_job', ['failed_job_id']];
	}

	protected function rows(): iterable
	{
		$latest = time() - max(self::RECENT, ErrorLogFactory::quiet());
		$earliest = time() - $this->days * 86400;
		$faker = $this->context()->faker;
		$recurring = $this->recurring();

		for ($i = 0; $i < $this->count; $i++)
		{
			// more than half of a real history is the same few faults again
			if (mt_rand(1, 100) <= 60)
			{
				$fault = self::zipf($recurring);
			}
			else
			{
				$fault = $this->oneOff($faker->word());
			}
			[$class, $type, $file, $line, $message] = $fault;

			yield [
				'execute_class' => $class,
				'execute_data' => serialize([]),
				'exception' => self::exception($class, $type, $file, $line, $message),
				'fail_date' => mt_rand($earliest, $latest),
			];
		}
	}

	protected function write(array $rows): int
	{
		return $this->insertEach($rows);
	}

	/**
	 * The faults that recur: XenForo's, one in the code of each of up to three of the forum's
	 * add-ons, one in a library an add-on ships, one from outside the install, and one with no file.
	 *
	 * @return array[] [job class, exception type, file, line, message], commonest first
	 */
	private function recurring(): array
	{
		$faults = self::CORE;
		$faults[] = ['XF\Job\WebhookSend', 'GuzzleHttp\Exception\ClientException', 'src/vendor/guzzlehttp/guzzle/src/Exception/RequestException.php', 113,
			'Client error: `POST ' . self::secret('webhook') . '` resulted in a `404 Not Found` response'];

		$addOns = [];
		foreach (array_keys(\XF::app()->container('addon.cache')) AS $addOnId)
		{
			if (!preg_match('/^XF[A-Z]*$/', $addOnId))
			{
				$addOns[] = $addOnId;
			}
		}
		sort($addOns);
		$chosen = [];
		for ($i = 0; $i < 3 && $addOns; $i++)
		{
			$chosen[] = array_splice($addOns, mt_rand(0, count($addOns) - 1), 1)[0];
		}
		$chosen = $chosen ?: ['Acme/Example'];

		foreach ($chosen AS $addOnId)
		{
			$namespace = str_replace('/', '\\', $addOnId);
			$faults[] = ["{$namespace}\\Job\\Sync", 'RuntimeException',
				self::realFile("src/addons/{$addOnId}", ['Job/*.php', 'Service/*.php', 'Repository/*.php', '*.php']) ?? "src/addons/{$addOnId}/Service/Api.php", mt_rand(20, 400),
				'The remote service answered 503 Service Unavailable'];
		}

		// a library an add-on ships: from one of those that has one, or else any add-on that does,
		// or else one it does not have, so that the history still has a failure in an add-on's vendor/
		$library = null;
		foreach (array_merge($chosen, $addOns) AS $addOnId)
		{
			if ($file = self::libraryFile("src/addons/{$addOnId}/vendor"))
			{
				$library = [$addOnId, $file];
				break;
			}
		}
		[$libraryAddOn, $libraryFile] = $library ?? [$chosen[0], "src/addons/{$chosen[0]}/vendor/acme/client/src/Client.php"];
		$faults[] = [str_replace('/', '\\', $libraryAddOn) . '\\Job\\Sync', 'GuzzleHttp\Exception\ConnectException', $libraryFile, 210,
			'cURL error 7: Failed to connect to api.example.com port 443 after 0 ms: Connection refused for ' . self::secret('login')];

		$faults[] = ["{$namespace}\\Job\\Export", 'RuntimeException', '/opt/example/lib/Storage/Client.php', 88,
			'Upload refused: storage quota exceeded'];
		$faults[] = ['XF\Job\EmailBounce', 'XF\Job\ManuallyFailedException', null, 0,
			'The bounce mailbox could not be opened'];

		// in an order of the generator's choosing: which faults are the common ones varies by seed
		for ($i = count($faults) - 1; $i > 0; $i--)
		{
			$j = mt_rand(0, $i);
			[$faults[$i], $faults[$j]] = [$faults[$j], $faults[$i]];
		}

		return $faults;
	}

	/**
	 * A fault seen once: a job of XenForo's or a made-up add-on's, thrown from anywhere.
	 */
	private function oneOff(string $word): array
	{
		$classes = array_merge(array_column(self::CORE, 0), ['XF\Job\WebhookSend', 'XF\Job\UserAction', 'Acme\Feeds\Job\Import', 'Acme\Digest\Job\Send']);
		$class = $classes[mt_rand(0, count($classes) - 1)];
		[$type, $message] = self::ONE_OFF[mt_rand(0, count(self::ONE_OFF) - 1)];
		$message = sprintf($message, $word);

		if (mt_rand(1, 1000) <= $this->secrets * 1000)
		{
			$kinds = ['login', 'hostless', 'webhook', 'query', 'slack', 'jwt', 'hex'];
			$type = 'RuntimeException';
			$message = 'Request failed: ' . self::secret($kinds[mt_rand(0, count($kinds) - 1)]);
		}

		$where = mt_rand(1, 100);
		if ($where <= 45)
		{
			// a job of XenForo's fails in its own file; a made-up add-on's in one it does not have
			$file = strpos($class, 'XF\\') === 0
				? 'src/' . str_replace('\\', '/', $class) . '.php'
				: 'src/XF/' . ['Service', 'Repository', 'Entity'][mt_rand(0, 2)] . '/' . ucfirst($word) . '.php';
		}
		else if ($where <= 70)
		{
			$file = 'src/addons/Acme/' . ucfirst($word) . '/Job/Process.php';
		}
		else if ($where <= 82)
		{
			$file = 'src/addons/Acme/' . ucfirst($word) . '/vendor/acme/client/src/Client.php';
		}
		else if ($where <= 90)
		{
			$file = '/opt/example/lib/' . ucfirst($word) . '.php';
		}
		else
		{
			$file = null;
		}

		return [$class, $type, $file, mt_rand(10, 900), $message];
	}

	/**
	 * A real file in a directory of the install, so that whatever reads the history and checks a
	 * file is there finds it: the first of the patterns that matches anything, its files in order.
	 *
	 * @param string $directory relative to the install
	 * @param string[] $patterns relative to the directory
	 *
	 * @return string|null relative to the install, or null where nothing matches
	 */
	private static function realFile(string $directory, array $patterns): ?string
	{
		$root = realpath(\XF::getRootDirectory()) ?: \XF::getRootDirectory();
		foreach ($patterns AS $pattern)
		{
			$files = glob("{$root}/{$directory}/{$pattern}") ?: [];
			sort($files);
			if ($files)
			{
				return substr($files[mt_rand(0, count($files) - 1)], strlen($root) + 1);
			}
		}

		return null;
	}

	/**
	 * A real file in one of the libraries in a `vendor/` directory that the add-on ships - never
	 * one only its tests need, since no job runs PHPUnit.
	 *
	 * @param string $vendor relative to the install
	 *
	 * @return string|null relative to the install, or null where there is no library to choose
	 */
	private static function libraryFile(string $vendor): ?string
	{
		$root = realpath(\XF::getRootDirectory()) ?: \XF::getRootDirectory();
		$installed = json_decode((string) @file_get_contents("{$root}/{$vendor}/composer/installed.json"), true);
		if (!is_array($installed) || !isset($installed['packages']))
		{
			return null;
		}

		$dev = $installed['dev-package-names'] ?? [];
		$names = [];
		foreach ($installed['packages'] AS $package)
		{
			if (!in_array($package['name'], $dev, true) && is_dir("{$root}/{$vendor}/{$package['name']}"))
			{
				$names[] = $package['name'];
			}
		}
		sort($names);

		for ($tries = count($names); $tries > 0; $tries--)
		{
			$name = array_splice($names, mt_rand(0, count($names) - 1), 1)[0];
			$file = self::realFile("{$vendor}/{$name}", ['src/*.php', 'src/*/*.php', 'lib/*.php', '*.php']);
			if ($file !== null)
			{
				return $file;
			}
		}

		return null;
	}

	/**
	 * The exception as PHP writes one out, which is how XenForo stores it: the type, the message,
	 * where it was thrown, and the trace. A fault with no file has neither of the last two.
	 *
	 * @param string|null $file relative to the install, or absolute when it is outside it
	 */
	private static function exception(string $class, string $type, ?string $file, int $line, string $message): string
	{
		if ($file === null)
		{
			return "{$type}: {$message}";
		}

		// absolute, as PHP writes it, whatever the install was started with
		$root = realpath(\XF::getRootDirectory()) ?: \XF::getRootDirectory();
		$path = $file[0] === '/' ? $file : "{$root}/{$file}";

		return "{$type}: {$message} in {$path}:{$line}\nStack trace:\n"
			. "#0 {$root}/src/XF/Job/Manager.php(275): {$class}->run(8)\n"
			. "#1 {$root}/src/XF/Job/Manager.php(205): XF\\Job\\Manager->runJobInternal(Array, 8)\n"
			. "#2 {$root}/src/XF/Job/Manager.php(89): XF\\Job\\Manager->runJobEntry(Array, 8)\n"
			. "#3 {$root}/job.php(46): XF\\Job\\Manager->runQueue(false, 8)\n"
			. '#4 {main}';
	}

	/**
	 * A made-up credential in one of the shapes that turn up in a real message. Each says it is
	 * not real, and the token shapes are assembled here so that no file holds one whole.
	 */
	private static function secret(string $kind): string
	{
		$hex = function (int $length): string
		{
			$out = '';
			while (strlen($out) < $length)
			{
				$out .= dechex(mt_rand(0, 15));
			}

			return $out;
		};
		$base64url = function (string $text): string
		{
			return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
		};

		switch ($kind)
		{
			case 'login':
				return 'https://SECRET-user:SECRET-' . $hex(12) . '@api.example.com/v1/sync';
			case 'hostless':
				return 'redis://SECRET-user:SECRET-' . $hex(12) . '@/0';
			case 'webhook':
				return 'https://hooks.example.com/services/SECRET/' . $hex(24);
			case 'query':
				return 'https://api.example.com/v1/feed?api_key=SECRET-' . $hex(16);
			case 'slack':
				return 'token ' . 'xox' . 'b-' . 'SECRET-' . mt_rand(100000, 999999) . '-' . $hex(24);
			case 'jwt':
				// the signature is where it says so: SECRET- is itself base64url
				return 'bearer ' . $base64url('{"alg":"HS256","typ":"JWT"}') . '.' . $base64url('{"sub":"SECRET-example"}') . '.SECRET-' . $hex(32);
			default:
				return 'signing key ' . $hex(40);
		}
	}

	/**
	 * One of the faults, the first commonest: the nth is drawn about 1/n as often as the first.
	 */
	private static function zipf(array $faults): array
	{
		$total = 0;
		foreach (array_keys($faults) AS $index)
		{
			$total += 1 / ($index + 1) ** 1.2;
		}

		$target = mt_rand() / mt_getrandmax() * $total;
		$sum = 0;
		foreach ($faults AS $index => $fault)
		{
			$sum += 1 / ($index + 1) ** 1.2;
			if ($sum >= $target)
			{
				return $fault;
			}
		}

		return end($faults);
	}
}
