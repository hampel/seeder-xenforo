<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\EmailBounceLogFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * An email bounce log from the last month: hard bounces from members whose mail has stopped, soft
 * bounces from members whose mail still goes, and messages that came back without being bounces.
 *
 * Parameters:
 *
 *     hard   how many members whose mail bounced hard, each with one bounce and in the state that
 *            leaves them in (3)
 *     soft   how many members whose mail bounced soft, one to three times each, who still receive
 *            mail (3)
 *     other  how many entries that are not bounces, by turns an automatic reply, a delay, and a
 *            bounce for an address no member has (3)
 *
 * **Entries are written as data. Nothing is read from a bounce mailbox and nothing is sent**, so
 * nothing that acts on a bounce as XenForo reads it sees them. Every entry carries the delivery
 * report it would have been read from. XenForo prunes this log at 30 days, so every entry is dated
 * within the last month.
 */
class EmailBounceSeeder extends AbstractSeeder
{
	public function run()
	{
		$hard = $this->count('hard', 3);
		if ($hard)
		{
			EmailBounceLogFactory::new()->count($hard)->create();
		}

		$members = [];
		for ($i = 0, $soft = $this->count('soft', 3); $i < $soft; $i++)
		{
			$members[] = $member = UserFactory::new()->create();
			EmailBounceLogFactory::new()->for($member)->soft()->count(mt_rand(1, 3))->create();
		}

		for ($i = 0, $other = $this->count('other', 3); $i < $other; $i++)
		{
			$entry = EmailBounceLogFactory::new();
			$member = $members ? $members[mt_rand(0, count($members) - 1)] : null;
			switch ($i % 3)
			{
				case 0: $entry = $entry->messageType('autoreply'); break;
				case 1: $entry = $entry->messageType('delay'); break;
				case 2: $entry = $entry->unmatched(); $member = null; break;
			}
			($member ? $entry->for($member) : $entry)->create();
		}
	}
}
