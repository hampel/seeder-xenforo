<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Mvc\Entity\Entity;

/**
 * For a factory whose entity is a node: XenForo keeps a node of any type as a row of `xf_node`
 * and a row of that type's own table, saved together.
 */
trait BuildsNodes
{
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
