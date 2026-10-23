<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\FailedJobFactory;
use Hampel\Seeder\Factory\JobFactory;

/**
 * The job queue and its history: jobs that failed over the last few weeks, and jobs waiting in
 * the queue - some to be run by hand, some tried and waiting to try again.
 *
 * Parameters:
 *
 *     failed    how many failed jobs (60)
 *     days      how many days back they go, at least two (28)
 *     secrets   the percentage of one-off failures whose message quotes a made-up credential (10)
 *     queued    how many jobs wait for the queue (6)
 *     manual    how many wait to be run by hand (3)
 *     retrying  how many have failed and wait to try again (3)
 *
 * **Failed jobs are written as rows, and nothing is made to fail.** None is dated within a day of
 * now. **No queued job will ever run**: each is due years from now and names a job class that
 * does not exist. So a queue seeded here has nothing due and nothing stalled. Not in the sample
 * `seeder:validate` seeds, or in `Default`.
 */
class JobSeeder extends AbstractSeeder
{
	/** how long one add-on keeps a failed job before deleting it, in days */
	private const PRUNED_AFTER = ['Hampel/JobRunner' => 30];

	public function run()
	{
		$secrets = $this->count('secrets', 10);
		if ($secrets > 100)
		{
			throw new \InvalidArgumentException("secrets is a percentage, so at most 100, not {$secrets}");
		}

		FailedJobFactory::new()
			->count($this->count('failed', 60))
			->within($this->count('days', 28))
			->secrets($secrets / 100)
			->create();

		foreach ([
			[$this->count('queued', 6), 0, 0],
			[$this->count('manual', 3), 1, 0],
			[$this->count('retrying', 3), 0, 1],
		] AS [$count, $manual, $retrying])
		{
			if ($count)
			{
				JobFactory::new()->count($count)->manual($manual)->retrying($retrying)->create();
			}
		}
	}

	public function notices(): array
	{
		$notices = ['Every queued job is due years from now and names a job class that does not exist, so none will run.'];

		$days = $this->count('days', 28);
		foreach (self::PRUNED_AFTER AS $addOnId => $keeps)
		{
			if ($days > $keeps && \XF::isAddOnActive($addOnId))
			{
				$notices[] = "{$addOnId} is active and deletes failed jobs older than {$keeps} days, so those older will go before they are cleaned.";
			}
		}

		return $notices;
	}
}
