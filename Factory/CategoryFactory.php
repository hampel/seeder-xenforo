<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\BuildsNodes;
use XF\Mvc\Entity\Entity;

/**
 * A category: the node and its category row. The entity built, recorded and returned is the node,
 * so its id is what forums are put inside with `ForumFactory::new()->in($category)`.
 *
 * Cleaning removes what was made in it first, so a category goes empty, as the control panel
 * would ask for. A forum the run did not make that was put inside it is moved up to the
 * category's parent by XenForo, as when a category is deleted there.
 */
class CategoryFactory extends AbstractFactory
{
	use BuildsNodes;

	protected $entity = 'XF:Node';

	public function definition()
	{
		$faker = $this->context()->faker;

		return [
			'node_type_id' => 'Category',
			'title' => ucfirst($faker->words(mt_rand(1, 3), true)),
			'description' => rtrim($faker->sentence(mt_rand(5, 10)), '.'),
			'parent_node_id' => 0,
			'display_order' => 1000 + mt_rand(1, 999),
		];
	}

	public static function describe(Entity $entity): array
	{
		$described = ['title' => $entity->title, 'node_type_id' => 'Category'];
		if ($entity->parent_node_id)
		{
			$described['parent_node_id'] = $entity->parent_node_id;
		}

		return $described;
	}

	protected function newEntity(array $attributes): Entity
	{
		return $this->newNode($attributes);
	}
}
