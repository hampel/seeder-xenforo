<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\HoldsUpgrade;
use XF\Mvc\Entity\Entity;
use XF\Service\User\UpgradeService;
use XF\Service\User\UserGroupChangeService;

/**
 * A member holding a user upgrade now: the record of it, and membership of whatever groups the
 * upgrade grants.
 *
 * **Its end is always in the future**, or it has none. XenForo's own cron expires any that has
 * run out, and alerts the member when it does - so for an upgrade that is over, use
 * UserUpgradeExpiredFactory.
 *
 * Written through the entity, so nothing another add-on hooks onto an upgrade happens; see
 * withHooks(). A member can hold a given upgrade only once.
 */
class UserUpgradeActiveFactory extends AbstractFactory
{
	use HoldsUpgrade;

	protected $entity = 'XF:UserUpgradeActive';

	public function definition()
	{
		return [
			'user_id' => UserFactory::new(),
			'user_upgrade_id' => UserUpgradeFactory::new(),
			'start_date' => function (array $attributes)
			{
				[$user, $upgrade] = $this->parties($attributes);

				// late enough that it has not run out yet
				$length = $upgrade->length_unit ? UserUpgradeFactory::endOf($upgrade, 0) : 86400 * 365;
				$earliest = max($user->register_date, time() - $length + 86400);

				return mt_rand(min($earliest, time() - 60), time() - 60);
			},
			'end_date' => function (array $attributes)
			{
				[, $upgrade] = $this->parties($attributes);

				return UserUpgradeFactory::endOf($upgrade, (int) $attributes['start_date']);
			},
			'hooks' => false,
		];
	}

	/** @return static one the member has cancelled, which still runs to its end */
	public function cancelled() { return $this->state(['is_cancelled' => true]); }

	public static function describe(Entity $entity): array
	{
		return [
			'user_id' => $entity->user_id,
			'user_upgrade_id' => $entity->user_upgrade_id,
			'start_date' => $entity->start_date,
			'end_date' => $entity->end_date,
		];
	}

	/**
	 * The record does not take the member out of the upgrade's groups when it is deleted;
	 * XenForo's downgrade does that, and alerts the member. Done here without the alert.
	 */
	public static function remove(Entity $entity): void
	{
		\XF::service(UserGroupChangeService::class)->removeUserGroupChange($entity->user_id, 'userUpgrade-' . $entity->user_upgrade_id);

		parent::remove($entity);
	}

	protected function newEntity(array $attributes): Entity
	{
		[$user, $upgrade] = $this->parties($attributes);

		if (\XF::db()->fetchOne('SELECT 1 FROM xf_user_upgrade_active WHERE user_id = ? AND user_upgrade_id = ?', [$user->user_id, $upgrade->user_upgrade_id]))
		{
			throw new \InvalidArgumentException("Member {$user->user_id} already holds user upgrade {$upgrade->user_upgrade_id}; a member can hold one only once");
		}

		$end = (int) $attributes['end_date'];
		if ($end && $end <= time())
		{
			throw new \InvalidArgumentException('An upgrade still held cannot have run out already: XenForo would expire it and alert the member. '
				. 'Use UserUpgradeExpiredFactory for one that is over');
		}

		if (!empty($attributes['hooks']))
		{
			$service = \XF::service(UpgradeService::class, $upgrade, $user);
			$service->setEndDate($end);
			$active = $service->getActiveUpgrade();
		}
		else
		{
			$service = null;
			$active = \XF::em()->create('XF:UserUpgradeActive');
			$active->bulkSet(['user_id' => $user->user_id, 'user_upgrade_id' => $upgrade->user_upgrade_id, 'extra' => self::terms($upgrade), 'end_date' => $end]);
		}

		$active->start_date = (int) $attributes['start_date'];
		$this->fill($active, array_diff_key($attributes, array_flip(['user_id', 'user_upgrade_id', 'start_date', 'end_date', 'hooks'])));

		$this->remember($active, $user, $upgrade, $attributes + ['service' => $service]);

		return $active;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];

		if ($pending['hooks'])
		{
			if (!$pending['service']->upgrade())
			{
				throw new \RuntimeException('XenForo refused the upgrade: ' . implode(' ', array_map('strval', $entity->getErrors())));
			}

			return;
		}

		$entity->save();

		// the half of XenForo's upgrade service that is not the record
		\XF::service(UserGroupChangeService::class)->addUserGroupChange(
			$entity->user_id, 'userUpgrade-' . $entity->user_upgrade_id, $pending['upgrade']->extra_group_ids
		);
	}
}
