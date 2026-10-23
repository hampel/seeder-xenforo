<?php namespace Hampel\Seeder\Factory;

/**
 * Jobs waiting in the queue: some to be run by hand, some already tried and waiting to try again.
 *
 *     JobFactory::new()->count(12)->manual(0.25)->retrying(0.25)->create();
 *
 * **None of them will ever run.** Each is due years from now, so neither the queue nor a request
 * to run what an administrator started will pick one up. Each names a job class that does not
 * exist, so if one ever were run it would fail rather than do something. And each has a unique key
 * that starts with `seeder-`, which `seeder:run` and `seeder:clean` look for so as never to run one
 * themselves when they finish the jobs their saves queued.
 *
 * So a queue seeded here has jobs waiting, manual ones and ones retrying, and nothing due or
 * stalled: a row that was due would be run. XenForo has no entity for this table, so the rows are
 * recorded as `xf_job`.
 */
class JobFactory extends AbstractBulkFactory
{
	protected $entity = 'xf_job';

	/** what every seeded job's unique key starts with */
	public const KEY_PREFIX = 'seeder-';

	/** made-up job classes, none of which any add-on supplies */
	private const CLASSES = ['Acme\Feeds\Job\Import', 'Acme\Digest\Job\Send', 'Acme\Sync\Job\Push', 'Acme\Archive\Job\Compress', 'Acme\Reports\Job\Build'];

	/** @var int */
	private $count = 1;

	/** @var float */
	private $manual = 0.25;

	/** @var float */
	private $retrying = 0.25;

	/**
	 * @return static
	 */
	public function count(int $count)
	{
		$this->count = $count;

		return $this;
	}

	/**
	 * The share of jobs, from 0 to 1, that are to be run by hand rather than by the queue.
	 *
	 * @return static
	 */
	public function manual(float $share)
	{
		$this->manual = max(0.0, min(1.0, $share));

		return $this;
	}

	/**
	 * The share of jobs, from 0 to 1, that have been tried and failed, and wait to try again.
	 *
	 * @return static
	 */
	public function retrying(float $share)
	{
		$this->retrying = max(0.0, min(1.0, $share));

		return $this;
	}

	protected static function tableOf(string $entity): array
	{
		return ['xf_job', ['job_id']];
	}

	protected function rows(): iterable
	{
		$classes = array_values(array_filter(self::CLASSES, function (string $class)
		{
			return !class_exists($class);
		}));
		if (!$classes)
		{
			throw new \RuntimeException('Every job class Seeder makes up exists on this forum, so a seeded job could do something');
		}

		for ($i = 0; $i < $this->count; $i++)
		{
			$retrying = mt_rand(1, 1000) <= $this->retrying * 1000;

			yield [
				'unique_key' => self::KEY_PREFIX . sprintf('%08x%08x', mt_rand(), mt_rand()),
				'execute_class' => $classes[mt_rand(0, count($classes) - 1)],
				'execute_data' => serialize([]),
				'manual_execute' => mt_rand(1, 1000) <= $this->manual * 1000 ? 1 : 0,
				// five to ten years away
				'trigger_date' => time() + mt_rand(5 * 365, 10 * 365) * 86400,
				'last_run_date' => $retrying ? time() - mt_rand(3600, 7 * 86400) : null,
				'attempts' => $retrying ? mt_rand(1, 4) : 0,
				'priority' => 100,
			];
		}
	}

	protected function write(array $rows): int
	{
		return $this->insertEach($rows);
	}
}
