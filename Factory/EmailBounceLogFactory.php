<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * An entry in the email bounce log, written as data: what XenForo records for each message its
 * bounce handler reads from the bounce mailbox. Nothing is read from a mailbox and nothing is
 * sent - the forum gains a row that says a message came back.
 *
 * By default each entry is a hard bounce of mail to a new member whose mail bounced, so the member
 * is in the state XenForo's action on a hard bounce leaves them in. Give the member with `for()`;
 * `soft()` is a bounce XenForo counts towards its soft bounce threshold, and `messageType()` a
 * delay, an automatic reply or something it could not make out, on which it takes no action.
 * `unmatched()` came back for an address no member has.
 *
 * Every entry carries the delivery report it was read from, as the log's raw message. **The log
 * alone is written**: a hard bounce does not change the member's state, which the default member
 * has from `bouncing()`, and a soft one writes nothing to the soft bounce history XenForo counts.
 * **XenForo prunes the log at 30 days**, so an entry is dated within the last month - and is
 * gone at its next daily clean-up once that is past, whether or not the batch is cleaned.
 */
class EmailBounceLogFactory extends AbstractFactory
{
	protected $entity = 'XF:EmailBounceLog';

	/**
	 * The delivery status and the remote server's words for each kind: [status, diagnostic].
	 */
	private const STATUS = [
		'hard' => [
			['5.1.1', 'smtp; 550 5.1.1 <%s>: Recipient address rejected: User unknown in virtual mailbox table'],
			['5.1.1', 'smtp; 550-5.1.1 The email account that you tried to reach does not exist.'],
			['5.2.1', 'smtp; 550 5.2.1 <%s>: Mailbox disabled for this recipient'],
			['5.1.2', 'smtp; 550 5.1.2 Host unknown (Name server: mx.example.net: host not found)'],
		],
		'soft' => [
			['4.2.2', 'smtp; 452 4.2.2 <%s>: Mailbox full, try again later'],
			['5.2.2', 'smtp; 552 5.2.2 <%s>: Quota exceeded'],
			['4.4.1', 'smtp; 421 4.4.1 Connection timed out'],
		],
	];

	public function definition()
	{
		return [
			'user' => function (array $attributes)
			{
				// in the state a hard bounce leaves them in; anything else leaves them as they were
				return $attributes['action_taken'] === 'hard' ? UserFactory::new()->bouncing()->create() : UserFactory::new()->create();
			},
			'user_id' => function (array $attributes)
			{
				return $attributes['user'] ? $attributes['user']->user_id : null;
			},
			'recipient' => function (array $attributes)
			{
				return $attributes['user'] ? $attributes['user']->email : 'no.such.member@example.org';
			},
			'message_type' => 'bounce',
			'action_taken' => 'hard',
			'kind' => function (array $attributes)
			{
				// what the status says, which XenForo's action follows
				return $attributes['action_taken'] === 'soft' ? 'soft' : 'hard';
			},
			'status' => function (array $attributes)
			{
				switch ($attributes['message_type'])
				{
					case 'bounce': return self::STATUS[$attributes['kind']][mt_rand(0, count(self::STATUS[$attributes['kind']]) - 1)];
					case 'delay': return ['4.4.7', 'smtp; 421 4.4.7 Delivery time expired, still trying'];
					default: return [null, null];
				}
			},
			'status_code' => function (array $attributes) { return $attributes['status'][0]; },
			'diagnostic_info' => function (array $attributes)
			{
				return $attributes['status'][1] === null ? null : sprintf($attributes['status'][1], $attributes['recipient']);
			},
			'email_date' => function (array $attributes)
			{
				// never before the member joined, nor after now
				$since = $attributes['user'] ? $attributes['user']->register_date + 60 : 0;

				return min(time(), max($since, SpamTriggerLogFactory::recent()));
			},
			'log_date' => function (array $attributes)
			{
				// read from the mailbox by the next run of the handler's job
				return min(time(), $attributes['email_date'] + mt_rand(60, 900));
			},
			'raw_message' => function (array $attributes)
			{
				return self::report($attributes);
			},
		];
	}

