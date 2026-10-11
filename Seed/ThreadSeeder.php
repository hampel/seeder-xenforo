<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\PrefixFactory;
use Hampel\Seeder\Factory\ReactionFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use XF\Repository\TagRepository;

/**
 * Threads with replies, whose first posts differ from one to the next: plain, long, with a quote,
 * a list, an image, links, an attachment, and everything at once - so whatever renders a first
 * post meets the range of BB code.
 *
 * Parameters:
 *
 *     threads   how many (12)
 *     replies   the most replies a thread gets; each gets between none and this many (5)
 *     forum     the node id of an existing forum to post in; a new forum is made if omitted
 *     authors   how many members to create as authors (5)
 *     prefix    1 to create a thread prefix and give it to some threads, 0 for none (1)
 *     reactions the most reactions a post gets, from the authors; each gets between none and
 *               this many, and never more than there are other authors to give them. 0 for
 *               none (0)
 *     tags      how many different tags to file the threads under, each thread getting up to
 *               three of them; 0 for none (0)
 *     states    1 to add threads and replies that are awaiting approval or soft-deleted, about
 *               one in six of each; 0 for everything visible (0)
 *
 * A seeder that calls this one may pass `author_pool` and `forum_pool`, collections of existing
 * members and forum nodes, in place of authors and forum.
 */
class ThreadSeeder extends AbstractSeeder
{
	public function run()
	{
		$authors = $this->param('author_pool') ?? UserFactory::new()->count($this->count('authors', 5))->create();
		$forums = $this->param('forum_pool') ?? [$this->forum()];

		$prefix = null;
		if ($this->count('prefix', 1))
		{
			$nodeIds = [];
			foreach ($forums AS $forum)
			{
				$nodeIds[] = $forum->node_id;
			}
			$prefix = PrefixFactory::new()->create(['node_ids' => $nodeIds]);
		}

		$variations = [
			[],
			['message_parts' => ['long'], 'prefix_id' => $prefix ? $prefix->prefix_id : 0],
			['message_parts' => ['quote']],
			['message_parts' => ['list']],
			['message_parts' => ['image']],
			['message_parts' => ['links']],
			['attachments' => 1],
			['message_parts' => ['formatting', 'quote', 'list', 'links'], 'attachments' => 2,
				'prefix_id' => $prefix ? $prefix->prefix_id : 0],
		];

		$replies = PostFactory::new()->count([0, $this->count('replies', 5)]);
		$threads = ThreadFactory::new()
			->count($this->count('threads', 12))
			->recycle($authors)
			->recycle($forums)
			->sequence(...$variations);

		// a member cannot react to their own post, so each post has one fewer to draw on than
		// there are authors; past that the factory would make new members to do the reacting
		$reactions = min($this->count('reactions', 0), max(0, count($authors) - 1));
		if ($reactions)
		{
			$replies = $replies->has(ReactionFactory::new()->count([0, $reactions]));
			$threads = $threads->has(ReactionFactory::new()->count([0, $reactions]));
		}

		$tags = $this->count('tags', 0);
		if ($tags)
		{
			// one- and two-word tags, since a tag's form in a URL is what differs for the second
			$names = [];
			for ($i = 0; $i < $tags; $i++)
			{
				$names[] = $this->tagName($i % 3 === 2 ? 2 : 1, $names);
			}

			$threads = $threads->state(function () use ($names)
			{
				$picked = [];
				for ($i = mt_rand(0, min(3, count($names))); $i > 0; $i--)
				{
					$picked[] = $names[mt_rand(0, count($names) - 1)];
				}

				return ['tags' => array_values(array_unique($picked))];
			});
		}

		if ($this->count('states', 0))
		{
			$hidden = function ($index)
			{
				return [[], [], ['discussion_state' => 'moderated'], [], ['deleted' => true, 'delete_reason' => 'Seeded as deleted'], []][$index % 6];
			};
			$threads = $threads->sequence($hidden);
			$replies = $replies->sequence(function ($index)
			{
				return [[], [], [], ['message_state' => 'moderated'], [], ['deleted' => true]][$index % 6];
			});
		}

		$threads->has($replies)->create();
	}

	/**
	 * A tag name of so many words that this forum would accept - how long a tag may be, and what
	 * it may not contain, is the forum's to say - and that is not one of those already chosen.
	 */
	private function tagName(int $words, array $taken): string
	{
		$repo = \XF::repository(TagRepository::class);
		$faker = $this->context()->faker;

		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$name = $repo->normalizeTag($faker->words($words, true));
			if ($repo->isValidTag($name) && !in_array($name, $taken, true))
			{
				return $name;
			}
		}

		throw new \RuntimeException('Could not find a tag name this forum would accept after 50 attempts');
	}

	private function forum()
	{
		$nodeId = $this->param('forum');
		if ($nodeId === null)
		{
			return ForumFactory::new()->create();
		}

		$forum = \XF::em()->find('XF:Forum', $nodeId);
		if (!$forum)
		{
			throw new \InvalidArgumentException("The 'forum' parameter must be the node id of a forum; there is none with id '{$nodeId}'");
		}

		return $forum->Node;
	}
}
