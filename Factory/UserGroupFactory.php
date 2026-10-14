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

	/** @var \WeakMap the permissions each unsaved group is to grant once it has an id */
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
	 * The permissions as XenForo's permission service takes them, or an exception naming what the
	 * forum does not have or would not take.
	 */
	public static function checkPermissions(array $permissions): array
	{
		$available = \XF::repository('XF:Permission')->getPermissionsGrouped();

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
					if (!in_array($value, ['allow', 'deny'], true))
					{
						throw new \InvalidArgumentException("'{$groupId}.{$permissionId}' takes 'allow' or 'deny'");
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

		return ['title' => $entity->title, 'permissions' => $granted];
	}

	protected function newEntity(array $attributes): Entity
	{
		$permissions = $attributes['permissions'] ?? [];
		unset($attributes['permissions']);

		$group = parent::newEntity($attributes);

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$group] = $permissions;

		return $group;
	}

	protected function store(Entity $entity): void
	{
		$entity->save();

		$permissions = self::$pending[$entity] ?? [];
		if (!$permissions)
		{
			return;
		}

		try
		{
			// a new group is in no member's permission combination yet, so nothing is rebuilt here:
			// each member's is built as they join it
			\XF::service(UpdatePermissionsService::class)
				->setUserGroup($entity)
				->setGlobal()
				->updatePermissions($permissions);
		}
		catch (\Throwable $e)
		{
			// the group is saved and not yet recorded: left now, nothing would ever remove it
			static::remove($entity);

			throw $e;
		}
	}
}
