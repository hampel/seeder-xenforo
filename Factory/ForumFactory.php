<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * A forum: the node and its forum row. The entity built, recorded and returned is the node, so
 * its id is the node id that threads are filed under.
 */
class ForumFactory extends AbstractFactory
{
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

	public static function describe(Entity $entity): array
	{
		return ['title' => $entity->title];
	}

	protected function newEntity(array $attributes): Entity
	{
		$node = \XF::em()->create($this->entity);
		$this->fill($node, $attributes);

		// the forum row is saved with the node, as the control panel does it
		$node->addCascadedSave($node->getDataRelationOrDefault(false));

		return $node;
	}
}
