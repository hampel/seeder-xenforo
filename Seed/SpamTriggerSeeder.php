<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\SpamTriggerLogFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * A spam trigger log from the last month: registrations XenForo's spam check held for approval
 * or rejected, and messages it held or refused.
 *
 * Parameters:
 *
 *     registrations  how many entries about a registration, by turns of a member awaiting approval
 *                    and of one whose registration was rejected (6)
 *     messages       how many entries about a message, by turns a thread and a reply awaiting
 *                    approval and a reply refused outright, in a forum made for them (6)
 *     members        how many members to create who post those messages (3)
 *
 * **Entries are written as data. No spam check is run**, and nothing is sent to a spam service.
 * Every member and message is made in the state the entry says the check left it in. XenForo
 * prunes this log at 30 days, so every entry is dated within the last month.
 */
class SpamTriggerSeeder extends AbstractSeeder
{
	public function run()
	{
		for ($i = 0, $registrations = $this->count('registrations', 6); $i < $registrations; $i++)
		{
			$entry = SpamTriggerLogFactory::new();
			if ($i % 2)
			{
				$entry = $entry->for(UserFactory::new()->rejected()->create(['register_date' => SpamTriggerLogFactory::recent()]));
			}
			$entry->create();
		}

		$messages = $this->count('messages', 6);
		if (!$messages)
		{
			return;
		}

		// members of some standing, so everything they post this month comes after they joined
		$members = UserFactory::new()->count(max(1, $this->count('members', 3)))->create(['register_date' => function ()
		{
			return time() - mt_rand(35, 80) * 86400;
		}]);
		$forum = ForumFactory::new()->create();
		$thread = ThreadFactory::new()->for($forum)->recycle($members)->create(['post_date' => time() - 30 * 86400 + 3600]);
		$recent = function () { return SpamTriggerLogFactory::recent(); };

		for ($i = 0; $i < $messages; $i++)
		{
			switch ($i % 3)
			{
				case 0:
					$content = ThreadFactory::new()->for($forum)->recycle($members)->moderated()->create(['post_date' => $recent]);
					SpamTriggerLogFactory::new()->for($content)->create();
					break;

				case 1:
					$content = PostFactory::new()->for($thread)->recycle($members)->moderated()->create(['post_date' => $recent]);
					SpamTriggerLogFactory::new()->for($content)->create();
					break;

				case 2:
					SpamTriggerLogFactory::new()->denied('post', $members[mt_rand(0, $members->count() - 1)] ?? $members->first())->create();
					break;
			}
		}
	}
}
