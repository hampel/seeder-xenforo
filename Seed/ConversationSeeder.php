<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ConversationFactory;
use Hampel\Seeder\Factory\ConversationMessageFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * Conversations between members, each with replies from more than one of them and every message
 * at its own moment.
 *
 * Parameters:
 *
 *     conversations   how many (5)
 *     messages        how many messages each has, the first included (10)
 *     participants    the most members in one, the starter included; each has between two and
 *                     this many (3)
 *     members         how many members to create for them to be between (6)
 *
 * Nobody is alerted or emailed about any of it.
 */
class ConversationSeeder extends AbstractSeeder
{
	public function run()
	{
		$conversations = $this->count('conversations', 5);
		$replies = max(0, $this->count('messages', 10) - 1);
		$most = max(2, $this->count('participants', 3));

		$members = $this->param('member_pool') ?? UserFactory::new()->count(max(2, $this->count('members', 6)))->create();

		for ($i = 0; $i < $conversations; $i++)
		{
			ConversationFactory::new()
				->recycle($members)
				->to(mt_rand(1, $most - 1))
				->has(ConversationMessageFactory::new()->count($replies))
				->create();
		}
	}
}
