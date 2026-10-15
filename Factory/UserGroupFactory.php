<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;
use XF\Service\UpdatePermissionsService;

/**
 * A user group, with the permissions it grants written as the control panel writes them.
 *
 *     UserGroupFactory::new()->granting(['general' => ['bypassFloodCheck' => 'allow']])->create();
 *
 * A permission is named by its group and id, as XenForo stores it, with `'allow'` or `'deny'` for
 * one that is a flag and a number for one that is a limit (-1 is unlimited). A permission the forum
 * does not have is refused.
 *
 * `grantingOn($forum, [...])` sets the group's permissions for one forum or other node, as the
 * control panel's node permissions do: `'content_allow'`, `'reset'` or `'deny'` for a flag. Members
 * of groups with different node permissions are in different permission combinations, which is
 * what code that filters content per member has to be tested against.
 *
 * **Deleting a user group marks every style as changed and empties the forum's cached CSS** -
 * XenForo rebuilds its cache of how each group's names are shown. So cleaning a batch with a group
 * in it does that, and groups stay out of `SampleSeeder`, as an HTML widget does.
 *
 * Removing one is XenForo's own deletion: its permission entries go with it, and a job takes its
 * members out of it and then removes every permission combination no member has any more.
 */
class UserGroupFactory extends AbstractFactory
{
	protected $entity = 'XF:UserGroup';

	/** @var \WeakMap the permissions each unsaved group is to grant once it has an id:
	 *                ['global' => [...], 'nodes' => [node id => [...]]] */
	private static $pending;

	public function definition()
	{
		return [
			'title' => ucfirst($this->context()->faker->words(mt_rand(1, 2), true)) . ' members',
		];
	}

	/**
	 * Grants these permissions, as `[permission group => [permission id => value]]`. Called more
	 * than once, the later value for a permission wins.
	 *
	 * @return static
	 */
	public function granting(array $permissions)
	{
		$permissions = self::checkPermissions($permissions);

		return $this->state(function (array $attributes) use ($permissions)
		{
			return ['permissions' => array_replace_recursive($attributes['permissions'] ?? [], $permissions)];
		});
	}

	/**
	 * Grants these permissions on one forum or other node, as `[permission group => [permission id
	 * => value]]` - `'content_allow'`, `'reset'` or `'deny'` for a flag, a number for a limit. Only
	 * the permissions XenForo lets a node have are taken: the `forum` group and `general.viewNode`.
	 *
	 * @param \XF\Mvc\Entity\Entity $node a node, or the forum behind one
	 *
	 * @return static
	 */
	public function grantingOn(Entity $node, array $permissions)
	{
		if ($node->structure()->shortName !== 'XF:Node')
		{
			$node = $node->Node ?? null;
		}
		if (!$node || !$node->node_id)
		{
			throw new \InvalidArgumentException('Node permissions need a saved forum or other node');
		}

		$nodeId = $node->node_id;
		$permissions = self::checkPermissions($permissions, 'node');

		return $this->state(function (array $attributes) use ($nodeId, $permissions)
		{
			$nodes = $attributes['node_permissions'] ?? [];
			$nodes[$nodeId] = array_replace_recursive($nodes[$nodeId] ?? [], $permissions);

			return ['node_permissions' => $nodes];
		});
	}

