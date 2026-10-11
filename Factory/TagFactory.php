<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * A tag, as content can be filed under. XenForo works out the form of it that goes in a URL when
 * it is saved, and a run reports both.
 *
 * Threads are given tags with ThreadFactory's withTags(), which makes any that do not exist yet.
 */
class TagFactory extends AbstractFactory
{
	protected $entity = 'XF:Tag';

	public function definition()
	{
		return [
			'tag' => $this->context()->faker->unique()->word(),
		];
	}

	public static function describe(Entity $entity): array
	{
		return ['tag' => $entity->tag, 'tag_url' => $entity->tag_url];
	}
}
