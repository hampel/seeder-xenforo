<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\ModeratorLogFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * A moderator log with history in it: a few moderators acting on members' threads, replies and
 * accounts - editing, moving, locking and unlocking, removing and restoring, approving.
 *
 * Parameters:
 *
 *     entries     about how many entries (60)
 *     moderators  how many moderators to create for them to be by (2)
 *     members     how many members to create, whose threads, replies and accounts they are
 *                 about (4)
 *     threads     how many threads those members start, in a forum made for them (6)
 *     replies     the most replies each thread gets, from those members (3)
 *
 * **Entries are written as rows. No moderator did anything**, and nothing that reacts to a
 * moderator action as it is taken sees them - see notices(). None is dated within the quiet
 * period before now. Not in the sample `seeder:validate` seeds, or in `Default`.
 */
class ModeratorLogSeeder extends AbstractSeeder
{
	/**
	 * Add-ons that send a moderator action somewhere as it is taken, and how.
	 */
	private const WATCHERS = [
		'Hampel/Slack' => 'posts a moderator action as XenForo logs it',
		'Hampel/EventLogging' => 'forwards each moderator log entry as it is saved',
	];

	public function run()
	{
		$moderators = ModeratorLogFactory::moderator()->count(max(1, $this->count('moderators', 2)))->create();
		$members = UserFactory::new()->count(max(1, $this->count('members', 4)))->create();

		$threads = ThreadFactory::new()
			->for(ForumFactory::new()->create())
			->recycle($members)
			->count(max(1, $this->count('threads', 6)))
			->has(PostFactory::new()->recycle($members)->count([0, $this->count('replies', 3)]))
			->create();

		$content = array_merge(array_values($members->toArray()), array_values($threads->toArray()));
		foreach ($threads AS $thread)
		{
			foreach (\XF::em()->getFinder('XF:Post')->where('thread_id', $thread->thread_id)->where('position', '>', 0)->fetch() AS $post)
			{
				$content[] = $post;
			}
		}

		ModeratorLogFactory::new()
			->for(...$content)
			->by(...array_values($moderators->toArray()))
			->count($this->count('entries', 60))
			->create();
	}

	public function notices(): array
	{
		$notices = [];
		foreach (self::WATCHERS AS $addOnId => $what)
		{
			if (\XF::isAddOnActive($addOnId))
			{
				$notices[] = "{$addOnId} is active and {$what}; these are written as rows, so it does not see them.";
			}
		}

		return $notices;
	}
}
