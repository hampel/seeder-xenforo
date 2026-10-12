<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;

/**
 * A member's comment on a media item in XenForo Media Gallery. It is visible, counts towards the
 * item's comments and its category's, and is dated after the item, after the comments the item
 * already has and after its author joined - so an item's comments are in the order they were made.
 *
 * Written through the entity and not the gallery's comment service, so nobody watching the item
 * is told. Its author is drawn from the members given to recycle(), and may be the item's own.
 */
class MediaCommentFactory extends AbstractFactory
{
	use NeedsAddOn;

	protected $entity = 'XFMG:Comment';

	public function definition()
	{
		$context = $this->context();

		return [
			'content_id' => MediaFactory::new(),
			'user_id' => UserFactory::new(),
			'message' => function ()
			{
				return $this->context()->faker->paragraph(mt_rand(1, 3));
			},
			'comment_date' => function (array $attributes) use ($context)
			{
				$item = $this->item($attributes);
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				// after the item, after the comments it already has, and not before its author joined
				return $context->timeline->after(max(
					(int) $item->media_date, (int) $item->last_comment_date, $author ? $author->register_date : 0
				));
			},
		];
	}

	/** @return static awaiting approval by a moderator */
	public function moderated() { return $this->state(['comment_state' => 'moderated']); }

	public static function describe(Entity $entity): array
	{
		return ['media_id' => $entity->content_id, 'user_id' => $entity->user_id];
	}

	public static function remove(Entity $entity): void
	{
		self::silenceContent($entity);

		parent::remove($entity);
	}

	protected function newEntity(array $attributes): Entity
	{
		$item = $this->item($attributes);
		$author = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$author)
		{
			throw new \InvalidArgumentException("A comment needs an author: there is no member {$attributes['user_id']}");
		}

		$comment = $item->getNewComment();
		$this->fill($comment, ['username' => $author->username] + $attributes);

		return $comment;
	}

	protected function store(Entity $entity): void
	{
		self::silenceContent($entity);

		$entity->save();
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->comment_date;
	}

	/**
	 * Saving or removing a comment rewrites its item's comment count and its category's, which
	 * saves both.
	 */
	protected static function silenceContent(Entity $comment): void
	{
		$item = $comment->Content;
		foreach ([$item, $item ? $item->Category : null] AS $touched)
		{
			if ($touched instanceof Entity)
			{
				static::silence($touched);
			}
		}
	}

	protected function item(array $attributes): Entity
	{
		self::needs('XFMG', 'Media comments');

		$item = \XF::em()->find('XFMG:MediaItem', $attributes['content_id']);
		if (!$item)
		{
			throw new \InvalidArgumentException("A comment needs a media item: there is no media item {$attributes['content_id']}");
		}

		return $item;
	}
}
