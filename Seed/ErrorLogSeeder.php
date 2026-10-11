<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * A server error log with some history in it: a few faults that recur and many that do not, from
 * page views, commands and jobs, some of them while a member was logged in.
 *
 * Parameters:
 *
 *     errors    how many entries (300)
 *     members   how many members to create for entries to be attributed to; about one entry in
 *               three from a page view names one (5)
 *
 * **Entries are written as data. Nothing is made to fail.** None is dated within the quiet period
 * before now, so nothing that watches for new errors takes one for new. Whatever reports on the
 * log by another rule will still report on these - see notices().
 */
class ErrorLogSeeder extends AbstractSeeder
{
	/**
	 * Add-ons that read the server error log and send what they find somewhere, and what each
	 * will do with entries it did not see being written.
	 */
	private const READERS = [
		'Hampel/LogDigest' => 'seeded errors dated inside its window will be in the next digest it sends',
		'Hampel/EventLogging' => 'every seeded error will be forwarded to its log channel on its next run, and on to wherever that channel sends errors; and cleaning entries it has already forwarded makes it send the whole real log again, once',
	];

	public function run()
	{
		$members = $this->param('member_pool') ?? UserFactory::new()->count($this->count('members', 5))->create();
		$ids = [];
		foreach ($members AS $member)
		{
			$ids[] = $member->user_id;
		}

		ErrorLogFactory::new()
			->count($this->count('errors', 300))
			->state(function (array $attributes) use ($ids)
			{
				// attributes are still unresolved here, so the origin is decided for the entries
				// that are to carry a member
				return $ids && mt_rand(1, 100) <= 25
					? ['origin' => 'web', 'user_id' => $ids[mt_rand(0, count($ids) - 1)]]
					: [];
			})
			->create();
	}

	public function notices(): array
	{
		$notices = [];
		foreach (self::READERS AS $addOnId => $what)
		{
			if (\XF::isAddOnActive($addOnId))
			{
				$notices[] = "{$addOnId} is active: {$what}.";
			}
		}

		return $notices;
	}

	/**
	 * @return array [add-on id => what it will do], for the readers active on this forum
	 */
	public static function activeReaders(): array
	{
		$active = [];
		foreach (self::READERS AS $addOnId => $what)
		{
			if (\XF::isAddOnActive($addOnId))
			{
				$active[$addOnId] = $what;
			}
		}

		return $active;
	}
}
