<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\WritesPosts;
use XF\Mvc\Entity\Entity;
use XF\Service\Thread\ReplierService;

/**
 * A reply to a thread, written through XenForo's own replier and dated after the thread's last
 * post - so replies made one after another come out in order. Its notifications are never sent.
 *
 * Besides the post's columns, the attributes are message, message_parts, attachments, deleted and
 * log_ip, as for a thread.
 */
class PostFactory extends AbstractFactory
{
	use WritesPosts;

	protected $entity = 'XF:Post';

	public function definition()
	{
		$context = $this->context();

		return [
			'thread_id' => ThreadFactory::new(),
			'user_id' => UserFactory::new(),
			'message_parts' => [],
			'attachments' => 0,
			'deleted' => false,
			'delete_reason' => '',
			'deleted_by' => null,
			'log_ip' => false,
			'message' => function (array $attributes) use ($context)
			{
				return $context->faker->bbCodeMessage($attributes['message_parts'] ?: ['short']);
			},
			'post_date' => function (array $attributes) use ($context)
			{
				$thread = \XF::em()->find('XF:Thread', $attributes['thread_id']);
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				// after the post it follows, and not before its author had joined
				return $context->timeline->after(max(
					$thread ? $thread->last_post_date : $context->timeline->start(),
					$author ? $author->register_date : 0
				));
			},
		];
	}

	/**
	 * Awaiting approval: in the approval queue, and not counted in the thread.
	 *
	 * @return static
	 */
	public function moderated() { return $this->state(['message_state' => 'moderated']); }

	/**
	 * Posted and then soft-deleted, with a deletion log entry.
	 *
	 * @param \XF\Entity\User|null $by who deleted it; its author if omitted
	 *
	 * @return static
	 */
	public function deleted(string $reason = '', ?\XF\Entity\User $by = null)
	{
		return $this->state(['deleted' => true, 'delete_reason' => $reason, 'deleted_by' => $by ? $by->user_id : null]);
	}

	/** @return static */
	/**
	 * Logs the address it was posted from, as XenForo does for a post made in a browser: an IP log
	 * row for the post, dated with it, from one of the author's addresses for the run. See
	 * `IpHistory`.
	 *
	 * @return static
	 */
	public function withIp() { return $this->state(['log_ip' => true]); }

	public function withQuote() { return $this->state(['message_parts' => ['short', 'quote']]); }

	/**
	 * @return static
	 */
	public function withAttachment(int $images = 1)
	{
		return $this->state(['attachments' => $images]);
	}

	public static function describe(Entity $entity): array
	{
		return ['thread_id' => $entity->thread_id, 'user_id' => $entity->user_id];
	}

	public static function remove(Entity $entity): void
	{
		self::removeWithAttachments($entity, [$entity->post_id]);
	}

	protected function newEntity(array $attributes): Entity
	{
		$thread = \XF::em()->find('XF:Thread', $attributes['thread_id']);
		if (!$thread)
		{
			throw new \InvalidArgumentException("A reply needs a thread: there is no thread {$attributes['thread_id']}");
		}
		$author = $this->author($attributes['user_id']);

		$replier = \XF::asVisitor($author, function () use ($thread, $attributes)
		{
			$replier = \XF::service(ReplierService::class, $thread);
			$replier->setIsAutomated();
			$replier->setMessage($attributes['message']);

			return $replier;
		});

		$post = $replier->getPost();
		$own = array_diff_key($attributes, array_flip(
			['thread_id', 'user_id', 'message', 'message_parts', 'attachments', 'post_date',
				'deleted', 'delete_reason', 'deleted_by', 'log_ip']
		));
		$this->fill($post, $own);

		$this->pending($post, [
			'service' => $replier,
			'author' => $author,
			'date' => (int) $attributes['post_date'],
			'message' => $attributes['message'],
			'attachments' => (int) $attributes['attachments'],
			'deleted' => (bool) $attributes['deleted'],
			'delete_reason' => $attributes['delete_reason'],
			'deleted_by' => $attributes['deleted_by'],
			'log_ip' => !empty($attributes['log_ip']),
		]);

		return $post;
	}

	protected function store(Entity $entity): void
	{
		$pending = $this->pending($entity);
		$replier = $pending['service'];

		\XF::asVisitor($pending['author'], function () use ($replier, $pending)
		{
			$message = $this->attach($replier, $pending);
			if ($message !== null)
			{
				$replier->setMessage($message);
			}

			$this->assertValid($replier);

			// validation stamps the real time and readies the save, so the date the reply is meant
			// to have is forced in after it
			$replier->getPost()->set('post_date', $pending['date'], ['forceSet' => true]);

			$replier->save();
		});

		if ($pending['log_ip'])
		{
			self::logIpFor($entity, $pending);
		}
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $this->pending($entity)['date'];
	}
}
