<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Context;
use XF\Entity\Ip;

/**
 * The addresses members of a run come from, and the IP log rows written from them. Not a factory:
 * the rows belong to a member or a post, and go when it does.
 *
 * Each member is given one to three addresses the first time one is asked for, from the ranges
 * set aside for documentation, and keeps them for the run: the first is where most of what they
 * do comes from. About one address in four is one another member of the run already has, so some
 * members share an address and an address can lead to several members.
 */
final class IpHistory
{
	/** @var \WeakMap|null for each run: ['handed' => [address, ...], 'members' => [user id => [address, ...]]] */
	private static $runs;

	/**
	 * @return string[] the member's addresses, the one they use most first
	 */
	public static function addressesFor(Context $context, int $userId): array
	{
		self::$runs = self::$runs ?? new \WeakMap();
		$run = self::$runs[$context] ?? ['handed' => [], 'members' => []];

		if (!isset($run['members'][$userId]))
		{
			$roll = mt_rand(1, 10);
			$count = $roll <= 6 ? 1 : ($roll <= 9 ? 2 : 3);

			$addresses = [];
			while (count($addresses) < $count)
			{
				$address = $run['handed'] && mt_rand(1, 4) === 1
					? $run['handed'][mt_rand(0, count($run['handed']) - 1)]
					: $context->faker->documentationIp();
				if (!in_array($address, $addresses, true))
				{
					$addresses[] = $address;
				}
			}

			$run['members'][$userId] = $addresses;
			$run['handed'] = array_values(array_unique(array_merge($run['handed'], $addresses)));
			self::$runs[$context] = $run;
		}

		return $run['members'][$userId];
	}

	/**
	 * One of a member's addresses, the first far more often than the others.
	 */
	public static function pick(array $addresses): string
	{
		return mt_rand(1, 4) > 1 ? $addresses[0] : $addresses[mt_rand(0, count($addresses) - 1)];
	}

	/**
	 * Write one IP log row, as `IpRepository::logIp()` does, dated. The repository's own call
	 * would leave the date to its column default, which is fixed for the process.
	 */
	public static function log(int $userId, string $address, string $contentType, int $contentId, string $action, int $date): Ip
	{
		/** @var Ip $ip */
		$ip = \XF::em()->create('XF:Ip');
		$ip->bulkSet([
			'user_id' => $userId, 'ip' => $address, 'content_type' => $contentType,
			'content_id' => $contentId, 'action' => $action, 'log_date' => $date,
		]);
		$ip->save();

		return $ip;
	}

	/**
	 * Remove the rows logged for these posts. XenForo leaves a post's row when the post is deleted,
	 * for its pruning to take; the member's own go with the member.
	 *
	 * @param int[] $postIds
	 */
	public static function forgetPosts(array $postIds): void
	{
		if ($postIds)
		{
			\XF::db()->delete('xf_ip', "content_type = 'post' AND content_id IN (" . \XF::db()->quote($postIds) . ')');
		}
	}
}
