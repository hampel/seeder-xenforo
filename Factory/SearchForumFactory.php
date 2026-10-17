<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\BuildsNodes;
use XF\Mvc\Entity\Entity;
use XF\Repository\SearchForumRepository;

/**
 * A search forum: a node that lists the threads matching a search. The entity built, recorded
 * and returned is the node.
 *
 * Left alone it lists every visible thread on the forum; searching() narrows it to the forums
 * named. An attribute that is a column of the search forum's own row - `search_criteria`,
 * `sort_order`, `sort_direction`, `max_results`, `cache_ttl` - is set there.
 *
 * Its results are worked out as it is made, from the threads there are at that moment, so make
 * it after the threads it should list. XenForo works them out again when a page view finds them
 * older than the search forum's `cache_ttl`, which is ten minutes unless given.
 */
class SearchForumFactory extends AbstractFactory
{
	use BuildsNodes;

	protected $entity = 'XF:Node';

	public function definition()
	{
		$faker = $this->context()->faker;

		return [
			'node_type_id' => 'SearchForum',
			'title' => ucfirst($faker->words(mt_rand(1, 3), true)),
			'description' => rtrim($faker->sentence(mt_rand(5, 10)), '.'),
			'parent_node_id' => 0,
			'display_order' => 1000 + mt_rand(1, 999),
			'search_criteria' => ['discussion_state' => 'visible'],
		];
	}

	/**
	 * List the threads in these forums only.
	 *
	 * @param iterable|Entity|int $forums forum nodes, forums, or node ids
	 *
	 * @return static
	 */
	public function searching($forums)
	{
		$nodeIds = [];
		foreach ($forums instanceof Entity || !is_iterable($forums) ? [$forums] : $forums AS $forum)
		{
			$nodeIds[] = $forum instanceof Entity ? (int) $forum->node_id : (int) $forum;
		}
		if (!$nodeIds)
		{
			throw new \InvalidArgumentException('A search forum that searches forums has to be given at least one');
		}

		return $this->state(function (array $attributes) use ($nodeIds)
		{
			return ['search_criteria' => ['node_id' => $nodeIds] + ($attributes['search_criteria'] ?? [])];
		});
	}

	public static function describe(Entity $entity): array
	{
		$searchForum = $entity->getData();

		return ['title' => $entity->title, 'threads' => $searchForum ? $searchForum->discussion_count : 0];
	}

	protected function newEntity(array $attributes): Entity
	{
		return $this->newNode($attributes);
	}

	protected function store(Entity $entity): void
	{
		$entity->save();
		$this->placeInTree($entity);

		// XenForo leaves this until the end of the request; a run goes on to use the result
		\XF::repository(SearchForumRepository::class)->rebuildThreadsForSearchForum($entity->getData());
	}

	/** The results are as fresh as the moment they were worked out, not as the run's start. */
	protected function clockFor(Entity $entity): ?int
	{
		return time();
	}
}
