<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;
use XF\Service\Conversation\ReplierService;

/**
 * A reply in a conversation, written through XenForo's own replier with its notifications
 * switched off.
 *
 * **Each reply is from a different member than the message before it**, drawn from the
 * conversation's participants, so a conversation with replies always has messages from more than
 * one author. **Each is dated strictly after the message before it**, never in the same second,
 * so anything that tells messages apart by date - "send only what is newer" - has something to
 * work on.
 *
 * Besides the message's columns, the attributes are conversation_id, user_id, message and
 * message_date.
 */
class ConversationMessageFactory extends AbstractFactory
{
	protected $entity = 'XF:ConversationMessage';

	/** @var \WeakMap what each unsaved message still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$context = $this->context();

		return [
			'conversation_id' => ConversationFactory::new(),
			'user_id' => function (array $attributes)
			{
				return $this->nextAuthor((int) $attributes['conversation_id']);
			},
			'message' => $context->faker->bbCodeMessage(['short']),
			'message_date' => function (array $attributes)
			{
				return self::after((int) \XF::db()->fetchOne(
					'SELECT last_message_date FROM xf_conversation_master WHERE conversation_id = ?', $attributes['conversation_id']
				));
			},
		];
	}

	public static function describe(Entity $entity): array
	{
		return [
			'conversation_id' => $entity->conversation_id,
			'user_id' => $entity->user_id,
			'message_date' => $entity->message_date,
		];
	}

	/**
	 * XenForo has no way to delete one message from a conversation, so nothing tidies up after
	 * one. If the conversation is still there, its counts and last message are put right.
	 */
	public static function remove(Entity $entity): void
	{
		$conversationId = $entity->conversation_id;

		static::silence($entity);
		$entity->delete();

		$db = \XF::db();
		$last = $db->fetchRow(
			'SELECT message_id, message_date, user_id, username FROM xf_conversation_message
				WHERE conversation_id = ? ORDER BY message_date DESC, message_id DESC LIMIT 1',
			$conversationId
		);
		if (!$last || !$db->fetchOne('SELECT 1 FROM xf_conversation_master WHERE conversation_id = ?', $conversationId))
		{
			return;
		}

		$latest = [
			'last_message_id' => $last['message_id'],
			'last_message_date' => $last['message_date'],
			'last_message_user_id' => $last['user_id'],
			'last_message_username' => $last['username'],
		];
		$replies = max(0, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_conversation_message WHERE conversation_id = ?', $conversationId) - 1);

		$db->update('xf_conversation_master', $latest + ['reply_count' => $replies], 'conversation_id = ?', $conversationId);
		$db->update('xf_conversation_user', $latest + ['reply_count' => $replies], 'conversation_id = ?', $conversationId);
	}

	protected function newEntity(array $attributes): Entity
	{
		$conversation = \XF::em()->find('XF:ConversationMaster', $attributes['conversation_id']);
		if (!$conversation)
		{
			throw new \InvalidArgumentException("A reply needs a conversation: there is no conversation {$attributes['conversation_id']}");
		}

		$author = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$author)
		{
			throw new \InvalidArgumentException("A reply needs an author: there is no member {$attributes['user_id']}");
		}

		$replier = \XF::asVisitor($author, function () use ($conversation, $author, $attributes)
		{
			$replier = \XF::service(ReplierService::class, $conversation, $author);
			$replier->setIsAutomated();
			$replier->setAutoSendNotifications(false);
			$replier->setMessageContent($attributes['message']);

			return $replier;
		});

		$message = $replier->getMessage();
		$this->fill($message, array_diff_key($attributes, array_flip(['conversation_id', 'user_id', 'message', 'message_date'])));

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$message] = ['service' => $replier, 'author' => $author, 'date' => (int) $attributes['message_date']];

		return $message;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$replier = $pending['service'];
		$date = $pending['date'];

		\XF::asVisitor($pending['author'], function () use ($replier, $date)
		{
			if (!$replier->validate($errors))
			{
				throw new \RuntimeException('XenForo refused the reply: ' . implode(' ', array_map('strval', $errors)));
			}

			// validation stamps the real time and readies the save, so the date is forced in after it
			$replier->getMessage()->set('message_date', $date, ['forceSet' => true]);

			$replier->save();
		});

		// the author has read up to their own message; XenForo records that against the real clock
		\XF::db()->update('xf_conversation_recipient', ['last_read_date' => $date],
			'conversation_id = ? AND user_id = ?', [$entity->conversation_id, $pending['author']->user_id]
		);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$pending[$entity]['date'];
	}

	/**
	 * A participant other than whoever wrote the last message.
	 */
	protected function nextAuthor(int $conversationId): int
	{
		$db = \XF::db();
		$last = (int) $db->fetchOne('SELECT last_message_user_id FROM xf_conversation_master WHERE conversation_id = ?', $conversationId);
		$participants = array_map('intval', $db->fetchAllColumn(
			"SELECT user_id FROM xf_conversation_recipient WHERE conversation_id = ? AND recipient_state = 'active' ORDER BY user_id",
			$conversationId
		));

		$others = array_values(array_diff($participants, [$last]));
		if (!$others)
		{
			throw new \InvalidArgumentException("Conversation {$conversationId} has nobody in it to reply");
		}

		return $others[mt_rand(0, count($others) - 1)];
	}

	/**
	 * A moment after the one given: at least a second later, usually a good deal more, and taking
	 * no more than half of what is left before now - so that however many replies follow, each
	 * still has room to be later than the last.
	 */
	protected static function after(int $last): int
	{
		$left = time() - $last;
		if ($left <= 2)
		{
			return $last + 1;
		}

		$u = mt_rand() / mt_getrandmax();

		return $last + max(1, (int) ($left * 0.5 * $u * $u));
	}
}
