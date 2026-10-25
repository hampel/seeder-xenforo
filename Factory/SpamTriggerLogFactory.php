<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * An entry in the spam trigger log, written as data: what XenForo records when its spam check
 * holds a registration or a message for approval, or refuses it. Nothing is checked and nothing
 * is held - the forum gains a row that says it was.
 *
 * By default each entry is about a new member's registration, held for approval: the member is
 * made awaiting approval, and registered within the last month. Give the content with `for()`:
 * an entry about a member, a thread, a reply, a profile post, a comment on one or a conversation
 * is dated with it, by its author, and was held for approval - or, for a member whose
 * registration was rejected, refused. `denied()` is about a message refused outright, which was
 * never saved and so has no content at all.
 *
 * Besides the entry's columns there is one attribute:
 *
 *     content  the member or message the entry is about, or null for one refused outright
 *
 * **XenForo keeps one entry for each piece of content**, so a second about the same is refused.
 * **And it prunes the log at 30 days**: an entry dated before that goes at its next daily
 * clean-up, whether or not the batch is cleaned.
 */
class SpamTriggerLogFactory extends AbstractFactory
{
	protected $entity = 'XF:SpamTriggerLog';

	/**
	 * Each kind of content an entry can be about: [content type, id column, date column].
	 */
	private const CONTENT = [
		'XF:User' => ['user', 'user_id', 'register_date'],
		'XF:Thread' => ['thread', 'thread_id', 'post_date'],
		'XF:Post' => ['post', 'post_id', 'post_date'],
		'XF:ProfilePost' => ['profile_post', 'profile_post_id', 'post_date'],
		'XF:ProfilePostComment' => ['profile_post_comment', 'profile_post_comment_id', 'comment_date'],
		'XF:ConversationMaster' => ['conversation', 'conversation_id', 'start_date'],
	];

	/**
	 * What XenForo's checkers say when they match, as each writes it: [phrase, data].
	 */
	private const REGISTRATION = [
		['sfs_matched_x', ['matches' => 'username: 2, email: 14, ip: 37']],
		['sfs_matched_x', ['matches' => 'email: blacklisted, ip: 6']],
		['shared_ip_banned_user_x', ['users' => 'Example Banned Member']],
		['shared_ip_rejected_user_x', ['users' => 'Example Rejected Member, Another Example']],
		['dnsbl_matched', []],
	];

	private const MESSAGE = [
		['spam_phrase_matched_x', ['phrase' => 'cheap watches']],
		['spam_phrase_matched_x', ['phrase' => 'limited time offer']],
		['akismet_matched', []],
	];

	public function definition()
	{
		return [
			'content' => function ()
			{
				return UserFactory::new()->moderated()->create(['register_date' => self::recent()]);
			},
			'content_type' => function (array $attributes)
			{
				return self::CONTENT[$attributes['content']->structure()->shortName][0];
			},
			'content_id' => function (array $attributes)
			{
				return $attributes['content']->get(self::CONTENT[$attributes['content']->structure()->shortName][1]);
			},
			'user_id' => function (array $attributes)
			{
				return (int) $attributes['content']->user_id;
			},
			'log_date' => function (array $attributes)
			{
				return $attributes['content']->get(self::CONTENT[$attributes['content']->structure()->shortName][2]);
			},
			'ip_address' => function ()
			{
				return \XF\Util\Ip::stringToBinary($this->context()->faker->documentationIp(), false);
			},
			'result' => function (array $attributes)
			{
				$content = $attributes['content'];

				return $content && $content->structure()->shortName === 'XF:User' && $content->user_state === 'rejected' ? 'denied' : 'moderated';
			},
			'details' => function (array $attributes)
			{
				$pool = $attributes['content_type'] === 'user' ? self::REGISTRATION : self::MESSAGE;
				$details = [];
				foreach ((array) array_rand($pool, mt_rand(1, 2)) AS $index)
				{
					[$phrase, $data] = $pool[$index];
					$details[] = $data ? ['phrase' => $phrase, 'data' => $data] : ['phrase' => $phrase];
				}

				return $details;
			},
			'request_state' => function (array $attributes)
			{
				return self::requestState($attributes['content_type'], $attributes['content']);
			},
		];
	}

