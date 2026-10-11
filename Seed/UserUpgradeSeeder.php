<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\PaymentProfileFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserUpgradeActiveFactory;
use Hampel\Seeder\Factory\UserUpgradeExpiredFactory;
use Hampel\Seeder\Factory\UserUpgradeFactory;

/**
 * User upgrades, with members who hold one now and members whose upgrade has run out.
 *
 * Parameters:
 *
 *     upgrades   how many upgrades to create (2); with 0 the forum's own are used
 *     active     how many members hold one now (5)
 *     expired    how many upgrades have run out (5)
 *     members    how many members to create for them to be among (10)
 *     hooks      1 to go through XenForo's upgrade service, so that other add-ons' reactions
 *                to an upgrade happen (0)
 *
 * The upgrades are not offered for sale and put nobody in a group. Nobody is alerted or mailed.
 */
class UserUpgradeSeeder extends AbstractSeeder
{
	public function run()
	{
		$count = $this->count('upgrades', 2);
		$upgrades = $count
			// one payment profile between them: each upgrade has to name one, and one is enough
			? UserUpgradeFactory::new()->recycle(PaymentProfileFactory::new()->create())->count($count)->create()
			: \XF::finder('XF:UserUpgrade')->fetch();
		if (!$upgrades->count())
		{
			throw new \InvalidArgumentException('This forum has no user upgrades of its own: ask for some with upgrades=N');
		}

		$active = $this->count('active', 5);
		$expired = $this->count('expired', 5);
		$members = UserFactory::new()->count(max(1, $this->count('members', 10)))->create();

		// a member can hold a given upgrade only once
		$pairs = [];
		foreach ($members AS $member)
		{
			foreach ($upgrades AS $upgrade)
			{
				$pairs[] = [$member, $upgrade];
			}
		}
		if ($active > count($pairs))
		{
			throw new \InvalidArgumentException("active cannot be more than members x upgrades, which is " . count($pairs));
		}
		shuffle($pairs);

		$hooks = (bool) $this->count('hooks', 0);

		foreach (array_splice($pairs, 0, $active) AS $i => [$member, $upgrade])
		{
			$factory = UserUpgradeActiveFactory::new()->for($member)->for($upgrade);
			$factory = $hooks ? $factory->withHooks() : $factory;
			($i % 5 === 4 ? $factory->cancelled() : $factory)->create();
		}

		// whoever is left, so that nobody both holds an upgrade and has had the same one run out
		for ($i = 0; $i < $expired; $i++)
		{
			[$member, $upgrade] = $pairs ? $pairs[$i % count($pairs)] : [$members->first(), $upgrades->first()];
			$factory = UserUpgradeExpiredFactory::new()->for($member)->for($upgrade);
			($hooks ? $factory->withHooks() : $factory)->create();
		}
	}

	public function notices(): array
	{
		if (!$this->count('hooks', 0))
		{
			return [];
		}

		return ['hooks=1: upgrades go through XenForo\'s upgrade service, so anything another add-on does when a member '
			. 'is upgraded or an upgrade runs out will happen for each of them - including sending the member\'s details elsewhere.'];
	}
}
