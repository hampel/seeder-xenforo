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
 *         ->robotKinds(40)               // drawn from 40 different robots, a few of them most
 *         ->actions(30)                  // doing 30 different things, most of them reading threads
 *         ->robotsOn('Search::Results')  // nine robots in ten hammering one action
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

	/** how many of the pairs below a run draws from unless told otherwise: the first eleven */
	public const DEFAULT_ACTIONS = 11;

	/**
	 * What visitors are doing, and how often: [controller, action, weight, view state]. Every
	 * pair is a real controller and action. A run draws from the first few, commonest first.
	 */
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
		['XF\\Pub\\Controller\\PostController', 'Index', 3, 'valid'],
		['XF\\Pub\\Controller\\ThreadController', 'Unread', 3, 'valid'],
		['XF\\Pub\\Controller\\ThreadController', 'Latest', 3, 'valid'],
		['XF\\Pub\\Controller\\WhatsNewPostController', 'Index', 2, 'valid'],
		['XF\\Pub\\Controller\\SearchController', 'Index', 2, 'valid'],
		['XF\\Pub\\Controller\\MemberController', 'Index', 2, 'valid'],
		['XF\\Pub\\Controller\\MemberController', 'RecentContent', 2, 'valid'],
		['XF\\Pub\\Controller\\MemberController', 'About', 2, 'valid'],
		['XF\\Pub\\Controller\\ThreadController', 'Reply', 2, 'valid'],
		['XF\\Pub\\Controller\\ForumController', 'PostThread', 2, 'valid'],
		['XF\\Pub\\Controller\\TagController', 'Tag', 2, 'valid'],
		['XF\\Pub\\Controller\\TagController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\HelpController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\MiscController', 'Contact', 1, 'valid'],
		['XF\\Pub\\Controller\\OnlineController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\AccountController', 'AccountDetails', 1, 'valid'],
		['XF\\Pub\\Controller\\AccountController', 'Preferences', 1, 'valid'],
		['XF\\Pub\\Controller\\AccountController', 'Alerts', 1, 'valid'],
		['XF\\Pub\\Controller\\ConversationController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\ConversationController', 'View', 1, 'valid'],
		['XF\\Pub\\Controller\\WatchedController', 'Threads', 1, 'valid'],
		['XF\\Pub\\Controller\\CategoryController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\PageController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\LostPasswordController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\PostController', 'Reactions', 1, 'valid'],
		['XF\\Pub\\Controller\\MemberController', 'Trophies', 1, 'valid'],
		['XF\\Pub\\Controller\\ThreadController', 'Preview', 1, 'valid'],
		['XF\\Pub\\Controller\\EditorController', 'Dialog', 1, 'valid'],
		['XF\\Pub\\Controller\\MiscController', 'Style', 1, 'valid'],
		['XF\\Pub\\Controller\\ProfilePostController', 'Index', 1, 'valid'],
		['XF\\Pub\\Controller\\SearchController', 'Member', 1, 'valid'],
		['XF\\Pub\\Controller\\ForumController', 'MarkRead', 1, 'valid'],
		['XF\\Pub\\Controller\\FindThreadsController', 'Unanswered', 1, 'valid'],
		['XF\\Pub\\Controller\\ReportController', 'Index', 1, 'valid'],
	];

	/** how steeply the commonest robots outnumber the rest */
	private const SKEW = 1.4;

	/**
	 * The crawlers a forum sees most, commonest first. Names differ between XenForo's own list
	 * and the longer lists other add-ons supply, so both spellings are here; only those the forum
	 * knows are used.
	 */
	private const COMMON_ROBOTS = ['googlebot', 'google', 'bing', 'gptbot', 'openai', 'ahrefs', 'semrushbot', 'semrush',
		'bytespider', 'bytedance', 'amazonbot', 'yandexbot', 'yandex', 'applebot', 'petalbot', 'petalsearch',
		'facebookextern', 'meta-externalagent', 'anthropic', 'perplexity', 'mj12', 'dotbot', 'duckduckbot',
		'duckduckgo', 'baidu'];

	/** @var int[] */
	protected $members = [];

	/** @var array [count, networks, share in the largest] */
	protected $guests = [0, 1, 0.0];

	/** @var array [count, networks, share in the largest] */
	protected $robots = [0, 1, 0.0];

	/** @var int how many different robots the robots are drawn from */
	protected $robotKinds = 40;

	/** @var int how many different controller and action pairs visitors are spread across */
	protected $actions = self::DEFAULT_ACTIONS;

	/** @var array|null [controller, action, view state, share] the one action most robots are on */
	protected $robotFocus;

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
	 * How many different robots the robot rows are drawn from. On a real forum a handful of
	 * crawlers account for nearly all of them, so the default is few, and whatever the number the
	 * first few take most of the rows.
	 *
	 * @param int $kinds 1 or more; no more than XenForo's robot list holds are used
	 *
	 * @return static
	 */
	public function robotKinds(int $kinds)
	{
		if ($kinds < 1)
		{
			throw new \InvalidArgumentException('There must be at least one kind of robot');
		}

		$factory = clone $this;
		$factory->robotKinds = $kinds;

		return $factory;
	}

	/**
	 * How many different things visitors are doing: controller and action pairs, the commonest
	 * first, so however many there are most visitors are reading threads.
	 *
	 * @param int $kinds 1 to the number of pairs this factory knows, which actionsKnown() gives
	 *
	 * @return static
	 */
	public function actions(int $kinds)
	{
		if ($kinds < 1 || $kinds > count(self::ACTIVITY))
		{
			throw new \InvalidArgumentException('Actions must be between 1 and ' . count(self::ACTIVITY));
		}

		$factory = clone $this;
		$factory->actions = $kinds;

		return $factory;
	}

	/**
	 * Put most of the robots on one action, as a swarm that is hammering one page is. The rest
	 * are spread like everyone else.
	 *
	 * @param string $action a controller and action such as `Search::Results`, meaning
	 *                       XF\Pub\Controller\SearchController::actionResults(); or a full class
	 *                       name before the `::`, for a controller that is not XenForo's own
	 * @param float $share the share of robots on it, 0 to 1
	 *
	 * @return static
	 */
	public function robotsOn(string $action, float $share = 0.9)
	{
		if (!preg_match('/^([A-Za-z0-9_\\\\]+)::([A-Za-z0-9_]+)$/', $action, $match))
		{
			throw new \InvalidArgumentException("'{$action}' is not an action: write it as Controller::Action, such as Search::Results");
		}

		$class = strpos($match[1], '\\') === false ? "XF\\Pub\\Controller\\{$match[1]}Controller" : ltrim($match[1], '\\');
		if (!class_exists($class) || !method_exists($class, 'action' . $match[2]))
		{
			throw new \InvalidArgumentException("There is no action {$match[2]} on a controller {$class} on this forum");
		}

		$factory = clone $this;
		$factory->robotFocus = [$class, $match[2], substr($class, -15) === 'ErrorController' ? 'error' : 'valid', self::share($share, 'share')];

		return $factory;
	}

	public static function actionsKnown(): int
	{
		return count(self::ACTIVITY);
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

		$robotKeys = array_slice(self::robotKeys(), 0, $this->robotKinds);
		foreach ($this->addresses($this->robots, 128) AS $ip)
		{
			yield $this->row(0, $ip, $ip, $robotKeys ? self::skewed($robotKeys) : 'unknown', true);
		}
	}

	protected function row(int $userId, string $uniqueKey, string $ip, string $robotKey, bool $isRobot = false): array
	{
		$online = max(60, (int) \XF::options()->onlineStatusTimeout * 60);
		$kept = max($online + 120, (int) \XF::app()->config()['sessionActivityExpiration']);

		$age = $this->stale > 0 && mt_rand() / mt_getrandmax() < $this->stale
			? mt_rand($online + 1, $kept - 61)
			: mt_rand(0, $online - 1);

		if ($isRobot && $this->robotFocus && mt_rand() / mt_getrandmax() < $this->robotFocus[3])
		{
			[$controller, $action, $state] = $this->robotFocus;
		}
		else
		{
			[$controller, $action, , $state] = self::weighted(array_slice(self::ACTIVITY, 0, $this->actions), 2);
		}

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
	 * One of the items, the earlier ones far more often than the later, falling away steeply: of
	 * forty, the first five are drawn about two times in three.
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
				$total += 1 / $rank ** self::SKEW;
			}
			$totals[$n] = $total;
		}

		$target = mt_rand() / mt_getrandmax() * $totals[$n];
		$sum = 0.0;
		foreach ($items AS $index => $item)
		{
			$sum += 1 / ($index + 1) ** self::SKEW;
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
