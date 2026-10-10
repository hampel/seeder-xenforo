<?php namespace Hampel\Seeder\Factory;

use XF\Util\Ip;

/**
 * Who is online: session activity rows for members, guests and robots, written in bulk so that
 * tens of thousands take seconds.
 *
 *     SessionActivityFactory::new()
 *         ->members($users)
 *         ->guests(5000, 40)             // across 40 networks, evenly
 *         ->robots(60000, 12, 0.8)       // across 12 networks, 80% of them in one
 *         ->ipv6(0.05)
 *         ->stale(0.1)
 *         ->create();
 *
 * **Addresses come from ranges nobody is issued**, because a guest's row is keyed by its address
 * and a seeded row must never stand where a real visitor's would: guests from 10.0.0.0 to
 * 10.127.255.255, robots from 10.128.0.0 to 10.255.255.255, members from 198.18.0.0/15, and IPv6
 * from 2001:db8::/32. A "network" here is a /16: the second number of the address.
 *
 * On a public forum none of those can be a real visitor. On a development machine, where visitors
 * arrive from private addresses, 10.0.0.0/8 can be - which is why a row already in the table is
 * left alone and not recorded.
 *
 * XenForo deletes these rows itself, an hour after their view date. seeder:clean reports any that
 * have gone as already gone.
 */
class SessionActivityFactory extends AbstractBulkFactory
{
	protected $entity = 'XF:SessionActivity';

	/** the most addresses asked of one /16, which holds 65,536 */
	private const NETWORK_LIMIT = 60000;

	/** what visitors are doing, and how often: [controller, action, weight, view state] */
	private const ACTIVITY = [
		['XF\Pub\Controller\ThreadController', 'Index', 40, 'valid'],
		['XF\Pub\Controller\ForumController', 'Forum', 14, 'valid'],
		['XF\Pub\Controller\ForumController', 'List', 10, 'valid'],
		['XF\Pub\Controller\IndexController', 'Index', 8, 'valid'],
		['XF\Pub\Controller\WhatsNewController', 'Index', 6, 'valid'],
		['XF\Pub\Controller\MemberController', 'View', 6, 'valid'],
		['XF\Pub\Controller\SearchController', 'Results', 4, 'valid'],
		['XF\Pub\Controller\AttachmentController', 'Index', 4, 'valid'],
		['XF\Pub\Controller\LoginController', 'Index', 3, 'valid'],
		['XF\Pub\Controller\RegisterController', 'Index', 2, 'valid'],
		['XF\Pub\Controller\ErrorController', 'DispatchError', 3, 'error'],
	];

	private const COMMON_ROBOTS = ['googlebot', 'bing', 'gptbot', 'ahrefs', 'semrushbot', 'bytespider', 'amazonbot',
		'yandexbot', 'applebot', 'petalbot', 'facebookextern', 'mj12', 'dotbot', 'duckduckbot', 'baidu'];

	/** @var int[] */
	protected $members = [];

	/** @var array [count, networks, share in the largest] */
	protected $guests = [0, 1, 0.0];

	/** @var array [count, networks, share in the largest] */
	protected $robots = [0, 1, 0.0];

	/** @var float */
	protected $ipv6 = 0.0;

	/** @var float */
	protected $stale = 0.0;

	/**
	 * @param iterable $users the members who are online, one row each
	 *
	 * @return static
	 */
	public function members(iterable $users)
	{
		$factory = clone $this;
		foreach ($users AS $user)
		{
			$factory->members[] = (int) $user->user_id;
		}

		return $factory;
	}

	/**
	 * @param int $count how many guests
	 * @param int $networks how many /16 networks they come from, 1 to 128
	 * @param float $top the share of them in the largest network, 0 to 1; 0 spreads them evenly
	 *
	 * @return static
	 */
	public function guests(int $count, int $networks = 20, float $top = 0.0)
	{
		$factory = clone $this;
		$factory->guests = self::spread($count, $networks, $top);

		return $factory;
	}

	/**
	 * @param int $count how many robots
	 * @param int $networks how many /16 networks they come from, 1 to 128
	 * @param float $top the share of them in the largest network, 0 to 1; 0 spreads them evenly
	 *
	 * @return static
	 */
	public function robots(int $count, int $networks = 20, float $top = 0.0)
	{
		$factory = clone $this;
		$factory->robots = self::spread($count, $networks, $top);

		return $factory;
	}

	/**
	 * @param float $share the share of guests and robots with an IPv6 address, 0 to 1
	 *
	 * @return static
	 */
	public function ipv6(float $share)
	{
		$factory = clone $this;
		$factory->ipv6 = self::share($share, 'ipv6');

		return $factory;
	}

	/**
	 * @param float $share the share of everyone last seen too long ago to count as online, though
	 *                     not yet so long ago that XenForo has deleted the row; 0 to 1
	 *
	 * @return static
	 */
	public function stale(float $share)
	{
		$factory = clone $this;
		$factory->stale = self::share($share, 'stale');

		return $factory;
	}

	protected function rows(): iterable
	{
		foreach ($this->members AS $userId)
		{
			// a member's row is keyed by their id, as text
			yield $this->row($userId, (string) $userId, pack('C4', 198, 18 + mt_rand(0, 1), mt_rand(0, 255), mt_rand(1, 254)), '');
		}

		foreach ($this->addresses($this->guests, 0) AS $ip)
		{
			// a guest's row is keyed by its address
			yield $this->row(0, $ip, $ip, '');
		}

		$robotKeys = self::robotKeys();
		foreach ($this->addresses($this->robots, 128) AS $ip)
		{
			yield $this->row(0, $ip, $ip, $robotKeys ? self::skewed($robotKeys) : 'unknown');
		}
	}

