<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\SessionActivityFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * Who is online: members, guests and robots, in whatever numbers and however concentrated - a
 * quiet evening, or a swarm of robots from one network.
 *
 * Parameters:
 *
 *     members    how many members to create and show as online (10)
 *     guests     how many guests (100)
 *     robots     how many robots (200)
 *     networks   how many /16 networks guests come from, and how many robots come from; 1 to
 *                128 (20)
 *     top        the percentage of guests, and of robots, in their largest network; 0 spreads
 *                them evenly (0)
 *     robot_kinds how many different robots they are drawn from; a few take most of the rows (40)
 *     ipv6       the percentage of guests and robots with an IPv6 address (5)
 *     stale      the percentage of everyone seen too long ago to count as online (10)
 *
 * XenForo deletes these rows an hour after their view date, so they do not last.
 */
class OnlineSeeder extends AbstractSeeder
{
	public function run()
	{
		$networks = $this->count('networks', 20);
		$top = $this->count('top', 0) / 100;

		$members = $this->param('member_pool') ?? UserFactory::new()->count($this->count('members', 10))->create();

		SessionActivityFactory::new()
			->members($members)
			->guests($this->count('guests', 100), $networks, $top)
			->robots($this->count('robots', 200), $networks, $top)
			->robotKinds(max(1, $this->count('robot_kinds', 40)))
			->ipv6($this->count('ipv6', 5) / 100)
			->stale($this->count('stale', 10) / 100)
			->create();
	}
}
