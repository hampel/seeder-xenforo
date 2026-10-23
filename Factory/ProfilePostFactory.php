<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\WritesProfilePosts;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\ProfilePost\CreatorService;

/**
 * A message one member leaves on another's profile, written through XenForo's own profile post
 * creator - or, with status(), a status update: a member's post on their own profile.
 *
 *     ProfilePostFactory::new()->on($owner)->create(['user_id' => $author->user_id]);
 *     ProfilePostFactory::new()->on($member)->status()->create();
 *     ProfilePostFactory::new()->recycle($members)->withComments([1, 3])->count(5)->create();
 *
 * **Nobody is alerted.** XenForo alerts the profile's owner, and anyone mentioned, from the
 * creator's sendNotifications(), which is never called. The profile post's webhook is switched
 * off like every other.
 *
 * It is visible, dated after both its author and the profile's owner joined, and its address is
 * not logged. Without on() or status(), the owner and the author are drawn from the members
 * given to recycle() and are never the same member - so a status update is only ever asked for.
 */
class ProfilePostFactory extends AbstractFactory
{
	use WritesProfilePosts;

	protected $entity = 'XF:ProfilePost';

	public function definition()
	{
		$context = $this->context();

		return [
			'profile_user_id' => UserFactory::new(),
			'user_id' => function (array $attributes)
			{
				return $this->otherThan((int) $attributes['profile_user_id']);
			},
			'message_state' => 'visible',
			'message' => function ()
			{
				return $this->shortMessage();
			},
			'post_date' => function (array $attributes) use ($context)
			{
				$owner = \XF::em()->find('XF:User', $attributes['profile_user_id']);
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				// not before either of them had joined
				return $context->timeline->after(max(
					$owner ? $owner->register_date : $context->timeline->start(),
					$author ? $author->register_date : 0
				));
			},
		];
	}

	/**
	 * On this member's profile.
	 *
	 * @return static
	 */
	public function on(User $owner)
	{
		return $this->state(['profile_user_id' => $owner->user_id]);
	}

	/**
	 * A status update: written by the profile's own owner.
	 *
	 * @return static
	 */
	public function status()
	{
		return $this->state(['user_id' => function (array $attributes)
		{
			return $attributes['profile_user_id'];
		}]);
	}

	/**
	 * Comments on each profile post, by the members given to recycle() - see
	 * ProfilePostCommentFactory.
	 *
	 * @param int|int[] $count how many, or a [fewest, most] range drawn for each profile post
	 *
	 * @return static
	 */
	public function withComments($count)
	{
		return $this->has(ProfilePostCommentFactory::new()->count($count));
	}

	public static function describe(Entity $entity): array
	{
		return ['profile_user_id' => $entity->profile_user_id, 'user_id' => $entity->user_id];
	}

	protected function newEntity(array $attributes): Entity
	{
		$owner = $this->member((int) $attributes['profile_user_id'], 'a profile to be on');
		$author = $this->member((int) $attributes['user_id'], 'an author');
		if (!$owner->Profile)
		{
			throw new \InvalidArgumentException("Member {$owner->user_id} has no profile to post on");
		}

		$creator = \XF::asVisitor($author, function () use ($owner, $attributes)
		{
			$creator = \XF::service(CreatorService::class, $owner->Profile);
			$creator->logIp(false);
			$creator->setContent($attributes['message']);

			return $creator;
		});

		$profilePost = $creator->getProfilePost();
		$this->fill($profilePost, array_diff_key($attributes, array_flip(
			['profile_user_id', 'user_id', 'message', 'post_date']
		)));

		$this->pending($profilePost, [
			'service' => $creator,
			'author' => $author,
			'date' => (int) $attributes['post_date'],
			'dateColumn' => 'post_date',
		]);

		return $profilePost;
	}

	/**
	 * A member from the recycled ones who is not the profile's owner, or a new member if there
	 * is none.
	 */
	protected function otherThan(int $ownerId): int
	{
		$pool = array_values(array_filter($this->recycled['XF:User'] ?? [], function (User $member) use ($ownerId)
		{
			return $member->user_id !== $ownerId;
		}));
		if ($pool)
		{
			return $pool[mt_rand(0, count($pool) - 1)]->user_id;
		}

		return UserFactory::new()->create()->user_id;
	}
}
