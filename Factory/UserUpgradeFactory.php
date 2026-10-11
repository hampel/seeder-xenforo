<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * A user upgrade - a paid membership a member can hold for a time. **Not offered for sale**
 * (`can_purchase` is off), and by default it changes nobody's groups: use grantingGroups() for one
 * that does.
 *
 * Members are given one with UserUpgradeActiveFactory, or one that has run out with
 * UserUpgradeExpiredFactory.
 */
class UserUpgradeFactory extends AbstractFactory
{
	protected $entity = 'XF:UserUpgrade';

	private const TITLES = ['Supporter', 'Premium member', 'Gold member', 'Club member', 'Patron', 'Ad-free browsing', 'Lifetime supporter'];

	public function definition()
	{
		return [
			'title' => self::TITLES[mt_rand(0, count(self::TITLES) - 1)],
			'description' => $this->context()->faker->sentence(),
			'display_order' => mt_rand(1, 10) * 10,
			'cost_amount' => [5, 10, 20, 25, 50][mt_rand(0, 4)],
			'cost_currency' => 'usd',
			'length_amount' => 1,
			'length_unit' => ['month', 'month', 'year'][mt_rand(0, 2)],
			'can_purchase' => false,
			'payment_profile_ids' => function ()
			{
				return [$this->related(PaymentProfileFactory::new())->payment_profile_id];
			},
		];
	}

	/**
	 * @param int[] $groupIds user groups a member is in for as long as they hold the upgrade
	 *
	 * @return static
	 */
	public function grantingGroups(array $groupIds)
	{
		return $this->state(['extra_group_ids' => array_map('intval', $groupIds)]);
	}

	/** @return static one that lasts this long: a number of days, months or years */
	public function lasting(int $amount, string $unit)
	{
		return $this->state(['length_amount' => $amount, 'length_unit' => $unit]);
	}

	/** @return static one that never runs out */
	public function permanent() { return $this->state(['length_amount' => 0, 'length_unit' => '']); }

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'length' => $entity->length_unit ? "{$entity->length_amount} {$entity->length_unit}" : 'permanent',
			'extra_group_ids' => $entity->extra_group_ids,
		];
	}

	/**
	 * When an upgrade that began then would run out, or 0 for one that never does.
	 */
	public static function endOf(Entity $upgrade, int $start): int
	{
		return $upgrade->length_unit ? strtotime("+{$upgrade->length_amount} {$upgrade->length_unit}", $start) : 0;
	}
}
