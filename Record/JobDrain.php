<?php namespace Hampel\Seeder\Record;

use XF\Repository\CountersRepository;

/**
 * Runs the jobs that seeding or cleaning queued, before the command returns.
 *
 * Saving and deleting entities queues work - a search re-index when a member is removed, a
 * permission rebuild when a group changes. Left queued, a run looks finished while the forum is
 * still changing, and whatever is compared next is compared against a moving target.
 *
 * Only jobs queued after start() are run, and never XenForo's own cron runner. The board
 * statistics cache is rebuilt last, for the same reason.
 */
class JobDrain
{
	private const PASSES = 500;

	/** @var int */
	protected $mark = 0;

	public function start(): void
	{
		$this->mark = (int) \XF::db()->fetchOne('SELECT COALESCE(MAX(job_id), 0) FROM xf_job');
	}

	/**
	 * @return int how many jobs were run to completion
	 */
	public function finish(): int
	{
		$manager = \XF::app()->jobManager();
		$completed = 0;

		// The permission builder keeps the tree of forums it first saw. Seed and clean in one
		// process and the rebuild after cleaning would still know the forum just deleted, and
		// write its permission rows back.
		\XF::app()->container()->decache('permission.builder');

		for ($pass = 0; $pass < self::PASSES; $pass++)
		{
			// Some jobs are not queued when the entity is saved but held back until the request
			// ends - the re-index of a conversation's messages is one. Left to that, they would be
			// queued after this had finished looking.
			\XF::triggerRunOnce();

			$ids = \XF::db()->fetchAllColumn(
				'SELECT job_id FROM xf_job WHERE job_id > ? AND execute_class <> ? ORDER BY job_id',
				[$this->mark, 'XF\Job\Cron']
			);
			if (!$ids)
			{
				break;
			}

			foreach ($ids AS $id)
			{
				$manager->runById($id, 8);
				if (!$manager->getJob($id))
				{
					$completed++;
				}
			}
		}

		// the board statistics name the newest member, who may have just been created or removed
		\XF::repository(CountersRepository::class)->rebuildForumStatisticsCache();

		return $completed;
	}
}
