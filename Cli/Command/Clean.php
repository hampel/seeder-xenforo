<?php namespace Hampel\Seeder\Cli\Command;

use Hampel\Seeder\Guard;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Repository\RecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Removes seeded content: one batch, or every batch. It deletes only what was recorded when the
 * content was created, so nothing a batch did not make is touched.
 *
 * Exit code: 0 when everything recorded is gone, 1 if anything could not be removed or the run was
 * refused. A record whose entity could not be removed stays, so the command can be run again.
 */
class Clean extends Command
{
	protected function configure()
	{
		$this
			->setName('seeder:clean')
			->setDescription('Remove seeded content from this forum')
			->addArgument('batch', InputArgument::OPTIONAL, 'The batch to remove - every batch if omitted')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask before deleting')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON - needs --force');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$json = (bool) $input->getOption('json');

		if (!Guard::isEnabled())
		{
			$output->writeln('<error>' . Guard::refusal() . '</error>');

			return 1;
		}

		$all = \XF::repository(RecordRepository::class)->getBatches();
		$batch = $input->getArgument('batch');
		if ($batch !== null && !isset($all[$batch]))
		{
			$output->writeln("<error>There is no batch named '{$batch}' on this forum.</error>");

			return 1;
		}
		$batches = $batch === null ? $all : [$batch => $all[$batch]];

		if (!$batches)
		{
			$output->writeln($json ? json_encode(['batches' => []], JSON_PRETTY_PRINT) : 'No seeded content is recorded on this forum.');

			return 0;
		}

		if (!$input->getOption('force'))
		{
			if ($json)
			{
				$output->writeln('<error>--json cannot ask for confirmation: pass --force as well.</error>');

				return 1;
			}

			$records = array_sum(array_column($batches, 'records'));
			$question = new ConfirmationQuestion(
				"<question>Delete {$records} seeded record(s) in " . count($batches) . " batch(es) from this forum? (y/n)</question> ",
				false
			);
			if (!$this->getHelper('question')->ask($input, $output, $question))
			{
				$output->writeln('Nothing was deleted.');

				return 1;
			}
		}

		$cleaner = $this->cleaner();
		$results = [];
		$failed = false;
		foreach (array_keys($batches) AS $name)
		{
			$results[$name] = $cleaner->clean((string) $name);
			$failed = $failed || $results[$name]['failed'];
		}

		$json ? $output->writeln(json_encode(['batches' => self::forJson($results)], JSON_PRETTY_PRINT))
			: $this->report($output, $results);

		return $failed ? 1 : 0;
	}

	protected function cleaner(): Cleaner
	{
		return new Cleaner();
	}

	protected function report(OutputInterface $output, array $results): void
	{
		foreach ($results AS $name => $result)
		{
			$output->writeln('');
			$output->writeln("  <fg=green;options=bold>{$name}</>");

			foreach ($result['removed'] AS $entity => $count)
			{
				$output->writeln("  removed  {$entity} x {$count}");
			}
			foreach ($result['missing'] AS $entity => $count)
			{
				$output->writeln("  already gone  {$entity} x {$count}");
			}
			foreach ($result['failed'] AS $failure)
			{
				$output->writeln("  <error>failed</error>  {$failure['entity']} {$failure['id']} - {$failure['error']}");
			}
		}

		$output->writeln('');
	}

	public static function forJson(array $results): array
	{
		$list = [];
		foreach ($results AS $name => $result)
		{
			$list[] = ['batch' => (string) $name] + $result;
		}

		return $list;
	}
}