	/**
	 * Mail to this member.
	 *
	 * @param User $member
	 *
	 * @return static
	 */
	public function for(Entity $member)
	{
		if ($member->structure()->shortName !== 'XF:User')
		{
			throw new \InvalidArgumentException('An email bounce log entry is for mail to a member, not ' . $member->structure()->shortName);
		}

		return $this->state(['user' => $member]);
	}

	/**
	 * A soft bounce: the mailbox full, the server not answering.
	 *
	 * @return static
	 */
	public function soft()
	{
		return $this->state(['action_taken' => 'soft']);
	}

	/**
	 * Not a bounce: `delay`, `autoreply`, `challenge` or `unknown`. XenForo takes no action.
	 *
	 * @return static
	 */
	public function messageType(string $type)
	{
		if (!in_array($type, ['delay', 'autoreply', 'challenge', 'unknown'], true))
		{
			throw new \InvalidArgumentException("A message that is not a bounce is a delay, an autoreply, a challenge or unknown, not '{$type}'");
		}

		return $this->state(['message_type' => $type, 'action_taken' => '']);
	}

	/**
	 * For an address no member has, so XenForo matched it to nobody and took no action.
	 *
	 * @return static
	 */
	public function unmatched()
	{
		return $this->state(['user' => null, 'action_taken' => '']);
	}

	public static function describe(Entity $entity): array
	{
		return ['user_id' => $entity->user_id, 'message_type' => $entity->message_type, 'action_taken' => $entity->action_taken];
	}

	protected function newEntity(array $attributes): Entity
	{
		unset($attributes['user'], $attributes['kind'], $attributes['status']);

		return parent::newEntity($attributes);
	}

	/**
	 * The message as the bounce mailbox held it: a delivery status report for a bounce, the reply
	 * itself for anything else.
	 */
	private static function report(array $attributes): string
	{
		$date = gmdate('D, d M Y H:i:s +0000', $attributes['email_date']);
		$recipient = $attributes['recipient'];
		$id = substr(md5($recipient . $attributes['email_date']), 0, 12);

		if ($attributes['message_type'] !== 'bounce' && $attributes['message_type'] !== 'delay')
		{
			return "Return-Path: <{$recipient}>\r\nFrom: <{$recipient}>\r\nTo: <bounces@example.com>\r\nDate: {$date}\r\n"
				. "Subject: Automatic reply: New reply to watched thread\r\nAuto-Submitted: auto-replied\r\n"
				. "Content-Type: text/plain; charset=utf-8\r\n\r\nI am away until next week and will reply when I return.";
		}

		$action = $attributes['message_type'] === 'delay' ? 'delayed' : 'failed';
		return "Return-Path: <>\r\nFrom: Mail Delivery System <MAILER-DAEMON@mx.example.net>\r\nTo: <bounces@example.com>\r\n"
			. "Date: {$date}\r\nSubject: Undelivered Mail Returned to Sender\r\nAuto-Submitted: auto-replied\r\n"
			. "Content-Type: multipart/report; report-type=delivery-status; boundary=\"{$id}\"\r\n\r\n"
			. "--{$id}\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\n"
			. "This is the mail system at host mx.example.net. Your message could not be delivered to <{$recipient}>.\r\n\r\n"
			. "--{$id}\r\nContent-Type: message/delivery-status\r\n\r\n"
			. "Reporting-MTA: dns; mx.example.net\r\n\r\nFinal-Recipient: rfc822; {$recipient}\r\nAction: {$action}\r\n"
			. "Status: {$attributes['status_code']}\r\nDiagnostic-Code: {$attributes['diagnostic_info']}\r\n\r\n--{$id}--";
	}
}
