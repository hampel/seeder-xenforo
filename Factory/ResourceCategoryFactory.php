<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;

/**
 * A category in XenForo Resource Manager. It has no discussion forum, so a resource posted in it
 * starts no thread; set thread_node_id for one that does.
 */
class ResourceCategoryFactory extends AbstractFactory
{
	use NeedsAddOn;

	protected $entity = 'XFRM:Category';

	private const TITLES = ['Add-ons', 'Styles', 'Guides', 'Tools', 'Templates', 'Translations', 'Graphics', 'Documentation'];

	public function definition()
	{
		return [
			'title' => self::TITLES[mt_rand(0, count(self::TITLES) - 1)],
			'description' => $this->context()->faker->sentence(),
			'display_order' => mt_rand(1, 20) * 10,
		];
	}

	public static function describe(Entity $entity): array
	{
		return ['title' => $entity->title, 'parent_category_id' => $entity->parent_category_id];
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFRM', 'Resource categories');

		return parent::newEntity($attributes);
	}
}
