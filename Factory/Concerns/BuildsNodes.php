<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Mvc\Entity\Entity;

/**
 * For a factory whose entity is a node: XenForo keeps a node of any type as a row of `xf_node`
 * and a row of that type's own table, saved together.
 *
 * **A node is placed in the tree as soon as it is saved.** XenForo works out where a node sits -
 * its `lft`, `rgt` and depth - at the end of the request, and until then a new node has none, so
 * it rejects any node given it as a parent ("Please select a valid parent"). The control panel
 * saves one node per request and never meets that; a run makes a category and then the forums in
 * it, so it does the work at once.
 */
trait BuildsNodes
{
	/**
	 * Inside this category, forum or other node.
	 *
	 * @param Entity $parent a node, or the category or forum behind one
	 *
	 * @return static
	 */
	public function in(Entity $parent)
	{
		$node = $parent->structure()->shortName === 'XF:Node' ? $parent : ($parent->Node ?? null);
		if (!$node || !$node->node_id)
		{
			throw new \InvalidArgumentException('A node goes inside a saved category, forum or other node');
		}

		return $this->state(['parent_node_id' => $node->node_id]);
	}

	protected function store(Entity $entity): void
	{
		$entity->save();
		$this->placeInTree($entity);
	}

	/**
	 * Run XenForo's deferred rebuild of the node tree now, and give the saved node the place it
	 * was given, so that a node can be made inside it straight away.
	 */
	protected function placeInTree(Entity $node): void
	{
		\XF::triggerRunOnce();

		$row = \XF::db()->fetchRow('SELECT lft, rgt, depth, breadcrumb_data FROM xf_node WHERE node_id = ?', $node->node_id);
		if ($row)
		{
			foreach (['lft', 'rgt', 'depth'] AS $column)
			{
				$node->setAsSaved($column, (int) $row[$column]);
			}
			$node->setAsSaved('breadcrumb_data', json_decode((string) $row['breadcrumb_data'], true) ?: []);
		}
	}

	/**
	 * A node with its type's row beside it, saved with it as the control panel does it. An
	 * attribute the node has no column for goes to the type's row where that has one.
	 */
	protected function newNode(array $attributes): Entity
	{
		$node = \XF::em()->create('XF:Node');
		$node->node_type_id = $attributes['node_type_id'];

		$data = $node->getDataRelationOrDefault(false);
		foreach ($attributes AS $key => $value)
		{
			if (!$node->isValidColumn($key) && $data->isValidColumn($key))
			{
				$data->set($key, $value);
				unset($attributes[$key]);
			}
		}

		$this->fill($node, $attributes);
		$node->addCascadedSave($data);

		return $node;
	}
}
