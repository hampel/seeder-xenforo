<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\BuildsNodes;
use XF\Mvc\Entity\Entity;

/**
 * A forum: the node and its forum row. The entity built, recorded and returned is the node, so
 * its id is the node id that threads are filed under.
 *
 * An attribute that is a column of the forum row and not of the node - `forum_type_id`,
 * `allow_posting`, `moderate_threads` - is set on the forum row.
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

	public static function describe(Entity $entity): array
	{
		$described = ['title' => $entity->title];

		$forum = $entity->getData();
		if ($forum && $forum->forum_type_id !== 'discussion')
		{
			$described['forum_type_id'] = $forum->forum_type_id;
		}

		return $described;
	}

	protected function newEntity(array $attributes): Entity
	{
		$type = $attributes['forum_type_id'] ?? null;
		if ($type !== null)
		{
			$handler = \XF::app()->forumType($type, false);
			if (!$handler)
			{
				throw new \InvalidArgumentException("'{$type}' is not a forum type this forum has");
			}

			$attributes += ['type_config' => $handler->getDefaultTypeConfig()];
		}

		return $this->newNode($attributes);
	}
}