	protected function row(int $userId, string $uniqueKey, string $ip, string $robotKey): array
	{
		$online = max(60, (int) \XF::options()->onlineStatusTimeout * 60);
		$kept = max($online + 120, (int) \XF::app()->config()['sessionActivityExpiration']);

		$age = $this->stale > 0 && mt_rand() / mt_getrandmax() < $this->stale
			? mt_rand($online + 1, $kept - 61)
			: mt_rand(0, $online - 1);

		[$controller, $action, , $state] = self::weighted(self::ACTIVITY, 2);

		return [
			'user_id' => $userId,
			'unique_key' => $uniqueKey,
			'ip' => $ip,
			'view_date' => time() - $age,
			'controller_name' => $controller,
			'controller_action' => $action,
			'view_state' => $state,
			'params' => '',
			'robot_key' => substr($robotKey, 0, 25),
		];
	}

	/**
	 * Binary addresses for one kind of visitor, each one different.
	 *
	 * @param array $spread [count, networks, share in the largest]
	 * @param int $base the second number of the first /16 this kind may use, inside 10.0.0.0/8
	 */
	protected function addresses(array $spread, int $base): iterable
	{
		[$count, $networks, $top] = $spread;
		if ($count < 1)
		{
			return;
		}

		$v6 = (int) round($count * $this->ipv6);
		$seen = [];
		for ($i = 0; $i < $v6; $i++)
		{
			do
			{
				$ip = Ip::stringToBinary(sprintf('2001:db8:%x:%x::%x:%x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(1, 65535)));
			}
			while (isset($seen[$ip]));

			$seen[$ip] = true;
			yield $ip;
		}

		$v4 = $count - $v6;
		$seconds = self::pick(range($base, $base + 127), $networks);
		$largest = $top > 0 ? (int) round($v4 * $top) : 0;

		foreach ($seconds AS $index => $second)
		{
			if ($top > 0)
			{
				$others = $networks - 1;
				$here = $index === 0 ? ($others ? $largest : $v4) : intdiv($v4 - $largest, $others) + ($index <= ($v4 - $largest) % $others ? 1 : 0);
			}
			else
			{
				$here = intdiv($v4, $networks) + ($index < $v4 % $networks ? 1 : 0);
			}

			if ($here > self::NETWORK_LIMIT)
			{
				throw new \InvalidArgumentException(
					"{$here} addresses were asked of one network, which holds " . self::NETWORK_LIMIT
					. ' - use more networks, or a smaller share in the largest'
				);
			}

			foreach (self::pick(range(0, 65535), $here) AS $host)
			{
				yield pack('C4', 10, $second, $host >> 8, $host & 255);
			}
		}
	}

	/**
	 * The robots XenForo can name, the ones a forum really sees most put first - the list is drawn
	 * from with a heavy skew towards its start, and alphabetical order would make an obscure
	 * crawler the commonest visitor.
	 *
	 * @return string[]
	 */
	private static function robotKeys(): array
	{
		$known = array_keys(\XF::app()->data('XF:Robot')->getRobotList());
		$common = array_values(array_intersect(self::COMMON_ROBOTS, $known));

		return array_merge($common, array_values(array_diff($known, $common)));
	}

	private static function spread(int $count, int $networks, float $top): array
	{
		if ($count < 0)
		{
			throw new \InvalidArgumentException('A count cannot be negative');
		}
		if ($networks < 1 || $networks > 128)
		{
			throw new \InvalidArgumentException('Networks must be between 1 and 128');
		}

		return [$count, $networks, self::share($top, 'top')];
	}

	private static function share(float $share, string $name): float
	{
		if ($share < 0 || $share > 1)
		{
			throw new \InvalidArgumentException("'{$name}' is a share between 0 and 1, not {$share}");
		}

		return $share;
	}

	/**
	 * @return array $count of the items, chosen without repeating, in a random order
	 */
	private static function pick(array $items, int $count): array
	{
		$last = count($items) - 1;
		$count = min($count, $last + 1);

		for ($i = 0; $i < $count; $i++)
		{
			$j = mt_rand($i, $last);
			[$items[$i], $items[$j]] = [$items[$j], $items[$i]];
		}

		return array_slice($items, 0, $count);
	}

	/**
	 * One of the items, the earlier ones far more often than the later: the second half as often
	 * as the first, the third a third as often.
	 */
	private static function skewed(array $items)
	{
		static $totals = [];

		$n = count($items);
		if (!isset($totals[$n]))
		{
			$total = 0.0;
			for ($rank = 1; $rank <= $n; $rank++)
			{
				$total += 1 / $rank;
			}
			$totals[$n] = $total;
		}

		$target = mt_rand() / mt_getrandmax() * $totals[$n];
		$sum = 0.0;
		foreach ($items AS $index => $item)
		{
			$sum += 1 / ($index + 1);
			if ($sum >= $target)
			{
				return $item;
			}
		}

		return $items[$n - 1];
	}

	/**
	 * One of the rows, chosen in proportion to the weight each carries.
	 *
	 * @param int $column which element of each row is its weight
	 */
	private static function weighted(array $rows, int $column): array
	{
		$target = mt_rand(1, array_sum(array_column($rows, $column)));
		foreach ($rows AS $row)
		{
			$target -= $row[$column];
			if ($target <= 0)
			{
				return $row;
			}
		}

		return end($rows);
	}
}
