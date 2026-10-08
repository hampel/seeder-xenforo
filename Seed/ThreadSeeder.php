<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\PrefixFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;

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

		ThreadFactory::new()
			->count($this->count('threads', 12))
			->recycle($authors)
			->recycle($forums)
			->sequence(...$variations)
			->has(PostFactory::new()->count([0, $this->count('replies', 5)]))
			->create();
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
