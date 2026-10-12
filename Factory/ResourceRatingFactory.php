<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;

/**
 * A member's rating of a resource in XenForo Resource Manager, with a written review unless
 * withoutReview() is used. It is of the resource's current version, counts towards the
 * resource's rating and its review count, and is published to the news feed as a real one is.
 *
 * Written through the entity and not the Resource Manager's rating service, which alerts the
 * resource's author. A seeded rating alerts nobody.
 *
 * The member rating is drawn from those given to recycle(), leaving out the resource's author
 * and anyone who has rated it already - an author does not rate their own resource, and a member
 * rates one once. If nobody is left, a new member is made.
 */
class ResourceRatingFactory extends AbstractFactory
{
	use NeedsAddOn;

	protected $entity = 'XFRM:ResourceRating';

	public function definition()
	{
		$context = $this->context();

		return [
			'resource_id' => ResourceFactory::new(),
			'user_id' => function (array $attributes)
			{
				return $this->pickRater($attributes);
			},
			// as ratings are: mostly good, and now and then not
			'rating' => function ()
			{
				return [5, 5, 5, 5, 4, 4, 4, 3, 2, 1][mt_rand(0, 9)];
			},
			// a review is plain text
			'message' => function ()
			{
				return $this->context()->faker->paragraph(mt_rand(2, 4));
			},
			'rating_date' => function (array $attributes) use ($context)
			{
				$resource = $this->resource($attributes);
				$rater = \XF::em()->find('XF:User', $attributes['user_id']);

				// after the resource it is of, and not before the member rating had joined
				return $context->timeline->after(max((int) $resource->resource_date, $rater ? $rater->register_date : 0));
			},
		];
	}

	/** @return static a rating alone, with nothing written */
	public function withoutReview() { return $this->state(['message' => '']); }

	/** @return static with this many stars, 1 to 5 */
	public function rating(int $stars) { return $this->state(['rating' => $stars]); }

	/** @return static shown without the member's name, where the forum allows that */
	public function anonymous() { return $this->state(['is_anonymous' => true]); }

	public static function describe(Entity $entity): array
	{
		return [
			'resource_id' => $entity->resource_id,
			'user_id' => $entity->user_id,
			'rating' => $entity->rating,
			'is_review' => (bool) $entity->is_review,
		];
	}

	public static function remove(Entity $entity): void
	{
		// removing it rewrites the resource's rating and its version's, which saves both
		foreach ([$entity, $entity->Resource, $entity->Version] AS $touched)
		{
			if ($touched instanceof Entity)
			{
				static::silence($touched);
			}
		}

		$entity->delete();
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFRM', 'Resource ratings');

		$resource = $this->resource($attributes);
		if (!$resource->CurrentVersion)
		{
			throw new \InvalidArgumentException("A rating is of a resource's current version, and resource {$resource->resource_id} has none");
		}
		if (!\XF::em()->find('XF:User', $attributes['user_id']))
		{
			throw new \InvalidArgumentException("A rating needs a member: there is no member {$attributes['user_id']}");
		}

		$rating = \XF::em()->create($this->entity);
		$this->fill($rating, [
			'resource_version_id' => $resource->current_version_id,
			'version_string' => $resource->CurrentVersion->version_string,
		] + $attributes);

		return $rating;
	}

	protected function store(Entity $entity): void
	{
		// the save rewrites the resource's rating and its version's, which saves both
		foreach ([$entity->Resource, $entity->Version] AS $touched)
		{
			if ($touched instanceof Entity)
			{
				static::silence($touched);
			}
		}

		$entity->save();
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->rating_date;
	}

	protected function resource(array $attributes): Entity
	{
		self::needs('XFRM', 'Resource ratings');

		$resource = \XF::em()->find('XFRM:ResourceItem', $attributes['resource_id']);
		if (!$resource)
		{
			throw new \InvalidArgumentException("A rating needs a resource: there is no resource {$attributes['resource_id']}");
		}

		return $resource;
	}

	protected function pickRater(array $attributes): int
	{
		$taken = \XF::db()->fetchAllColumn('SELECT user_id FROM xf_rm_resource_rating WHERE resource_id = ?', $attributes['resource_id']);
		$taken[] = $this->resource($attributes)->user_id;
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
}
