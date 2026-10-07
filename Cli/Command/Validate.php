<?php namespace Hampel\Seeder\Cli\Command;

use Faker\Factory;
use Hampel\Seeder\Cli\RendersReport;
use Hampel\Seeder\Config as SeederConfig;
use Hampel\Seeder\Guard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum can be seeded, and whether doing so is safe: the guard, the generator, and
 * what the forum would do with mail.
 *
 * Nothing here writes or sends, so --unattended has nothing to skip yet. It is accepted so that a
 * gate can pass the same flags to every add-on's validate command.
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
				'Skip checks that write or send - there are none yet')
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
