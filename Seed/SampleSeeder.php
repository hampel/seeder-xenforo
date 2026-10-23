<?php namespace Hampel\Seeder\Seed;

/**
 * A little of everything this add-on can create, and no more than it takes to have one of each:
 * members, a forum, threads whose first posts cover the range of BB code, replies, a prefix,
 * attachments, tags, reactions, a featured thread, a sticky thread, a thread with a poll and
 * votes, content awaiting approval and content soft-deleted, a forum of each other type with threads of that type, a category
 * with a forum in it and a sub-forum in that, search forums, conversations,
 * members with other ways into their accounts and members active without posting, members banned
 * and members whose mail bounced, an administrator with no permissions, profile posts, status
 * updates and comments on them,
 * custom user fields with members' values, user upgrades held and run out, warning definitions,
 * warnings, API keys and widgets - and, where the forum has Resource Manager or Media Gallery, resources -
 * rated, reviewed, one of them featured and each with a discussion thread - and media items
 * with comments.
 *
 * An HTML widget is left out: it has a template of its own, and saving one empties the forum's
 * cached CSS, which validating a forum should not do.
 *
 * It is what seeder:validate seeds and removes to prove cleaning leaves nothing behind, so
 * anything new the add-on learns to create belongs here too.
 */
class SampleSeeder extends AbstractSeeder
{
	public function run()
	{
		$this->call(ThreadSeeder::class, ['threads' => 8, 'replies' => 3, 'authors' => 4, 'prefix' => 1, 'reactions' => 2, 'states' => 1, 'tags' => 3, 'featured' => 1, 'sticky' => 1, 'polls' => 1, 'ips' => 1]);
		foreach (['article', 'question', 'suggestion'] AS $type)
		{
			$this->call(ThreadSeeder::class, ['threads' => 2, 'replies' => 2, 'authors' => 2, 'prefix' => 0, 'forum_type' => $type]);
		}
		// a category, a forum in it and a forum inside that, so a tree is made and cleaned
		$this->call(DefaultSeeder::class, ['users' => 2, 'forums' => 1, 'categories' => 1, 'subforums' => 1, 'threads' => 2, 'replies' => 1, 'prefix' => 0]);
		$this->call(SearchForumSeeder::class, ['search_forums' => 2, 'threads' => 3, 'replies' => 1, 'authors' => 2, 'prefix' => 0]);
		$this->call(ConversationSeeder::class, ['conversations' => 2, 'messages' => 4, 'members' => 3]);
		$this->call(UserSeeder::class, ['users' => 0, 'access' => 5, 'active' => 2, 'moderated' => 2, 'passwordless' => 1, 'no_auth' => 1, 'banned' => 2, 'bouncing' => 1, 'admins' => 1, 'changes' => 1, 'renamed' => 3, 'rejected' => 2, 'ips' => 1]);
		$this->call(ProfilePostSeeder::class, ['posts' => 2, 'statuses' => 1, 'comments' => 2, 'members' => 2]);
		$this->call(UserFieldSeeder::class, ['fields' => 4, 'members' => 3]);
		$this->call(UserUpgradeSeeder::class, ['upgrades' => 1, 'purchasable' => 1, 'members' => 3, 'active' => 2, 'expired' => 2]);
		// content that belongs to another add-on, where the forum has it
		if (\XF::isAddOnActive('XFRM'))
		{
			$this->call(ResourceSeeder::class, ['resources' => 3, 'categories' => 1, 'authors' => 2, 'reviews' => 1, 'ratings' => 1, 'featured' => 1, 'discussions' => 1]);
		}
		if (\XF::isAddOnActive('XFMG'))
		{
			$this->call(MediaSeeder::class, ['media' => 2, 'categories' => 1, 'albums' => 1, 'hidden' => 1, 'authors' => 2, 'comments' => 2]);
		}

		$this->call(WarningSeeder::class, ['warnings' => 5, 'definitions' => 2, 'members' => 2]);
		$this->call(ApiKeySeeder::class, ['user_keys' => 1, 'guest_keys' => 1, 'super_keys' => 0, 'inactive' => 1]);
		$this->call(WidgetSeeder::class, ['widgets' => 2]);
	}
}
