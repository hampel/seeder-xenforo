<?php namespace Hampel\Seeder\Cli\Command;

use Hampel\Seeder\Repository\RecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The batches this forum holds, oldest first: what each one created, and when. Read-only, so it
 * runs with seeding switched off - a forum can always be asked what was left on it.
 */
class ListBatches extends Command
{
	protected function configure()
	{
		$this
			->setName('seeder:list')
			->setDescription('List the batches of seeded content on this forum')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the batches as JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$batches = \XF::repository(RecordRepository::class)->getBatches();

		if ($input->getOption('json'))
		{
			$output->writeln(json_encode(['batches' => self::forJson($batches)], JSON_PRETTY_PRINT));

			return 0;
		}

		if (!$batches)
		{
			$output->writeln('No seeded content is recorded on this forum.');

			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['Batch', 'Records', 'Created', 'Contents']);
		foreach ($batches AS $name => $batch)
		{
			$contents = [];
			foreach ($batch['entities'] AS $entity => $count)
			{
				$contents[] = "{$entity} x {$count}";
			}

			$table->addRow([$name, $batch['records'], gmdate('Y-m-d H:i', $batch['first']) . ' UTC', implode("\n", $contents)]);
		}
		$table->render();

		return 0;
	}

	public static function forJson(array $batches): array
	{
		$list = [];
		foreach ($batches AS $name => $batch)
		{
			$list[] = [
				'batch' => (string) $name,
				'records' => $batch['records'],
				'first_record' => gmdate('c', $batch['first']),
				'last_record' => gmdate('c', $batch['last']),
				'entities' => $batch['entities'],
			];
		}

		return $list;
	}
}
