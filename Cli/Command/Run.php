<?php namespace Hampel\Seeder\Cli\Command;

use Hampel\Seeder\Guard;
use Hampel\Seeder\Record\SeedRunner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Repository\RecordRepository;
use Hampel\Seeder\Seed\Resolver;
use Hampel\Seeder\Timeline;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a seeder, recording everything it creates under one batch so seeder:clean can remove it.
 *
 * What was created is printed as JSON on standard output - a count of each kind, then ids, and for
 * members the name, address and the run's password; rows written in bulk are counted only - and a summary for a person on standard error, so
 * `seeder:run 2>/dev/null | jq` works.
 *
 * Exit code: 0 when the seeder finished, 1 if it was refused or threw. A run that throws has still
 * recorded what it made before it did.
 */
class Run extends Command
{
	protected function configure()
	{
		$this
			->setName('seeder:run')
			->setDescription('Create test content on this forum by running a seeder')
			->addArgument('seeder', InputArgument::OPTIONAL,
				'The seeder: a name such as User, or Vendor/AddOn:Name for another add-on\'s', Resolver::DEFAULT_SEEDER)
			->addOption('param', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'A parameter for the seeder, as name=value - for example -p users=50')
			->addOption('span', null, InputOption::VALUE_REQUIRED,
				'How far back content is dated, ending now: 36h, 90d, 12w, 6m, 2y', Timeline::DEFAULT_SPAN)
			->addOption('seed', null, InputOption::VALUE_REQUIRED,
				'A number that makes the generated names and text the same on every run')
			->addOption('batch', null, InputOption::VALUE_REQUIRED,
				'A name for this run\'s batch - the date and time if omitted')
			->addOption('no-record', null, InputOption::VALUE_NONE,
				'Do not record what is created. It can then never be cleaned - for a forum that will be discarded');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

		if (!Guard::isEnabled())
		{
			$errors->writeln('<error>' . Guard::refusal() . '</error>');

			return 1;
		}

		try
		{
			$class = Resolver::resolve((string) $input->getArgument('seeder'));
			$params = self::params((array) $input->getOption('param'));
			$timeline = Timeline::span((string) $input->getOption('span'));
			$seed = self::seed($input->getOption('seed'));
			$batch = $input->getOption('no-record') ? null : $this->batch($input->getOption('batch'));
		}
		catch (\InvalidArgumentException $e)
		{
			$errors->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		if ($batch === null)
		{
			$errors->writeln('<comment>Not recording: what this run creates cannot be removed by seeder:clean.</comment>');
		}

		foreach ((new $class($params))->notices() AS $notice)
		{
			$errors->writeln("<comment>{$notice}</comment>");
		}

		$result = (new SeedRunner())->run($class, $params, $timeline, $seed, $batch);
		$created = $result['created'];
		$counts = $result['counts'];
		$failure = $result['failure'];
		$password = $result['password'];

		$output->writeln(json_encode([
			'batch' => $batch,
			'seeder' => $class,
			'seed' => $seed,
			'password' => $password,
			'complete' => $failure === null,
			'counts' => $counts,
			'created' => $created,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		foreach ($counts AS $entity => $count)
		{
			$errors->writeln("  created  {$entity} x {$count}");
		}
		if (!$counts)
		{
			$errors->writeln('  nothing was created');
		}

		if ($failure)
		{
			// a refused parameter is a mistake in what was asked for, not a fault on the forum, and
			// has no business in its server error log
			if (!$failure instanceof \InvalidArgumentException)
			{
				\XF::logException($failure, false, 'Seeder: seeder:run - ');
			}
			$errors->writeln('<error>The seeder failed: ' . get_class($failure) . ': ' . $failure->getMessage() . '</error>');
			if ($batch !== null && $counts)
			{
				$errors->writeln("What it made before failing is recorded. Remove it with: seeder:clean {$batch}");
			}

			return 1;
		}

		if ($batch !== null)
		{
			$errors->writeln("  batch    {$batch}");
		}

		return 0;
	}

	/**
	 * @param string[] $pairs each "name=value"
	 */
	public static function params(array $pairs): array
	{
		$params = [];
		foreach ($pairs AS $pair)
		{
			if (!preg_match('/^([A-Za-z0-9_-]+)=(.*)$/s', $pair, $match))
			{
				throw new \InvalidArgumentException("'{$pair}' is not a parameter: write it as name=value");
			}

			$params[$match[1]] = $match[2];
		}

		return $params;
	}

	/**
	 * @param mixed $seed
	 */
	private static function seed($seed): ?int
	{
		if ($seed === null)
		{
			return null;
		}
		if (!ctype_digit((string) $seed))
		{
			throw new \InvalidArgumentException("--seed must be a whole number, not '{$seed}'");
		}

		return (int) $seed;
	}

	/**
	 * A batch that is not already on this forum. Running twice into one batch would leave no way
	 * to tell the two runs apart, or to remove one of them.
	 *
	 * @param mixed $batch
	 */
	private function batch($batch): string
	{
		$batch = $batch === null ? gmdate('Ymd-His') : (string) $batch;

		if (!TableRecorder::isValidBatchName($batch))
		{
			throw new \InvalidArgumentException(
				"'{$batch}' is not a batch name: use 1 to 50 letters, digits, dots, hyphens or underscores"
			);
		}
		if (\XF::repository(RecordRepository::class)->batchExists($batch))
		{
			throw new \InvalidArgumentException(
				"There is already a batch named '{$batch}' on this forum. Give this run another name, or remove that one first with: seeder:clean {$batch}"
			);
		}

		return $batch;
	}
}
