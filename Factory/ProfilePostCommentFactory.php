<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\WritesProfilePosts;
use XF\Mvc\Entity\Entity;
use XF\Service\ProfilePostComment\CreatorService;

/**
 * A comment on a profile post, written through XenForo's own comment creator, so the profile
 * post's comment count, dates and latest comments are as a forum leaves them.
 *
 *     ProfilePostCommentFactory::new()->for($profilePost)->create(['user_id' => $member->user_id]);
 *
 * **Nobody is alerted**: XenForo alerts the profile post's author, the profile's owner and the
 * others who commented from the creator's sendNotifications(), which is never called.
 *
 * It is visible and dated after the profile post, after the comments it already has and after
 * its author joined. Its author is drawn from the members given to recycle(), and may be the
 * profile post's author or the profile's owner.
 */
class ProfilePostCommentFactory extends AbstractFactory
{
	use WritesProfilePosts { store as storeProfilePost; }

	protected $entity = 'XF:ProfilePostComment';

	public function definition()
	{
		$context = $this->context();

		return [
			'profile_post_id' => ProfilePostFactory::new(),
			'user_id' => UserFactory::new(),
			'message_state' => 'visible',
			'message' => function ()
			{
				return $this->shortMessage();
			},
			'comment_date' => function (array $attributes) use ($context)
			{
				$profilePost = $this->profilePost((int) $attributes['profile_post_id']);
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				// after the profile post, after the comments it already has, and not before its
				// author joined
				return $context->timeline->after(max(
					(int) $profilePost->post_date, (int) $profilePost->last_comment_date, $author ? $author->register_date : 0
				));
			},
		];
	}

	public static function describe(Entity $entity): array
	{
		return ['profile_post_id' => $entity->profile_post_id, 'user_id' => $entity->user_id];
	}

	public static function remove(Entity $entity): void
	{
		self::silencePost($entity);

		parent::remove($entity);
	}

	protected function newEntity(array $attributes): Entity
	{
		$profilePost = $this->profilePost((int) $attributes['profile_post_id']);
		$author = $this->member((int) $attributes['user_id'], 'an author');

		$creator = \XF::asVisitor($author, function () use ($profilePost, $attributes)
		{
			$creator = \XF::service(CreatorService::class, $profilePost);
			$creator->logIp(false);
			$creator->setContent($attributes['message']);

			return $creator;
		});

		$comment = $creator->getComment();
		$this->fill($comment, array_diff_key($attributes, array_flip(
			['profile_post_id', 'user_id', 'message', 'comment_date']
		)));

		$this->pending($comment, [
			'service' => $creator,
			'author' => $author,
			'date' => (int) $attributes['comment_date'],
			'dateColumn' => 'comment_date',
		]);

		return $comment;
	}

	protected function store(Entity $entity): void
	{
		self::silencePost($entity);

		$this->storeProfilePost($entity);
	}

	/**
	 * Saving or removing a comment rewrites its profile post's comment count and latest comments,
	 * which saves the profile post.
	 */
	protected static function silencePost(Entity $comment): void
	{
		if ($comment->ProfilePost)
		{
			static::silence($comment->ProfilePost);
		}
	}

	protected function profilePost(int $profilePostId): Entity
	{
		$profilePost = \XF::em()->find('XF:ProfilePost', $profilePostId);
		if (!$profilePost)
		{
			throw new \InvalidArgumentException("A comment needs a profile post: there is no profile post {$profilePostId}");
		}

		return $profilePost;
	}
}
