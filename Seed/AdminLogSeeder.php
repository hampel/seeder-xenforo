<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\AdminLogFactory;

/**
 * An admin log with some history in it: a few administrators, each doing the ordinary things over
 * and over and a few unusual ones once.
 *
 * Parameters:
 *
 *     entries   how many entries (200)
 *     admins    how many administrators to create for them to be by (3)
 *
 * **Entries are written as data. Nothing is submitted to the admin control panel.** None is dated
 * within the quiet period before now. Whatever reports on the log by another rule will still
 * report on these - see notices().
 *
 * **The administrators are real ones**, with the run's password and no administrator permissions.
 * XenForo prunes this log by age, so entries older than its limit go at its next daily clean-up,
 * whether or not the batch is cleaned.
 */
class AdminLogSeeder extends AbstractSeeder
{
	/**
	 * Add-ons that read the admin log and send what they find somewhere, and what each will do
	 * with entries it did not see being written.
	 */
	private const READERS = [
		'Hampel/LogDigest' => 'seeded entries dated inside its window will be in the next admin log digest it sends',
	];

	public function run()
	{
		$admins = $this->param('admin_pool') ?? AdminLogFactory::administrator()->count(max(1, $this->count('admins', 3)))->create();

		AdminLogFactory::new()->recycle($admins)->count($this->count('entries', 200))->create();
	}

	public function notices(): array
	{
		$notices = [];
		if ($this->param('admin_pool') === null)
		{
			$admins = max(1, $this->count('admins', 3));
			$notices[] = "Creates {$admins} administrator" . ($admins === 1 ? '' : 's')
				. ' who can sign in to the admin control panel with the password below, with no permissions there.';
		}
		foreach (self::activeReaders() AS $addOnId => $what)
		{
			$notices[] = "{$addOnId} is active: {$what}.";
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
