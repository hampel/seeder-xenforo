<?php namespace Hampel\Seeder\Cli\Command;

use Hampel\Seeder\Cli\RendersReport;
use Hampel\Seeder\Config as SeederConfig;
use Hampel\Seeder\Guard;
use Hampel\Seeder\Repository\RecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum allows seeding, and with what settings. It reads and prints; it writes
 * nothing. seeder:validate is the command that finds out whether any of it works.
 */
class Config extends Command
{
	use RendersReport;

	private const FROM_CONFIG = ' <fg=cyan>(config.php)</>';

	protected function configure()
	{
		$this
			->setName('seeder:config')
			->setDescription('Show whether this forum allows seeding, and the settings in use');
	}

	protected function errorLogPrefix(): string
	{
		return 'Seeder: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$app = \XF::app();

		$this->heading('Seeder');
		$addOn = $app->addOnManager()->getById('Hampel/Seeder');
		$this->detail('add-on', $addOn ? (string) $addOn->getJson()['version_string'] : '');
		$this->detail('XenForo', \XF::$version);
		$this->detail('Faker', self::fakerVersion());

		$this->heading('Guard');
		$this->detail('seeding allowed', (Guard::isEnabled() ? 'yes' : 'no')
			. (SeederConfig::has('enabled') ? self::FROM_CONFIG : ' ' . $this->muted('(not in config.php)')));

		$this->heading('Records');
		$repo = \XF::repository(RecordRepository::class);
		$this->detail('batches on this forum', $repo->tableExists() ? (string) count($repo->getBatches()) : '');

		$this->heading('Generated content');
		$this->detail('locale', SeederConfig::locale()
			. (SeederConfig::get('locale') !== null ? self::FROM_CONFIG : ' ' . $this->muted('(default)')));

		$this->heading('Error log');
		$this->detail('quiet period', SeederConfig::errorLogQuietHours() . ' hours'
			. (SeederConfig::get('errorLogQuietHours') !== null ? self::FROM_CONFIG : ' ' . $this->muted('(default)')));

		$this->heading('Mail');
		// no source shown: XenForo merges its defaults into config, so set and defaulted look alike
		$this->detail('enableMail', self::mailEnabled() ? 'yes' : 'no');
		$this->detail('transport', self::mailTransport() . ' ' . $this->muted('(options)'));

		$this->report->writeln('');

		return 0;
	}

	/**
	 * The Faker release in this add-on's own Composer tree, or an empty string if it is not there.
	 */
	public static function fakerVersion(): string
	{
		$installed = dirname(__DIR__, 2) . '/vendor/composer/installed.json';
		if (!is_readable($installed))
		{
			return '';
		}

		$data = json_decode((string) file_get_contents($installed), true);
		foreach ((array) ($data['packages'] ?? []) AS $package)
		{
			if (($package['name'] ?? '') === 'fakerphp/faker')
			{
				return (string) ($package['version'] ?? '');
			}
		}

		return '';
	}

	public static function mailEnabled(): bool
	{
		return (bool) \XF::config('enableMail');
	}

	/**
	 * The mail transport's type and nothing else: the option also holds the transport's
	 * credentials, which must never be printed.
	 */
	public static function mailTransport(): string
	{
		$transport = \XF::options()->emailTransport ?? [];

		return is_array($transport) ? (string) ($transport['emailTransport'] ?? '') : '';
	}
}
