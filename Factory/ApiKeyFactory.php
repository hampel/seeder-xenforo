<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * An API key: by default one that acts as a member, with every scope.
 *
 * Written through the entity, not XenForo's key manager service, which emails every super
 * administrator about a new key.
 *
 * **A seeded key is a working credential.** A run prints its value, as it prints the members'
 * password, because whatever is testing the API needs it - and a super user key can do anything
 * on the forum it was made on.
 */
class ApiKeyFactory extends AbstractFactory
{
	protected $entity = 'XF:ApiKey';

	public function definition()
	{
		$context = $this->context();

		return [
			'title' => 'Seeded key ' . $context->faker->words(2, true),
			'user_id' => UserFactory::new(),
			'is_super_user' => false,
			'allow_all_scopes' => true,
			'scopes' => [],
			'active' => true,
			'creation_user_id' => 0,
			'creation_date' => function (array $attributes) use ($context)
			{
				$member = $attributes['user_id'] ? \XF::em()->find('XF:User', $attributes['user_id']) : null;

				// a member's key was made after the member was
				return $member ? $context->timeline->after($member->register_date) : $context->timeline->at();
			},
		];
	}

	/**
	 * @return static
	 */
	public function for(Entity $entity)
	{
		// a key for a member names that member, whatever else the factory was given to recycle
		if ($entity->structure()->shortName === 'XF:User')
		{
			return $this->state(['user_id' => $entity->user_id, 'is_super_user' => false]);
		}

		return parent::for($entity);
	}

	/**
	 * A key that may act as any member, and bypass permissions when asked to.
	 *
	 * @return static
	 */
	public function superUser()
	{
		return $this->state(['is_super_user' => true, 'user_id' => 0]);
	}

	/**
	 * A key that acts as a guest.
	 *
	 * @return static
	 */
	public function guest()
	{
		return $this->state(['is_super_user' => false, 'user_id' => 0]);
	}

	/**
	 * @return static
	 */
	public function inactive()
	{
		return $this->state(['active' => false]);
	}

	/**
	 * Limited to these scopes, such as `thread:read`, instead of having all of them.
	 *
	 * @param string[] $scopes
	 *
	 * @return static
	 */
	public function scopes(array $scopes)
	{
		return $this->state(['allow_all_scopes' => false, 'scopes' => array_values($scopes)]);
	}

	/**
	 * @return static that has been used since it was made
	 */
	public function used()
	{
		return $this->state(function ()
		{
			return ['last_use_date' => function (array $attributes)
			{
				return $this->context()->timeline->after($attributes['creation_date']);
			}];
		});
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'type' => $entity->key_type,
			'user_id' => $entity->user_id,
			'active' => (bool) $entity->active,
			'api_key' => $entity->api_key,
		];
	}

	protected function newEntity(array $attributes): Entity
	{
		$scopes = (array) $attributes['scopes'];
		if ($scopes)
		{
			$known = \XF::db()->fetchAllColumn('SELECT api_scope_id FROM xf_api_scope');
			$unknown = array_diff($scopes, $known);
			if ($unknown)
			{
				throw new \InvalidArgumentException('This forum has no API scope named ' . implode(', ', $unknown));
			}
		}

		// the entity holds scopes as a map, and writes its scope rows from the map's keys
		$attributes['scopes'] = array_fill_keys($scopes, true);

		return parent::newEntity($attributes);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->creation_date;
	}
}
