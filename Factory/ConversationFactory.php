<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\Conversation\CreatorService;

/**
 * A conversation: the conversation itself, its first message, and a row for each participant.
 * Written through XenForo's own conversation creator, with its notifications switched off - no
 * alert, no email, to anyone.
 *
 * Besides the conversation's columns, the attributes are:
 *
 *     message      the first message, as BB code
 *     recipients   the ids of the members it is sent to; the starter is a participant as well
 *     start_date   when it was started, which is also the first message's date
 *
 * Replies are made with ConversationMessageFactory, usually through has().
 */
class ConversationFactory extends AbstractFactory
{
	protected $entity = 'XF:ConversationMaster';

	/** @var \WeakMap what each unsaved conversation still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$context = $this->context();

		return [
			'user_id' => UserFactory::new(),
			'recipients' => function (array $attributes)
			{
				return $this->others(1, [(int) $attributes['user_id']]);
			},
			'title' => $context->faker->threadTitle(),
			'message' => $context->faker->bbCodeMessage(['short']),
			'start_date' => function (array $attributes) use ($context)
			{
				$latest = 0;
				foreach (array_merge([$attributes['user_id']], $attributes['recipients']) AS $userId)
				{
					$user = \XF::em()->find('XF:User', $userId);
					$latest = max($latest, $user ? $user->register_date : 0);
				}

				// after everyone in it had joined, and early enough to leave room for replies
				return min($context->timeline->after($latest), max($latest, time() - 3600));
			},
		];
	}

	/**
	 * Sent to this many other members, or to these members - by entity or by id. With two
	 * recipients a conversation has three participants.
	 *
	 * @param int|iterable $recipients
	 *
	 * @return static
	 */
	public function to($recipients)
	{
		if (is_int($recipients))
		{
			return $this->state(function (array $attributes) use ($recipients)
			{
				return ['recipients' => function (array $attributes) use ($recipients)
				{
					return $this->others($recipients, [(int) $attributes['user_id']]);
				}];
			});
		}

		$ids = [];
		foreach ($recipients AS $recipient)
		{
			$ids[] = $recipient instanceof Entity ? $recipient->user_id : (int) $recipient;
		}

		return $this->state(['recipients' => $ids]);
	}

	/**
	 * @return static started by this member
	 */
	public function from(User $starter)
	{
		return $this->state(['user_id' => $starter->user_id]);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'user_id' => $entity->user_id,
			// from the table: the entity's own list leaves out whoever started it
			'participants' => array_map('intval', \XF::db()->fetchAllColumn(
				'SELECT user_id FROM xf_conversation_recipient WHERE conversation_id = ? ORDER BY user_id', $entity->conversation_id
			)),
			'first_message_id' => $entity->first_message_id,
		];
	}

	protected function newEntity(array $attributes): Entity
	{
		$starter = $this->member($attributes['user_id']);

		$recipients = [];
		foreach (array_unique(array_map('intval', (array) $attributes['recipients'])) AS $userId)
		{
			if ($userId !== $starter->user_id)
			{
				$recipients[$userId] = $this->member($userId);
			}
		}
		if (!$recipients)
		{
			throw new \InvalidArgumentException('A conversation needs at least one recipient other than whoever starts it');
		}

		$creator = \XF::asVisitor($starter, function () use ($starter, $recipients, $attributes)
		{
			$creator = \XF::service(CreatorService::class, $starter);
			$creator->setIsAutomated();
			$creator->setAutoSendNotifications(false);
			$creator->setRecipientsTrusted($recipients);
			$creator->setContent($attributes['title'], $attributes['message']);

			return $creator;
		});

		$conversation = $creator->getConversation();
		$this->fill($conversation, array_diff_key($attributes, array_flip(['user_id', 'recipients', 'title', 'message', 'start_date'])));

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$conversation] = ['service' => $creator, 'starter' => $starter, 'date' => (int) $attributes['start_date']];

		return $conversation;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$creator = $pending['service'];
		$date = $pending['date'];

		\XF::asVisitor($pending['starter'], function () use ($creator, $date)
		{
			if (!$creator->validate($errors))
			{
				throw new \RuntimeException('XenForo refused the conversation: ' . implode(' ', array_map('strval', $errors)));
			}

			// validation stamps the real time and readies the save, so the dates the conversation
			// is meant to have are forced in after it
			$conversation = $creator->getConversation();
			$conversation->set('start_date', $date, ['forceSet' => true]);
			$conversation->set('last_message_date', $date, ['forceSet' => true]);
			$creator->getMessage()->set('message_date', $date, ['forceSet' => true]);

			$creator->save();
		});

		// each participant's own row carries the date too, and one of them by a column default
		\XF::db()->update('xf_conversation_user', ['last_message_date' => $date], 'conversation_id = ?', $entity->conversation_id);
		\XF::db()->update('xf_conversation_recipient', ['last_read_date' => $date],
			'conversation_id = ? AND user_id = ?', [$entity->conversation_id, $pending['starter']->user_id]
		);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$pending[$entity]['date'];
	}

	protected function member(int $userId): User
	{
		$member = \XF::em()->find('XF:User', $userId);
		if (!$member)
		{
			throw new \InvalidArgumentException("A conversation needs its members: there is no member {$userId}");
		}

		return $member;
	}

	/**
	 * The ids of so many members other than those given: from the members handed to recycle()
	 * where there are enough, and new ones for the rest.
	 *
	 * @param int[] $except
	 *
	 * @return int[]
	 */
	protected function others(int $count, array $except): array
	{
		$free = [];
		foreach ($this->recycled['XF:User'] ?? [] AS $user)
		{
			if (!in_array($user->user_id, $except, true))
			{
				$free[$user->user_id] = $user->user_id;
			}
		}
		$free = array_values($free);

		$ids = [];
		while (count($ids) < $count)
		{
			if ($free)
			{
				$ids[] = array_splice($free, mt_rand(0, count($free) - 1), 1)[0];
			}
			else
			{
				$ids[] = UserFactory::new()->create()->user_id;
			}
		}

		return $ids;
	}
}
