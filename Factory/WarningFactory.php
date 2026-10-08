<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Clock;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Repository\WarningRepository;

/**
 * A warning given to a member, by default for a post. Written through the entity, which applies
 * the warning as XenForo does - the member's points go up, the post is marked, and any warning
 * actions the forum has set for that many points are triggered.
 *
 * It is not written through XenForo's warn service, which queues a webhook and can send the member
 * a conversation. A seeded warning tells nobody.
 *
 * A warning whose expiry has already passed is saved as it was given and then expired, so its
 * points come back off - the state a real warning of that age would be in.
 */
class WarningFactory extends AbstractFactory
{
	protected $entity = 'XF:Warning';

	private const PERIODS = ['days' => 86400, 'weeks' => 604800, 'months' => 2592000, 'years' => 31536000];

	public function definition()
	{
		$context = $this->context();

		return [
			'content_type' => 'post',
			'content_id' => PostFactory::new(),
			'user_id' => function (array $attributes)
			{
				$content = $this->content($attributes);

				return $attributes['content_type'] === 'user' ? $content->user_id : (int) $content->user_id;
			},
			'warning_user_id' => UserFactory::new()->state(['is_moderator' => true, 'is_staff' => true]),
			'warning_definition_id' => function ()
			{
				return $this->pickDefinition();
			},
			'title' => function (array $attributes) use ($context)
			{
				$definition = $this->definitionFor($attributes);

				return $definition ? (string) $definition->title : ucfirst($context->faker->words(2, true));
			},
			'points' => function (array $attributes)
			{
				$definition = $this->definitionFor($attributes);

				return $definition ? $definition->points_default : mt_rand(1, 3);
			},
			'extra_user_group_ids' => function (array $attributes)
			{
				$definition = $this->definitionFor($attributes);

				return $definition ? $definition->extra_user_group_ids : [];
			},
			'notes' => mt_rand(0, 2) ? '' : rtrim($context->faker->sentence(mt_rand(4, 9)), '.'),
			'content_title' => function (array $attributes)
			{
				return (string) \XF::repository(WarningRepository::class)
					->getWarningHandler($attributes['content_type'], true)
					->getStoredTitle($this->content($attributes));
			},
			'warning_date' => function (array $attributes) use ($context)
			{
				$content = $this->content($attributes);

				// after the content it is about; a member is warned some time after they joined
				return $context->timeline->after(
					$attributes['content_type'] === 'user' ? $content->register_date : (int) ($content->post_date ?? 0)
				);
			},
			'expiry_date' => function (array $attributes)
			{
				$definition = $this->definitionFor($attributes);
				if (!$definition || $definition->expiry_type === 'never')
				{
					return 0;
				}

				return $attributes['warning_date'] + $definition->expiry_default * self::PERIODS[$definition->expiry_type];
			},
		];
	}

	/**
	 * A warning about the member themselves, not about something they posted.
	 *
	 * @return static
	 */
	public function against(User $user)
	{
		return $this->state(['content_type' => 'user', 'content_id' => $user->user_id]);
	}

	/**
	 * @return static given by this moderator
	 */
	public function by(User $moderator)
	{
		return $this->state(['warning_user_id' => $moderator->user_id]);
	}

	/**
	 * A warning written out by the moderator, not picked from the forum's definitions.
	 *
	 * @return static
	 */
	public function custom(string $title, int $points = 1)
	{
		return $this->state(['warning_definition_id' => 0, 'title' => $title, 'points' => $points]);
	}

	/**
	 * @return static that never expires
	 */
	public function permanent()
	{
		return $this->state(['expiry_date' => 0]);
	}

	/**
	 * @return static that has already run out: a day after it was given, or sooner if it was given
	 *                less than a day ago
	 */
	public function expired()
	{
		return $this->state(function ()
		{
			return ['expiry_date' => function (array $attributes)
			{
				$given = $attributes['warning_date'];

				return $given + min(86400, max(1, intdiv(time() - $given, 2)));
			}];
		});
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => (string) $entity->title,
			'user_id' => $entity->user_id,
			'content_type' => $entity->content_type,
			'content_id' => $entity->content_id,
			'points' => $entity->points,
			'expired' => (bool) $entity->is_expired,
		];
	}

	public static function remove(Entity $entity): void
	{
		static::silenceAround($entity);
		$entity->delete();
	}

	protected function store(Entity $entity): void
	{
		static::silenceAround($entity);
		$entity->save();

		if ($entity->expiry_date && $entity->expiry_date <= time())
		{
			// it has run out since: expire it at the moment it did, as the hourly clean-up would have
			Clock::at($entity->expiry_date, function () use ($entity)
			{
				$entity->is_expired = true;
				$entity->save();
			});
		}
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->warning_date;
	}

	/**
	 * Applying or removing a warning saves the content it is about and the member it was given
	 * to. Neither of those saves may send a webhook or write the moderator log either.
	 */
	protected static function silenceAround(Entity $warning): void
	{
		static::silence($warning);

		foreach ([$warning->Content, $warning->User] AS $touched)
		{
			if ($touched instanceof Entity)
			{
				static::silence($touched);
			}
		}
	}

	protected function content(array $attributes): Entity
	{
		$content = \XF::app()->findByContentType($attributes['content_type'], $attributes['content_id']);
		if (!$content)
		{
			throw new \InvalidArgumentException(
				"A warning needs something to be about: there is no {$attributes['content_type']} {$attributes['content_id']}"
			);
		}

		return $content;
	}

	protected function definitionFor(array $attributes): ?Entity
	{
		$id = (int) $attributes['warning_definition_id'];

		return $id ? \XF::em()->find('XF:WarningDefinition', $id) : null;
	}

	/**
	 * One of the definitions given to recycle(), or failing that one of the forum's own, or
	 * failing that none - a warning the moderator wrote out.
	 */
	protected function pickDefinition(): int
	{
		$pool = $this->recycled['XF:WarningDefinition'] ?? [];
		if ($pool)
		{
			return $pool[mt_rand(0, count($pool) - 1)]->warning_definition_id;
		}

		$ids = \XF::db()->fetchAllColumn('SELECT warning_definition_id FROM xf_warning_definition ORDER BY warning_definition_id');

		return $ids ? (int) $ids[mt_rand(0, count($ids) - 1)] : 0;
	}
}
