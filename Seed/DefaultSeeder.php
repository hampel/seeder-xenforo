<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * What seeder:run builds when it is not told otherwise: members, forums, and threads with replies
 * written by those members in those forums.
 *
 * Parameters: users (20), forums (2), forum_types (0), and those of the Thread seeder - threads
 * (12), replies (5), prefix (1), reactions (0), tags (0), featured (0), sticky (0), polls (0), states (0).
 *
 * With forum_types=1 the forums are of each of XenForo's types in turn - discussion, article,
 * question, suggestion - so forums=4 makes one of each.
 */
class DefaultSeeder extends AbstractSeeder
{
	public function run()
	{
		$users = UserFactory::new()->count($this->count('users', 20))->create();
		$forums = ForumFactory::new()->count($this->count('forums', 2));

		// polls were asked for, so the discussion forums take them
		$polls = $this->count('polls', 0);
		$discussion = ['forum_type_id' => 'discussion'] + ($polls ? ['allowed_thread_types' => ['poll']] : []);
		if ($this->count('forum_types', 0))
		{
			$forums = $forums->sequence(
				$discussion,
				['forum_type_id' => 'article'],
				['forum_type_id' => 'question'],
				['forum_type_id' => 'suggestion']
			);
		}
		else if ($polls)
		{
			$forums = $forums->state($discussion);
		}
		$forums = $forums->create();

		$this->call(ThreadSeeder::class, ['author_pool' => $users, 'forum_pool' => $forums]);
	}
}
