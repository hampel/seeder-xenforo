<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\SearchForumFactory;

/**
 * Search forums, each listing the threads of one forum, sorted differently from one to the next.
 *
 * Parameters:
 *
 *     search_forums   how many (2)
 *     forum           the node id of an existing forum to list the threads of; if omitted a new
 *                     forum is made and given threads by the Thread seeder, whose parameters
 *                     apply
 *
 * A seeder that calls this one may pass `forum_pool`, a collection of existing forum nodes, in
 * place of forum.
 */
class SearchForumSeeder extends AbstractSeeder
{
	public function run()
	{
		$forums = $this->param('forum_pool');
		if ($forums === null)
		{
			$nodeId = $this->param('forum');
			if ($nodeId === null)
			{
				$forums = [ForumFactory::new()->create()];
				$this->call(ThreadSeeder::class, ['forum_pool' => $forums]);
			}
			else
			{
				$forum = \XF::em()->find('XF:Forum', $nodeId);
				if (!$forum)
				{
					throw new \InvalidArgumentException("The 'forum' parameter must be the node id of a forum; there is none with id '{$nodeId}'");
				}
				$forums = [$forum->Node];
			}
		}

		SearchForumFactory::new()
			->count($this->count('search_forums', 2))
			->searching($forums)
			->sequence(
				[],
				['sort_order' => 'title', 'sort_direction' => 'asc'],
				['sort_order' => 'reply_count'],
				['sort_order' => 'post_date']
			)
			->create();
	}
}
