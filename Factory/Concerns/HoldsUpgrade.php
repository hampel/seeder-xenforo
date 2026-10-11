<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Entity\User;
use XF\Entity\UserUpgrade;
use XF\Mvc\Entity\Entity;

/**
 * What the two factories for a member's upgrade - one still held, one run out - share.
 */
trait HoldsUpgrade
{
	/** @var \WeakMap what each unsaved record still needs when it is stored */
	private static $pending;

	/**
	 * Written through XenForo's own upgrade service and repository instead of the entities, so
	 * that whatever other add-ons do when a member is upgraded or an upgrade runs out happens
	 * for these too. **That can include sending the member's details somewhere else**, which is
	 * why it has to be asked for.
	 *
	 * @return static
	 */
	public function withHooks()
	{
		return $this->state(['hooks' => true]);
	}

	protected function parties(array $attributes): array
	{
		$user = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$user)
		{
			throw new \InvalidArgumentException("An upgrade needs a member: there is no member {$attributes['user_id']}");
		}

		$upgrade = \XF::em()->find('XF:UserUpgrade', $attributes['user_upgrade_id']);
		if (!$upgrade)
		{
			throw new \InvalidArgumentException("There is no user upgrade {$attributes['user_upgrade_id']}");
		}

		return [$user, $upgrade];
	}

	/**
	 * The record of what was bought, as XenForo keeps it beside each member's upgrade.
	 */
	protected static function terms(UserUpgrade $upgrade): array
	{
		return [
			'cost_amount' => $upgrade->cost_amount,
			'cost_currency' => $upgrade->cost_currency,
			'length_amount' => $upgrade->length_amount,
			'length_unit' => $upgrade->length_unit,
		];
	}

	protected function remember(Entity $entity, User $user, UserUpgrade $upgrade, array $attributes): void
	{
		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$entity] = ['user' => $user, 'upgrade' => $upgrade, 'hooks' => !empty($attributes['hooks'])] + $attributes;
	}

	protected function clockFor(Entity $entity): ?int
	{
		return (int) self::$pending[$entity]['start_date'];
	}
}
