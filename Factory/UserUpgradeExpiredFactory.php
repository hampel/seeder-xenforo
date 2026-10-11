<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\HoldsUpgrade;
use XF\Mvc\Entity\Entity;
use XF\Repository\UserUpgradeRepository;
use XF\Service\User\UpgradeService;
use XF\Service\User\UserGroupChangeService;

/**
 * A user upgrade a member held and no longer does. It is first held and then run out, as a real
 * one is, so its record number comes from the same sequence - but nobody is alerted that it
 * ended, and the member is left in none of its groups.
 *
 * Besides the record's columns:
 *
 *     start_date          when it began
 *     original_end_date   when it was due to run out; 0 for an upgrade that never does
 *     end_date            when it did end: the due date where that has passed, otherwise some
 *                         moment before now - an upgrade ended early
 */
class UserUpgradeExpiredFactory extends AbstractFactory
{
	use HoldsUpgrade;

	protected $entity = 'XF:UserUpgradeExpired';

	public function definition()
	{
		return [
			'user_id' => UserFactory::new(),
			'user_upgrade_id' => UserUpgradeFactory::new(),
			'start_date' => function (array $attributes)
			{
				[$user] = $this->parties($attributes);

				return min($this->context()->timeline->after($user->register_date), time() - 3600);
			},
			'original_end_date' => function (array $attributes)
			{
				[, $upgrade] = $this->parties($attributes);

				return UserUpgradeFactory::endOf($upgrade, (int) $attributes['start_date']);
			},
			'end_date' => function (array $attributes)
			{
				$due = (int) $attributes['original_end_date'];

				return $due && $due < time() ? $due : mt_rand((int) $attributes['start_date'] + 60, time() - 60);
			},
			'hooks' => false,
		];
	}

	public static function describe(Entity $entity): array
	{
		return [
			'user_id' => $entity->user_id,
			'user_upgrade_id' => $entity->user_upgrade_id,
			'start_date' => $entity->start_date,
			'end_date' => $entity->end_date,
			'original_end_date' => $entity->original_end_date,
		];
	}

	protected function newEntity(array $attributes): Entity
	{
		[$user, $upgrade] = $this->parties($attributes);

		if ((int) $attributes['end_date'] > time() || (int) $attributes['end_date'] < (int) $attributes['start_date'])
		{
			throw new \InvalidArgumentException('An upgrade that has run out ended after it started and before now');
		}
		if (\XF::db()->fetchOne('SELECT 1 FROM xf_user_upgrade_active WHERE user_id = ? AND user_upgrade_id = ?', [$user->user_id, $upgrade->user_upgrade_id]))
		{
			throw new \InvalidArgumentException("Member {$user->user_id} holds user upgrade {$upgrade->user_upgrade_id} now; it cannot also be run out for them");
		}

		$expired = \XF::em()->create('XF:UserUpgradeExpired');
		$this->fill($expired, array_diff_key($attributes, array_flip(['user_id', 'user_upgrade_id', 'start_date', 'end_date', 'original_end_date', 'hooks'])));

		$this->remember($expired, $user, $upgrade, $attributes);

		return $expired;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$user = $pending['user'];
		$upgrade = $pending['upgrade'];
		$key = 'userUpgrade-' . $upgrade->user_upgrade_id;

		if ($pending['hooks'])
		{
			$service = \XF::service(UpgradeService::class, $upgrade, $user);
			$service->setEndDate((int) $pending['original_end_date']);
			$service->getActiveUpgrade()->start_date = (int) $pending['start_date'];
			$active = $service->upgrade();
			if (!$active)
			{
				throw new \RuntimeException('XenForo refused the upgrade');
			}

			// XenForo's downgrade service without its alert to the member
			\XF::service(UserGroupChangeService::class)->removeUserGroupChange($user->user_id, $key);
			\XF::repository(UserUpgradeRepository::class)->expireActiveUpgrade($active, $entity);
		}
		else
		{
			// held for a moment, for its record number; it never puts the member in a group
			$active = \XF::em()->create('XF:UserUpgradeActive');
			$active->bulkSet([
				'user_id' => $user->user_id, 'user_upgrade_id' => $upgrade->user_upgrade_id, 'extra' => self::terms($upgrade),
				'start_date' => (int) $pending['start_date'], 'end_date' => (int) $pending['original_end_date'],
			]);
			$active->save();

			$entity->bulkSet([
				'user_upgrade_record_id' => $active->user_upgrade_record_id, 'user_id' => $user->user_id,
				'user_upgrade_id' => $upgrade->user_upgrade_id, 'extra' => $active->extra,
				'start_date' => $active->start_date, 'original_end_date' => $active->end_date,
			]);
			$entity->save();
			$active->delete();
		}

		// XenForo's repository says it ended now; it ended when it was meant to have
		$entity->fastUpdate('end_date', (int) $pending['end_date']);
	}
}
