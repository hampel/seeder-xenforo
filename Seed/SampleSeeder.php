<?php namespace Hampel\Seeder\Seed;

/**
 * A little of everything this add-on can create, and no more than it takes to have one of each:
 * members, a forum, threads whose first posts cover the range of BB code, replies, a prefix,
 * attachments, tags, reactions, a featured thread, content awaiting approval and content soft-deleted, conversations,
 * members with other ways into their accounts and members active without posting, members banned
 * and members whose mail bounced,
 * custom user fields with members' values, user upgrades held and run out, warning definitions,
 * warnings and API keys - and, where the forum has Resource Manager or Media Gallery, resources -
 * rated, reviewed and one of them featured - and media items.
 *
 * It is what seeder:validate seeds and removes to prove cleaning leaves nothing behind, so
 * anything new the add-on learns to create belongs here too.
 */
class SampleSeeder extends AbstractSeeder
{
	public function run()
	{
		$this->call(ThreadSeeder::class, ['threads' => 8, 'replies' => 3, 'authors' => 4, 'prefix' => 1, 'reactions' => 2, 'states' => 1, 'tags' => 3, 'featured' => 1]);
		$this->call(ConversationSeeder::class, ['conversations' => 2, 'messages' => 4, 'members' => 3]);
		$this->call(UserSeeder::class, ['users' => 0, 'access' => 5, 'active' => 2, 'moderated' => 2, 'passwordless' => 1, 'no_auth' => 1, 'banned' => 2, 'bouncing' => 1]);
		$this->call(UserFieldSeeder::class, ['fields' => 4, 'members' => 3]);
		$this->call(UserUpgradeSeeder::class, ['upgrades' => 1, 'members' => 3, 'active' => 2, 'expired' => 2]);
		// content that belongs to another add-on, where the forum has it
		if (\XF::isAddOnActive('XFRM'))
		{
			$this->call(ResourceSeeder::class, ['resources' => 3, 'categories' => 1, 'authors' => 2, 'reviews' => 1, 'ratings' => 1, 'featured' => 1]);
		}
		if (\XF::isAddOnActive('XFMG'))
		{
			$this->call(MediaSeeder::class, ['media' => 2, 'categories' => 1, 'albums' => 1, 'hidden' => 1, 'authors' => 2]);
		}

		$this->call(WarningSeeder::class, ['warnings' => 5, 'definitions' => 2, 'members' => 2]);
		$this->call(ApiKeySeeder::class, ['user_keys' => 1, 'guest_keys' => 1, 'super_keys' => 0, 'inactive' => 1]);
	}
}
