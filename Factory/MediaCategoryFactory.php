<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;

/**
 * A category in XenForo Media Gallery, of the kind that holds media items directly. Use
 * forAlbums() for one that holds albums.
 */
class MediaCategoryFactory extends AbstractFactory
{
	use NeedsAddOn;

	protected $entity = 'XFMG:Category';

	private const TITLES = ['Landscapes', 'Members\' cars', 'Events', 'Workshops', 'Wildlife', 'Meet-ups', 'Projects', 'Screenshots'];

	public function definition()
	{
		return [
			'title' => self::TITLES[mt_rand(0, count(self::TITLES) - 1)],
			'description' => $this->context()->faker->sentence(),
			'category_type' => 'media',
			'display_order' => mt_rand(1, 20) * 10,
		];
	}

	/** @return static a category that holds albums, not media items */
	public function forAlbums() { return $this->state(['category_type' => 'album']); }

	public static function describe(Entity $entity): array
	{
		return ['title' => $entity->title, 'category_type' => $entity->category_type];
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFMG', 'Media categories');

		return parent::newEntity($attributes);
	}
}
