<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Util\Ip;

/**
 * Moderator log entries about threads, posts and members, in XenForo's own words: the actions it
 * logs for each, with the parameters its phrases name, and the content's title, author and address
 * as its handlers record them.
 *
 *     ModeratorLogFactory::new()->for($thread, $post, $member)->by($moderator)->count(20)->create();
 *
 * **Written as rows, not saved as entities.** Other add-ons act on a moderator action as it is
 * taken - by extending XenForo's logger, or on each new entry the entity saves - and send it
 * somewhere. Neither happens to a row put straight into the table, and nothing in XenForo hangs
 * off one. Each row is inserted alone so that the id it was given, and no other, is recorded.
 *
 * **The log is a history, and agrees with the content as it is now**: an action that changes a
 * state is undone later unless the content is in that state now - a thread locked and unlocked,
 * or locked and still locked. No entry is dated inside the quiet period before now, before the
 * content or before the moderator joined.
 */
class ModeratorLogFactory extends AbstractBulkFactory
{
	protected $entity = 'XF:ModeratorLog';

	/** @var Entity[] */
	private $content = [];

	/** @var User[] */
	private $moderators = [];

	/** @var int */
	private $count = 1;

	/**
	 * A factory for a moderator who can be in this log: one who registered before the quiet
	 * period began, since an entry can be dated neither inside it nor before its moderator joined.
	 */
	public static function moderator(): UserFactory
	{
		return UserFactory::new()->state([
			'is_moderator' => true,
			'is_staff' => true,
			'register_date' => function ()
			{
				return min(\Hampel\Seeder\Context::current()->timeline->at(), self::latest() - 86400);
			},
		]);
	}

	/**
	 * About these threads, posts and members, in turn.
	 *
	 * @return static
	 */
	public function for(Entity ...$content)
	{
		foreach ($content AS $entity)
		{
			if (!in_array($entity->structure()->shortName, ['XF:Thread', 'XF:Post', 'XF:User'], true))
			{
				throw new \InvalidArgumentException('A moderator log entry is about a thread, a post or a member, not ' . $entity->structure()->shortName);
			}
			$this->content[] = $entity;
		}

		return $this;
	}

	/**
	 * By these moderators, drawn at random. One is made if none is given.
	 *
	 * @return static
	 */
	public function by(User ...$moderators)
	{
		$this->moderators = array_merge($this->moderators, $moderators);

		return $this;
	}

	/**
	 * About how many entries: an action and its undoing are written together, so one more can be.
	 *
	 * @return static
	 */
	public function count(int $count)
	{
		$this->count = $count;

		return $this;
	}

	protected function rows(): iterable
	{
		if (!$this->content)
		{
			throw new \InvalidArgumentException('A moderator log entry needs something to be about: name it with for()');
		}
		$moderators = $this->moderators ?: [self::moderator()->create()];

		$written = 0;
		$order = $this->content;
		shuffle($order);
		for ($attempts = 0; $written < $this->count && $attempts < $this->count * 10; $attempts++)
		{
			$content = $order[$attempts % count($order)];
			$moderator = $moderators[mt_rand(0, count($moderators) - 1)];

			foreach ($this->event($content, $moderator) AS $row)
			{
				$written++;
				yield $row;
			}
		}

		if (!$written)
		{
			throw new \RuntimeException('Nothing named was old enough to have a moderator log entry outside the quiet period');
		}
	}

	/**
	 * One thing a moderator did to the content: one row, or an action and its undoing.
	 *
	 * @return array[] rows, oldest first
	 */
	protected function event(Entity $content, User $moderator): array
	{
		$faker = $this->context()->faker;
		$reason = function () use ($faker) { return rtrim($faker->sentence(mt_rand(3, 7)), '.'); };

		switch ($content->structure()->shortName)
		{
			case 'XF:Thread':
				$type = 'thread';
				$since = $content->post_date;
				$events = [[['edit', []]], [['title', ['old' => $faker->threadTitle()]]], [['move', ['from' => ucfirst($faker->words(2, true))]]]];
				$events[] = $content->discussion_open ? [['lock', []], ['unlock', []]] : [['lock', []]];
				$events[] = $content->sticky ? [['stick', []]] : [['stick', []], ['unstick', []]];
				$events[] = $content->discussion_state === 'deleted'
					? [['delete_soft', ['reason' => $this->deletionReason($content) ?? $reason()]]]
					: [['delete_soft', ['reason' => $reason()]], ['undelete', []]];
				if ($content->discussion_state === 'visible')
				{
					$events[] = [['unapprove', []], ['approve', []]];
				}
				break;

			case 'XF:Post':
				$type = 'post';
				$since = $content->post_date;
				$events = [[['edit', []]]];
				$events[] = $content->message_state === 'deleted'
					? [['delete_soft', ['reason' => $this->deletionReason($content) ?? $reason()]]]
					: [['delete_soft', ['reason' => $reason()]], ['undelete', []]];
				if ($content->message_state === 'visible')
				{
					$events[] = [['unapprove', []], ['approve', []]];
				}
				break;

			default:
				$type = 'user';
				$since = $content->register_date;
				$events = $this->memberEvents($content, $moderator);
				break;
		}

		$event = $events[mt_rand(0, count($events) - 1)];
		$actor = $event[0][2] ?? $moderator;
		$from = max($since, $actor->register_date);
		$to = self::latest();
		if ($from > $to)
		{
			return [];
		}

		$dates = [];
		foreach ($event AS $step)
		{
			// a date of its own still comes after the moderator joined
			$dates[] = isset($step[3]) ? max($step[3], $from) : mt_rand($from, $to);
		}
		sort($dates);
		if (end($dates) > $to)
		{
			return [];
		}

		$rows = [];
		foreach ($event AS $i => $step)
		{
			$rows[] = $this->row($type, $content, $step[0], $step[1], $actor, $dates[$i]);
		}

		return $rows;
	}