	/**
	 * About this member's registration, or this message.
	 *
	 * @return static
	 */
	public function for(Entity $content)
	{
		if (!isset(self::CONTENT[$content->structure()->shortName]))
		{
			throw new \InvalidArgumentException('A spam trigger log entry is about a member, a thread, a reply, a profile post, a comment on one or a conversation, not '
				. $content->structure()->shortName);
		}

		return $this->state(['content' => $content]);
	}

	/**
	 * About a message refused outright, so never saved: a thread, a reply, a profile post, a
	 * comment on one or a conversation, by this member - a new one if none is given - within the
	 * last month and after they joined.
	 *
	 * @return static
	 */
	public function denied(string $contentType = 'post', ?User $by = null)
	{
		$types = array_diff(array_column(self::CONTENT, 0), ['user']);
		if (!in_array($contentType, $types, true))
		{
			throw new \InvalidArgumentException("A message refused outright is one of '" . implode("', '", $types) . "', not '{$contentType}'");
		}

		return $this->state(function () use ($contentType, $by)
		{
			$by = $by ?? UserFactory::new()->create(['register_date' => self::recent()]);

			return [
				'content' => null,
				'content_type' => $contentType,
				'content_id' => null,
				'user_id' => $by->user_id,
				'log_date' => max($by->register_date + 60, self::recent()),
				'result' => 'denied',
			];
		});
	}

	public static function describe(Entity $entity): array
	{
		return ['content_type' => $entity->content_type, 'content_id' => $entity->content_id, 'result' => $entity->result];
	}

	protected function newEntity(array $attributes): Entity
	{
		unset($attributes['content']);

		// XenForo writes an entry with no content as a null, which the entity will not take: it is
		// saved as 0 and then given the null
		$attributes['content_id'] = $attributes['content_id'] ?? 0;

		return parent::newEntity($attributes);
	}

	protected function store(Entity $entity): void
	{
		if ($entity->content_id && \XF::em()->getFinder('XF:SpamTriggerLog')->where(['content_type' => $entity->content_type, 'content_id' => $entity->content_id])->total())
		{
			throw new \InvalidArgumentException("XenForo keeps one spam trigger log entry for each piece of content, and {$entity->content_type} {$entity->content_id} has one");
		}

		$entity->save();

		if (!$entity->content_id)
		{
			\XF::db()->update('xf_spam_trigger_log', ['content_id' => null], 'trigger_log_id = ?', $entity->trigger_log_id);
			// and the entity says so too, though its own setter would refuse it
			(function () { $this->_values['content_id'] = null; })->call($entity);
		}
	}

	/**
	 * A moment in the last month, but not the last hour, which XenForo's pruning leaves alone.
	 */
	public static function recent(): int
	{
		return time() - mt_rand(3600, 29 * 86400);
	}

	/**
	 * The request XenForo records with an entry: the address it was posted to and what was posted,
	 * with passwords and the token masked as it masks them.
	 */
	private static function requestState(string $contentType, ?Entity $content): array
	{
		$token = ['_xfToken' => '********'];
		$message = '<p>Great deals on <a href="https://example.com/offer">cheap watches</a>, limited time offer.</p>';

		switch ($contentType)
		{
			case 'user':
				$state = ['/register/register', $token + [
					'username' => $content ? $content->username : 'Example Member',
					'email' => $content ? $content->email : 'example.member@example.com',
					'password' => '********',
				]];
				break;

			case 'thread':
				$state = ['/forums/' . ($content ? $content->node_id : 2) . '/post-thread', $token + [
					'title' => $content ? $content->title : 'Cheap watches',
					'message_html' => $message,
				]];
				break;

			case 'post':
				$state = ['/threads/' . ($content ? $content->thread_id : 1) . '/add-reply', $token + ['message_html' => $message]];
				break;

			case 'profile_post':
				$state = ['/members/' . ($content ? $content->profile_user_id : 1) . '/post', $token + ['message' => strip_tags($message)]];
				break;

			case 'profile_post_comment':
				$state = ['/profile-posts/' . ($content ? $content->profile_post_id : 1) . '/add-comment', $token + ['message' => strip_tags($message)]];
				break;

			default:
				$state = ['/direct-messages/add', $token + ['title' => 'Cheap watches', 'message_html' => $message]];
		}

		return ['url' => $state[0], 'referrer' => '', '_GET' => [], '_POST' => $state[1]];
	}
}
