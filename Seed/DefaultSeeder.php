<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * What seeder:run builds when it is not told otherwise: members, forums, and threads with replies
 * written by those members in those forums.
 *
 * Parameters: users (20), forums (2), and those of the Thread seeder - threads (12), replies (5),
 * prefix (1).
 */
class DefaultSeeder extends AbstractSeeder
{
	public function run()
	{
		$users = UserFactory::new()->count($this->count('users', 20))->create();
		$forums = ForumFactory::new()->count($this->count('forums', 2))->create();

		$this->call(ThreadSeeder::class, ['author_pool' => $users, 'forum_pool' => $forums]);
	}
}