	/**
	 * What a moderator does to a member is about how they came to be as they are: approved from
	 * the queue, rejected from it, or a change of name approved.
	 */
	protected function memberEvents(User $member, User $moderator): array
	{
		if ($member->user_state === 'rejected' && $member->Reject)
		{
			$by = $member->Reject->reject_user_id ? \XF::em()->find('XF:User', $member->Reject->reject_user_id) : null;
			// a rejection by the spam check is not a moderator's
			if ($by)
			{
				return [[['rejected', ['reason' => $member->Reject->reject_reason], $by, $member->Reject->reject_date]]];
			}
		}

		$events = [];
		if ($member->user_state === 'valid')
		{
			$events[] = [['approved', [], null, $member->register_date + mt_rand(600, 2 * 86400)]];
		}

		$changes = \XF::db()->fetchAll(
			"SELECT * FROM xf_username_change WHERE user_id = ? AND change_state = 'approved' AND moderator_user_id > 0", $member->user_id
		);
		foreach ($changes AS $change)
		{
			$by = \XF::em()->find('XF:User', $change['moderator_user_id']);
			if ($by)
			{
				$events[] = [['username_change_approved', ['old' => $change['old_username'], 'new' => $change['new_username']], $by, (int) $change['change_date']]];
			}
		}

		return $events ?: [[['approved', [], null, $member->register_date + mt_rand(600, 2 * 86400)]]];
	}

	/**
	 * A row as XenForo's logger and the content type's handler fill one.
	 */
	protected function row(string $type, Entity $content, string $action, array $params, User $moderator, int $date): array
	{
		$router = \XF::app()->router('public');
		switch ($type)
		{
			case 'thread':
				[$title, $url, $discussionType, $discussionId] = [$content->title, $router->buildLink('nopath:threads', $content), 'thread', $content->thread_id];
				break;
			case 'post':
				[$title, $url, $discussionType, $discussionId] = [$content->Thread ? $content->Thread->title : '', $router->buildLink('nopath:posts', $content), 'thread', $content->thread_id];
				break;
			default:
				[$title, $url, $discussionType, $discussionId] = [$content->username, $router->buildLink('nopath:members', $content), 'user', $content->user_id];
		}

		$address = IpHistory::pick(IpHistory::addressesFor($this->context(), $moderator->user_id));

		return [
			'log_date' => $date,
			'user_id' => $moderator->user_id,
			'ip_address' => Ip::stringToBinary($address, false),
			'content_type' => $type,
			'content_id' => $content->getEntityId(),
			'content_user_id' => (int) $content->user_id,
			'content_username' => (string) $content->username,
			'content_title' => mb_substr((string) $title, 0, 150),
			'content_url' => $url,
			'discussion_content_type' => $discussionType,
			'discussion_content_id' => (int) $discussionId,
			'action' => $action,
			'action_params' => json_encode($params),
		];
	}

	/**
	 * One at a time, so the id each insert was given is the one recorded - never a guess that
	 * another writer to the table could have taken.
	 */
	protected function write(array $rows): int
	{
		return $this->insertEach($rows);
	}

	private function deletionReason(Entity $content): ?string
	{
		$reason = \XF::db()->fetchOne(
			'SELECT delete_reason FROM xf_deletion_log WHERE content_type = ? AND content_id = ?',
			[$content->getEntityContentType(), $content->getEntityId()]
		);

		return $reason !== false && $reason !== '' ? $reason : null;
	}

	/**
	 * The newest moment an entry may carry.
	 */
	private static function latest(): int
	{
		return time() - ErrorLogFactory::quiet();
	}
}
