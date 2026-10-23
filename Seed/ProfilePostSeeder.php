<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ProfilePostFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * Profile posts between members, status updates, and comments on both.
 *
 * Parameters:
 *
 *     posts      how many profile posts one member leaves on another's profile (8)
 *     statuses   how many status updates, each a member's post on their own profile (3)
 *     comments   the most comments each gets, from the members; each has between none and
 *                this many (2)
 *     members    how many members to create for them to be between (4)
 *
 * Nobody is alerted.
 */
class ProfilePostSeeder extends AbstractSeeder
{
	public function run()
	{
		$members = UserFactory::new()->count(max(2, $this->count('members', 4)))->create();

		$posts = ProfilePostFactory::new()->recycle($members);
		$comments = $this->count('comments', 2);
		if ($comments)
		{
			$posts = $posts->withComments([0, $comments]);
		}

		$count = $this->count('posts', 8);
		if ($count)
		{
			$posts->count($count)->create();
		}

		$statuses = $this->count('statuses', 3);
		if ($statuses)
		{
			$posts->status()->count($statuses)->create();
		}
	}
}
