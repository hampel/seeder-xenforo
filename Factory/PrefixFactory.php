<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;
use XF\Repository\ForumPrefixRepository;

/**
 * A thread prefix, with its title phrase. `node_ids` lists the forums it can be chosen in; a
 * thread given a prefix is filed under it whether or not its forum offers it.
 */
class PrefixFactory extends AbstractFactory
{
	protected $entity = 'XF:ThreadPrefix';

	private const STYLES = ['label label--primary', 'label label--accent', 'label label--red',
		'label label--green', 'label label--orange', 'label label--blue'];

	/** @var \WeakMap what each unsaved prefix still needs once it has an id */
	private static $pending;

	public function definition()
	{
		return [
			'title' => ucfirst($this->context()->faker->word()),
			'css_class' => self::STYLES[mt_rand(0, count(self::STYLES) - 1)],
			'allowed_user_group_ids' => [-1],
			'display_order' => 1000 + mt_rand(1, 999),
			'node_ids' => [],
		];
	}

	public static function describe(Entity $entity): array
	{
		return ['title' => (string) $entity->title];
	}

	protected function newEntity(array $attributes): Entity
	{
		$pending = ['title' => (string) $attributes['title'], 'node_ids' => (array) $attributes['node_ids']];
		unset($attributes['title'], $attributes['node_ids']);

		$prefix = parent::newEntity($attributes);

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$prefix] = $pending;

		return $prefix;
	}

	protected function store(Entity $entity): void
	{
		$entity->save();

		$pending = self::$pending[$entity];

		$phrase = $entity->getMasterPhrase();
		$phrase->phrase_text = $pending['title'];
		$phrase->save();

		\XF::repository(ForumPrefixRepository::class)->updatePrefixAssociations($entity, $pending['node_ids']);
	}
}
