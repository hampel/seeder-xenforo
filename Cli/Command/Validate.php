<?php namespace Hampel\Seeder\Cli\Command;

use Faker\Factory;
use Hampel\Seeder\Cli\RendersReport;
use Hampel\Seeder\Config as SeederConfig;
use Hampel\Seeder\Guard;
use Hampel\Seeder\Record\RoundTrip;
use Hampel\Seeder\Seed\AdminLogSeeder;
use Hampel\Seeder\Seed\ErrorLogSeeder;
use Hampel\Seeder\Repository\RecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum can be seeded, and whether doing so is safe: the guard, the generator, and
 * what the forum would do with mail.
 *
 * The round trip seeds a small forum - members, a forum, threads with replies, a prefix and
 * attachments - removes it, and compares the row count of every table before and after. It is the
 * only proof that cleaning leaves nothing behind on this forum, with this forum's add-ons, so it
 * runs by default. --unattended skips it, and it never runs with seeding switched off.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail. --strict makes a
 * warning exit 2 and changes nothing else.
 */
class Validate extends Command
{
	use RendersReport;

	private const SAFE_DOMAINS = ['example.com', 'example.org', 'example.net'];

	protected function configure()
	{
		$this
			->setName('seeder:validate')
			->setDescription('Check that this forum can be seeded, and that seeding it is safe')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Skip the round trip, which creates and removes real content - for a gate nobody is watching')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failures, instead of 0');
	}

	protected function errorLogPrefix(): string
	{
		return 'Seeder: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$this->checkSection('Guard');
		$this->probe('seeding allowed', function ()
		{
			$this->guard();
		});

		$this->checkSection('Records');
		$this->probe('record table', function ()
		{
			$this->records();
		});

		$this->checkSection('Generator');
		$this->probe('Faker', function ()
		{
			$this->generator();
		});

		$this->checkSection('Mail');
		$this->probe('mail', function ()
		{
			$this->mail();
		});

		$this->checkSection('Error log');
		$this->probe('error log', function ()
		{
			$this->errorLog();
		});

		$this->checkSection('Round trip');
		if ($input->getOption('unattended'))
		{
			$this->checkSkip('round trip', 'skipped - --unattended');
		}
		else if (!Guard::isEnabled())
		{
			$this->checkSkip('round trip', 'skipped - seeding is switched off');
		}
		else
		{
			$this->probe('round trip', function ()
			{
				$this->reportRoundTrip($this->roundTrip());
			});
		}

		$this->report->writeln('');
		if ($this->checksFailed())
		{
			$this->report->writeln('<error>Validation failed - this forum cannot be seeded as configured</error>');
		}
		else
		{
			$this->report->writeln($this->checksWarned() ? '<comment>Validated, with warnings</comment>' : '<info>Validated</info>');
		}

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	/**
	 * Off is a warning, not a skip and not a pass: nothing is broken, and the add-on does nothing.
	 */
	protected function guard(): void
	{
		if (Guard::isEnabled())
		{
			$this->checkOk('seeding allowed', 'switched on in config.php');
		}
		else if (SeederConfig::has('enabled'))
		{
			$this->checkWarn('seeding allowed', "switched off - \$config['hampelSeeder']['enabled'] is not true");
		}
		else
		{
			$this->checkWarn('seeding allowed', "switched off - config.php has no \$config['hampelSeeder']['enabled']");
		}
	}

	/**
	 * Without the table nothing can be recorded, so nothing created could be removed again.
	 */
	protected function records(): void
	{
		$repo = \XF::repository(RecordRepository::class);
		if (!$repo->tableExists())
		{
			$this->checkFail('record table', 'missing - reinstall the add-on');

			return;
		}

		$this->checkOk('record table', count($repo->getBatches()) . ' batch(es) recorded');
	}

	protected function generator(): void
	{
		$version = Config::fakerVersion();
		if ($version === '')
		{
			$this->checkFail('Faker', 'not in this add-on\'s vendor tree - run composer install');

			return;
		}
		$this->checkOk('Faker', $version);

		// Faker falls back to en_US without complaint, provider by provider, so a locale it has
		// never heard of looks exactly like one it knows
		$locale = SeederConfig::locale();
		if (self::fakerKnows($locale))
		{
			$this->checkOk('locale', $locale);
		}
		else
		{
			$this->checkWarn('locale', "{$locale} has no Faker providers - everything will be en_US");
		}

		$email = Factory::create($locale)->safeEmail();
		$domain = substr((string) strrchr($email, '@'), 1);
		if (in_array($domain, self::SAFE_DOMAINS, true))
		{
			$this->checkOk('email addresses', "reserved domains only, such as {$domain}");
		}
		else
		{
			$this->checkFail('email addresses', "generated an address at {$domain}, which could be real");
		}
	}

	private static function fakerKnows(string $locale): bool
	{
		foreach (['Person', 'Address', 'Company', 'Internet', 'PhoneNumber'] AS $provider)
		{
			if (class_exists("Faker\\Provider\\{$locale}\\{$provider}"))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Seeded error log entries are read by whatever reports on that log. Say which of those are
	 * here, since each will report on entries nobody saw being written.
	 */
	protected function errorLog(): void
	{
		$hours = SeederConfig::errorLogQuietHours();
		$this->checkOk('quiet period', "no seeded error, admin or moderator log entry is dated within {$hours} hours of now, so none looks new");

		foreach (ErrorLogSeeder::activeReaders() AS $addOnId => $what)
		{
			$this->checkWarn($addOnId, "active - {$what}");
		}
		foreach (AdminLogSeeder::activeReaders() AS $addOnId => $what)
		{
			$this->checkWarn($addOnId, "active - {$what}");
		}
	}

	/**
	 * @return array as RoundTrip::run() reports
	 */
	protected function roundTrip(): array
	{
		return (new RoundTrip())->run();
	}

	protected function reportRoundTrip(array $result): void
	{
		$made = [];
		foreach ($result['created'] AS $entity => $count)
		{
			$made[] = "{$entity} x {$count}";
		}

		if ($result['seed_failure'] !== null)
		{
			$this->checkFail('seeded', $result['seed_failure']);
		}
		else
		{
			$this->checkOk('seeded', implode(', ', $made) . " in {$result['seconds']}s, cleaning included");
		}

		if ($result['failed'])
		{
			$first = $result['failed'][0];
			$this->checkFail('cleaned', count($result['failed']) . " could not be removed, first {$first['entity']} {$first['id']} - "
				. "{$first['error']}. Left in batch {$result['batch']}");
		}
		else
		{
			// XenForo removes some things itself when the last use of them goes - a tag nobody uses
			$this->checkOk('cleaned', "{$result['removed']} removed"
				. (($result['gone'] ?? 0) ? ", {$result['gone']} already removed by XenForo" : ''));
		}

		if (!$result['leaks'])
		{
			$this->checkOk('no trace', "{$result['tables']} tables compared, XenForo's own all back to their counts");
		}
		foreach ($result['leaks'] AS $table => [$before, $after])
		{
			$this->checkFail('left behind', "{$table} went from {$before} rows to {$after}");
		}

		// another add-on's table: it may be logging what ran, or holding something of ours
		foreach ($result['other'] AS $table => [$before, $after])
		{
			$this->checkWarn('other add-on', "{$table} went from {$before} rows to {$after}");
		}

		if ($result['errors'])
		{
			$this->checkWarn('server error log', count($result['errors']) . ' new while this ran, first: ' . $result['errors'][0]);
		}
	}

	/**
	 * The seeder never sends, but a forum that can send will do so about seeded content later -
	 * a digest, a watched thread. On a copy of a live forum that reaches the live provider.
	 */
	protected function mail(): void
	{
		$transport = Config::mailTransport();

		if (!Config::mailEnabled())
		{
			$this->checkOk('enableMail', 'off - this forum cannot send');
		}
		else
		{
			$this->checkWarn('enableMail', 'on - this forum can send mail, through the '
				. ($transport === '' ? 'default' : $transport) . ' transport');
		}
	}
}