	/**
	 * The permissions as XenForo's permission service takes them, or an exception naming what the
	 * forum does not have or would not take.
	 *
	 * @param string|null $contentType `node` for permissions on a node, null for global ones
	 */
	public static function checkPermissions(array $permissions, ?string $contentType = null): array
	{
		$available = \XF::repository('XF:Permission')->getPermissionsGrouped();
		$handler = $contentType ? \XF::repository('XF:Permission')->getPermissionHandler($contentType) : null;
		$flags = $contentType ? ['content_allow', 'reset', 'deny'] : ['allow', 'deny'];

		$checked = [];
		foreach ($permissions AS $groupId => $values)
		{
			if (!is_array($values))
			{
				throw new \InvalidArgumentException("Permissions are given as [group => [permission => value]]; '{$groupId}' is not a list");
			}

			foreach ($values AS $permissionId => $value)
			{
				$permission = $available[$groupId][$permissionId] ?? null;
				if (!$permission)
				{
					throw new \InvalidArgumentException("This forum has no permission '{$groupId}.{$permissionId}'");
				}
				if ($handler && !$handler->isValidPermission($permission))
				{
					throw new \InvalidArgumentException("'{$groupId}.{$permissionId}' is not a permission a {$contentType} can have");
				}

				if ($permission->permission_type === 'integer')
				{
					if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value)))
					{
						throw new \InvalidArgumentException("'{$groupId}.{$permissionId}' is a limit, and takes a number (-1 for unlimited)");
					}
					$checked[$groupId][$permissionId] = (int) $value;
				}
				else
				{
					if (!in_array($value, $flags, true))
					{
						throw new \InvalidArgumentException("'{$groupId}.{$permissionId}' takes '" . implode("', '", array_slice($flags, 0, -1))
							. "' or '" . end($flags) . "'" . ($contentType ? " on a {$contentType}" : ''));
					}
					$checked[$groupId][$permissionId] = $value;
				}
			}
		}

		return $checked;
	}

	/**
	 * Permissions written on one line, as a command's parameter takes them:
	 * `general.bypassFloodCheck,conversation.maxRecipients:50` - each as group and id, with `:` and
	 * a value where it is not `allow`.
	 */
	public static function parsePermissions(string $list): array
	{
		$permissions = [];
		foreach (array_filter(array_map('trim', explode(',', $list)), 'strlen') AS $item)
		{
			if (!preg_match('/^([\w-]+)\.([\w-]+)(?::(.+))?$/', $item, $match))
			{
				throw new \InvalidArgumentException("A permission is written as group.permission, with :value where it is not 'allow' - not '{$item}'");
			}
			$permissions[$match[1]][$match[2]] = $match[3] ?? 'allow';
		}

		return self::checkPermissions($permissions);
	}

	public static function describe(Entity $entity): array
	{
		$granted = [];
		foreach (\XF::db()->fetchAll('
			SELECT permission_group_id, permission_id, permission_value, permission_value_int
			FROM xf_permission_entry
			WHERE user_group_id = ? AND user_id = 0
			ORDER BY permission_group_id, permission_id
		', $entity->user_group_id) AS $entry)
		{
			$granted[$entry['permission_group_id'] . '.' . $entry['permission_id']] =
				$entry['permission_value'] === 'use_int' ? (int) $entry['permission_value_int'] : $entry['permission_value'];
		}

		$described = ['title' => $entity->title, 'permissions' => $granted];

		foreach (\XF::db()->fetchAll('
			SELECT content_id, permission_group_id, permission_id, permission_value, permission_value_int
			FROM xf_permission_entry_content
			WHERE user_group_id = ? AND user_id = 0 AND content_type = \'node\'
			ORDER BY content_id, permission_group_id, permission_id
		', $entity->user_group_id) AS $entry)
		{
			$described['node_permissions'][$entry['content_id']][$entry['permission_group_id'] . '.' . $entry['permission_id']] =
				$entry['permission_value'] === 'use_int' ? (int) $entry['permission_value_int'] : $entry['permission_value'];
		}

		return $described;
	}

	protected function newEntity(array $attributes): Entity
	{
		$pending = ['global' => $attributes['permissions'] ?? [], 'nodes' => $attributes['node_permissions'] ?? []];
		unset($attributes['permissions'], $attributes['node_permissions']);

		$group = parent::newEntity($attributes);

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$group] = $pending;

		return $group;
	}

	protected function store(Entity $entity): void
	{
		$entity->save();

		$pending = self::$pending[$entity] ?? ['global' => [], 'nodes' => []];
		if (!$pending['global'] && !$pending['nodes'])
		{
			return;
		}

		try
		{
			// a new group is in no member's permission combination yet, so nothing is rebuilt here:
			// each member's is built as they join it
			if ($pending['global'])
			{
				\XF::service(UpdatePermissionsService::class)
					->setUserGroup($entity)
					->setGlobal()
					->updatePermissions($pending['global']);
			}
			foreach ($pending['nodes'] AS $nodeId => $permissions)
			{
				\XF::service(UpdatePermissionsService::class)
					->setUserGroup($entity)
					->setContent('node', $nodeId)
					->updatePermissions($permissions);
			}
		}
		catch (\Throwable $e)
		{
			// the group is saved and not yet recorded: left now, nothing would ever remove it
			static::remove($entity);

			throw $e;
		}
	}
}
