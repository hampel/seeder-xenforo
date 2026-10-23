<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\PaymentProfileFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserUpgradeActiveFactory;
use Hampel\Seeder\Factory\UserUpgradeExpiredFactory;
use Hampel\Seeder\Factory\UserUpgradeFactory;
use XF\Mvc\Entity\ArrayCollection;

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
 *     purchasable  how many of the upgrades made are offered for sale, though none can be
 *                bought (0)
 *
 * The upgrades put nobody in a group, and none can be bought: the payment profile is switched off.
 * Nobody is alerted or mailed.
 */
class UserUpgradeSeeder extends AbstractSeeder
{
	public function run()
	{
		$count = $this->count('upgrades', 2);
		$purchasable = $this->count('purchasable', 0);
		if ($purchasable > $count)
		{
			throw new \InvalidArgumentException("purchasable cannot be more than upgrades, which is {$count}");
		}

		if ($count)
		{
			// one payment profile between them: each upgrade has to name one, and one is enough
			$factory = UserUpgradeFactory::new()->recycle(PaymentProfileFactory::new()->create());
			$upgrades = new ArrayCollection(array_merge(
				$factory->purchasable()->count($purchasable)->create()->toArray(),
				$factory->count($count - $purchasable)->create()->toArray()
			));
		}
		else
		{
			$upgrades = \XF::finder('XF:UserUpgrade')->fetch();
		}
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
		$notices = [];
		if ($this->count('hooks', 0))
		{
			$notices[] = 'hooks=1: upgrades go through XenForo\'s upgrade service, so anything another add-on does when a member '
				. 'is upgraded or an upgrade runs out will happen for each of them - including sending the member\'s details elsewhere.';
		}
		if ($this->count('purchasable', 0))
		{
			$notices[] = 'purchasable: upgrades are offered for sale on this forum, with a purchase button, until the batch is cleaned. '
				. 'A purchase cannot complete: the payment profile they name is switched off.';
		}

		return $notices;
	}
}
