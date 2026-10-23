<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * What the profile post and comment factories share: both write through one of XenForo's creator
 * services, which is built when the entity is made and saved when it is stored, and neither ever
 * calls the service's sendNotifications() - which is what alerts the profile's owner, the post's
 * author and anyone mentioned.
 */
trait WritesProfilePosts
{
	/** @var \WeakMap what each unsaved entity still needs when it is stored */
	private static $pending;

	/**
	 * Keep, or fetch, what an entity needs when it is stored.
	 */
	protected function pending(Entity $entity, ?array $data = null): array
	{
		self::$pending = self::$pending ?? new \WeakMap();
		if ($data !== null)
		{
			self::$pending[$entity] = $data;
		}

		return self::$pending[$entity];
	}

	protected function member(int $userId, string $role): User
	{
		$member = \XF::em()->find('XF:User', $userId);
		if (!$member)
		{
			throw new \InvalidArgumentException("A profile post needs {$role}: there is no member {$userId}");
		}

		return $member;
	}

	/**
	 * A short message: XenForo limits a profile post, and a comment on one, to a few hundred
	 * characters, and treats it as structured text rather than BB code.
	 */
	protected function shortMessage(): string
	{
		$faker = $this->context()->faker;

		return rtrim(mb_substr($faker->sentences(mt_rand(1, 3), true), 0, 300));
	}

	protected function store(Entity $entity): void
	{
		$pending = $this->pending($entity);
		$creator = $pending['service'];

		\XF::asVisitor($pending['author'], function () use ($creator, $entity, $pending)
		{
			if (!$creator->validate($errors))
			{
				throw new \RuntimeException('XenForo refused the profile post: ' . implode(' ', array_map('strval', $errors)));
			}

			// validation stamps the real time and readies the save, so the date it is meant to
			// have is forced in after it
			$entity->set($pending['dateColumn'], $pending['date'], ['forceSet' => true]);

			$creator->save();
		});
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $this->pending($entity)['date'];
	}
}
