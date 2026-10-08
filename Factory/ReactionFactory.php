<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;
use XF\Reaction\AbstractHandler;
use XF\Repository\ReactionRepository;

/**
 * A member's reaction to a piece of content, by default a post. Written through the entity, which
 * updates the content's reaction summary and its author's reaction score, and published to the
 * news feed as a real one is.
 *
 * It is not written through XenForo's reaction repository, which alerts the author and queues a
 * webhook. A seeded reaction alerts nobody.
 *
 * The member reacting is drawn from those given to recycle(), leaving out the content's author
 * and anyone who has reacted to it already - a member cannot react to their own content, or
 * twice. If nobody is left, a new member is made.
 */
class ReactionFactory extends AbstractFactory
{
	protected $entity = 'XF:ReactionContent';

	public function definition()
	{
		$context = $this->context();

		return [
			'content_type' => 'post',
			'content_id' => PostFactory::new(),
			'content_user_id' => function (array $attributes)
			{
				return (int) $this->handler($attributes)->getContentUserId($this->content($attributes));
			},
			'reaction_user_id' => function (array $attributes)
			{
				return $this->pickReactor($attributes);
			},
			'reaction_id' => function ()
			{
				return $this->pickReaction();
			},
			'is_counted' => function (array $attributes)
			{
				return (bool) $this->handler($attributes)->reactionsCounted($this->content($attributes));
			},
			'reaction_date' => function (array $attributes) use ($context)
			{
				$content = $this->content($attributes);
				$reactor = \XF::em()->find('XF:User', $attributes['reaction_user_id']);

				// after the content it is to, and not before the member reacting had joined
				return $context->timeline->after(max((int) ($content->post_date ?? 0), $reactor ? $reactor->register_date : 0));
			},
		];
	}

	/**
	 * @return static
	 */
	public function for(Entity $entity)
	{
		// a reaction to a thread is a reaction to its first post
		return parent::for($entity->structure()->shortName === 'XF:Thread' ? $entity->FirstPost : $entity);
	}

	/**
	 * @return static with this reaction - 1 is Like on a forum that has not changed it
	 */
	public function reaction(int $reactionId)
	{
		return $this->state(['reaction_id' => $reactionId]);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'content_type' => $entity->content_type,
			'content_id' => $entity->content_id,
			'reaction_id' => $entity->reaction_id,
			'reaction_user_id' => $entity->reaction_user_id,
		];
	}

	public static function remove(Entity $entity): void
	{
		static::silence($entity);
		if ($entity->Content instanceof Entity)
		{
			static::silence($entity->Content);
		}

		$entity->delete();
	}

	protected function store(Entity $entity): void
	{
		$content = $entity->Content;
		if ($content instanceof Entity)
		{
			// the save rewrites the content's reaction summary, which saves the content
			static::silence($content);
		}

		$entity->save();

		$handler = \XF::repository(ReactionRepository::class)->getReactionHandler($entity->content_type, true);
		if ($content && $entity->ReactionUser)
		{
			$handler->publishReactionNewsFeed($entity->ReactionUser, $entity->content_id, $content, $entity->reaction_id);

			// the feed row's date is a column default, fixed when its structure was first built
			\XF::db()->update('xf_news_feed', ['event_date' => $entity->reaction_date],
				"content_type = ? AND content_id = ? AND user_id = ? AND action = 'reaction'",
				[$entity->content_type, $entity->content_id, $entity->reaction_user_id]
			);
		}
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->reaction_date;
	}

	protected function handler(array $attributes): AbstractHandler
	{
		return \XF::repository(ReactionRepository::class)->getReactionHandler($attributes['content_type'], true);
	}

	protected function content(array $attributes): Entity
	{
		$content = $this->handler($attributes)->getContent($attributes['content_id']);
		if (!$content)
		{
			throw new \InvalidArgumentException(
				"A reaction needs something to react to: there is no {$attributes['content_type']} {$attributes['content_id']}"
			);
		}

		return $content;
	}

	protected function pickReactor(array $attributes): int
	{
		$taken = \XF::db()->fetchAllColumn(
			'SELECT reaction_user_id FROM xf_reaction_content WHERE content_type = ? AND content_id = ?',
			[$attributes['content_type'], $attributes['content_id']]
		);
		$taken[] = $attributes['content_user_id'];
		$taken = array_flip(array_map('intval', $taken));

		$free = [];
		foreach ($this->recycled['XF:User'] ?? [] AS $user)
		{
			if (!isset($taken[$user->user_id]))
			{
				$free[] = $user->user_id;
			}
		}

		return $free ? $free[mt_rand(0, count($free) - 1)] : UserFactory::new()->create()->user_id;
	}

	/**
	 * One of the forum's active reactions: usually the first, which is Like unless the forum
	 * changed it, and otherwise any of them.
	 */
	protected function pickReaction(): int
	{
		$ids = array_map('intval', \XF::db()->fetchAllColumn(
			'SELECT reaction_id FROM xf_reaction WHERE active = 1 ORDER BY display_order, reaction_id'
		));
		if (!$ids)
		{
			throw new \RuntimeException('This forum has no active reactions to give');
		}

		return mt_rand(1, 10) <= 6 ? $ids[0] : $ids[mt_rand(0, count($ids) - 1)];
	}
}
