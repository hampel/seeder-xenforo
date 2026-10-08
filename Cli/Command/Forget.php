<?php namespace Hampel\Seeder\Cli\Command;

use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Repository\RecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Discards the records of a batch, or of every batch, and leaves the content where it is. The
 * content then belongs to the forum: seeder:clean can never remove it.
 *
 * This is the way to keep seeded content when uninstalling the add-on. It deletes nothing but this
 * add-on's own records, so it runs with seeding switched off.
 */
class Forget extends Command
{
	protected function configure()
	{
		$this
			->setName('seeder:forget')
			->setDescription('Keep seeded content but discard the record of it - it can then never be cleaned')
			->addArgument('batch', InputArgument::OPTIONAL, 'The batch to forget - every batch if omitted')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask first');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
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
			$output->writeln('No seeded content is recorded on this forum.');

			return 0;
		}

		$records = array_sum(array_column($batches, 'records'));

		if (!$input->getOption('force'))
		{
			$question = new ConfirmationQuestion(
				"<question>Discard {$records} record(s) in " . count($batches) . " batch(es)? The content stays on this forum and can never be cleaned. (y/n)</question> ",
				false
			);
			if (!$this->getHelper('question')->ask($input, $output, $question))
			{
				$output->writeln('Nothing was forgotten.');

				return 1;
			}
		}

		foreach (array_keys($batches) AS $name)
		{
			\XF::db()->delete(TableRecorder::TABLE, 'batch = ?', (string) $name);
			$output->writeln("  forgot   {$name} - {$batches[$name]['records']} record(s); the content was left in place");
		}

		return 0;
	}
}
