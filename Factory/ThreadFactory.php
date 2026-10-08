<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\WritesPosts;
use XF\Mvc\Entity\Entity;
use XF\Service\Thread\CreatorService;

/**
 * A thread and its first post, written through XenForo's own thread creator so the message is
 * prepared as a posted one is - embeds, attachments, quotes. Its notifications are never sent.
 *
 * Besides the thread's columns, the attributes are:
 *
 *     message         the first post, as BB code
 *     message_parts   what a generated message contains - see the with...() states
 *     attachments     how many images to attach to the first post
 */
class ThreadFactory extends AbstractFactory
{
	use WritesPosts;

	protected $entity = 'XF:Thread';

	public function definition()
	{
		$context = $this->context();

		return [
			'node_id' => ForumFactory::new(),
			'user_id' => UserFactory::new(),
			'title' => $context->faker->threadTitle(),
			'message_parts' => [],
			'attachments' => 0,
			'message' => function (array $attributes) use ($context)
			{
				return $context->faker->bbCodeMessage($attributes['message_parts']);
			},
			'post_date' => function (array $attributes) use ($context)
			{
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				return $context->timeline->after($author ? $author->register_date : $context->timeline->start());
			},
		];
	}

	/**
	 * @return static
	 */
	public function for(Entity $entity)
	{
		// a forum is filed under by its node
		return parent::for($entity->structure()->shortName === 'XF:Forum' ? $entity->Node : $entity);
	}

	/** @return static */
	public function locked() { return $this->state(['discussion_open' => false]); }

	/** @return static */
	public function sticky() { return $this->state(['sticky' => true]); }

	/** @return static a first post of many paragraphs */
	public function long() { return $this->withPart('long'); }

	/** @return static */
	public function withQuote() { return $this->withPart('quote'); }

	/** @return static */
	public function withList() { return $this->withPart('list'); }

	/** @return static */
	public function withImage() { return $this->withPart('image'); }

	/** @return static */
	public function withLinks() { return $this->withPart('links'); }

	/** @return static bold, italic and a heading */
	public function withFormatting() { return $this->withPart('formatting'); }

	/**
	 * @return static with images attached to the first post, the first of them shown in the message
	 */
	public function withAttachment(int $images = 1)
	{
		return $this->state(['attachments' => $images]);
	}

	/**
	 * @param int|Entity|PrefixFactory|null $prefix an existing prefix, or a new one if omitted
	 *
	 * @return static
	 */
	public function withPrefix($prefix = null)
	{
		$prefix = $prefix ?? PrefixFactory::new();

		return $this->state(['prefix_id' => $prefix instanceof Entity ? $prefix->getEntityId() : $prefix]);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'node_id' => $entity->node_id,
			'user_id' => $entity->user_id,
			'first_post_id' => $entity->first_post_id,
		];
	}

	public static function remove(Entity $entity): void
	{
		$postIds = \XF::db()->fetchAllColumn('SELECT post_id FROM xf_post WHERE thread_id = ?', $entity->thread_id);

		self::removeWithAttachments($entity, $postIds);
	}

	/**
	 * @return static
	 */
	protected function withPart(string $part)
	{
		return $this->state(function (array $attributes) use ($part)
		{
			return ['message_parts' => array_merge($attributes['message_parts'] ?? [], [$part])];
		});
	}

	protected function newEntity(array $attributes): Entity
	{
		$forum = \XF::em()->find('XF:Forum', $attributes['node_id']);
		if (!$forum)
		{
			throw new \InvalidArgumentException("A thread needs a forum: there is no forum with node id {$attributes['node_id']}");
		}
		$author = $this->author($attributes['user_id']);

		$creator = \XF::asVisitor($author, function () use ($forum, $attributes)
		{
			$creator = \XF::service(CreatorService::class, $forum);
			$creator->setIsAutomated();
			$creator->setContent($attributes['title'], $attributes['message']);

			return $creator;
		});

		$thread = $creator->getThread();
		$own = array_diff_key($attributes, array_flip(
			['node_id', 'user_id', 'title', 'message', 'message_parts', 'attachments', 'post_date']
		));
		$this->fill($thread, $own);

		$this->pending($thread, [
			'service' => $creator,
			'author' => $author,
			'date' => (int) $attributes['post_date'],
			'title' => $attributes['title'],
			'message' => $attributes['message'],
			'attachments' => (int) $attributes['attachments'],
		]);

		return $thread;
	}

	protected function store(Entity $entity): void
	{
		$pending = $this->pending($entity);
		$creator = $pending['service'];

		\XF::asVisitor($pending['author'], function () use ($creator, $pending)
		{
			$message = $this->attach($creator, $pending);
			if ($message !== null)
			{
				$creator->setContent($pending['title'], $message);
			}

			$this->assertValid($creator);

			// validation stamps the real time and readies the save, so the dates the content is
			// meant to have are forced in after it
			$thread = $creator->getThread();
			$thread->set('post_date', $pending['date'], ['forceSet' => true]);
			$thread->set('last_post_date', $pending['date'], ['forceSet' => true]);
			$creator->getPost()->set('post_date', $pending['date'], ['forceSet' => true]);

			$creator->save();
		});
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $this->pending($entity)['date'];
	}
}
