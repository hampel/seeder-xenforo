<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\BuildsNodes;
use XF\Mvc\Entity\Entity;

/**
 * A forum: the node and its forum row. The entity built, recorded and returned is the node, so
 * its id is the node id that threads are filed under.
 *
 * An attribute that is a column of the forum row and not of the node - `forum_type_id`,
 * `allow_posting`, `moderate_threads` - is set on the forum row.
 *
 * `allowed_thread_types` is not a column: it is the list allowing() keeps, which goes into the
 * forum's type settings.
 */
class ForumFactory extends AbstractFactory
{
	use BuildsNodes;

	protected $entity = 'XF:Node';

	public function definition()
	{
		$faker = $this->context()->faker;

		return [
			'node_type_id' => 'Forum',
			'title' => ucfirst($faker->words(mt_rand(1, 3), true)),
			'description' => rtrim($faker->sentence(mt_rand(5, 10)), '.'),
			'parent_node_id' => 0,
			'display_order' => 1000 + mt_rand(1, 999),
		];
	}

	/**
	 * A forum of one of XenForo's types - `discussion`, `article`, `question`, `suggestion`, or
	 * one another add-on adds. A thread made in it is of the type that forum starts by default,
	 * and the forum has that type's settings as XenForo first offers them unless `type_config`
	 * is given.
	 *
	 * @return static
	 */
	public function type(string $forumTypeId) { return $this->state(['forum_type_id' => $forumTypeId]); }

	/** @return static */
	public function article() { return $this->type('article'); }

	/** @return static */
	public function question() { return $this->type('question'); }

	/** @return static */
	public function suggestion() { return $this->type('suggestion'); }

	/**
	 * A discussion forum that also takes threads of these types - `poll`, `article`, `question`,
	 * `suggestion` - as its *Allowed thread types* setting does. A forum left alone takes
	 * discussions only, as a new forum in XenForo does. Only a discussion forum has the setting.
	 *
	 * @return static
	 */
	public function allowing(string ...$threadTypes)
	{
		return $this->state(function (array $attributes) use ($threadTypes)
		{
			return ['allowed_thread_types' => array_values(array_unique(array_merge($attributes['allowed_thread_types'] ?? [], $threadTypes)))];
		});
	}

	public static function describe(Entity $entity): array
	{
		$described = ['title' => $entity->title];

		$forum = $entity->getData();
		if ($forum && $forum->forum_type_id !== 'discussion')
		{
			$described['forum_type_id'] = $forum->forum_type_id;
		}
		if ($forum && !empty($forum->type_config['allowed_thread_types']))
		{
			$described['allowed_thread_types'] = $forum->type_config['allowed_thread_types'];
		}

		return $described;
	}

	protected function newEntity(array $attributes): Entity
	{
		$allowed = $attributes['allowed_thread_types'] ?? [];
		unset($attributes['allowed_thread_types']);

		$type = $attributes['forum_type_id'] ?? ($allowed ? 'discussion' : null);
		if ($type !== null)
		{
			$handler = \XF::app()->forumType($type, false);
			if (!$handler)
			{
				throw new \InvalidArgumentException("'{$type}' is not a forum type this forum has");
			}

			$attributes += ['type_config' => $handler->getDefaultTypeConfig()];
		}

		if ($allowed)
		{
			if (!array_key_exists('allowed_thread_types', $attributes['type_config']))
			{
				throw new \InvalidArgumentException("Only a discussion forum takes other types of thread; a {$type} forum does not");
			}
			foreach ($allowed AS $threadType)
			{
				if (!\XF::app()->threadType($threadType, false))
				{
					throw new \InvalidArgumentException("'{$threadType}' is not a thread type this forum has");
				}
			}

			$attributes['type_config']['allowed_thread_types'] = $allowed;
		}

		return $this->newNode($attributes);
	}
}
