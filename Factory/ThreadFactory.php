<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\FeaturesContent;
use Hampel\Seeder\Factory\Concerns\WritesPosts;
use XF\Mvc\Entity\Entity;
use XF\Repository\PollRepository;
use XF\Repository\TagRepository;
use XF\Service\Thread\CreatorService;
use XF\Service\Thread\TypeData\PollCreatorService;

/**
 * A thread and its first post, written through XenForo's own thread creator so the message is
 * prepared as a posted one is - embeds, attachments, quotes. Its notifications are never sent.
 *
 * Besides the thread's columns, the attributes are:
 *
 *     message         the first post, as BB code
 *     message_parts   what a generated message contains - see the with...() states
 *     attachments     how many images to attach to the first post
 *     deleted         true to soft-delete it once posted; delete_reason and deleted_by go with it
 *     tags            the names of tags to file it under - see withTags()
 *     featured        true to feature it - see featured()
 *     poll            how many choices its poll has, 0 for no poll - see withPoll()
 *     poll_votes      the most members who vote in the poll
 *     poll_every      true for every member given to recycle() to vote - see votedByAll()
 *     log_ip          true to log the first post's address - see withIp()
 */
class ThreadFactory extends AbstractFactory
{
	use FeaturesContent;
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
			'tags' => [],
			'attachments' => 0,
			'poll' => 0,
			'poll_votes' => 0,
			'poll_every' => false,
			'deleted' => false,
			'delete_reason' => '',
			'deleted_by' => null,
			'log_ip' => false,
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

	/**
	 * Featured, with the featured content record XenForo keeps and not the flag alone: it has no
	 * image or title of its own, and the thread's author is named as the one who featured it.
	 *
	 * @return static
	 */
	public function featured() { return $this->state(['featured' => true]); }

	/**
	 * Awaiting approval, as a thread posted in a moderated forum is. It is in the approval queue
	 * and not counted in the forum.
	 *
	 * @return static
	 */
	public function moderated() { return $this->state(['discussion_state' => 'moderated']); }

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
	 * File it under these tags, as XenForo's own tagging does. A tag that does not exist yet is
	 * made, and recorded, so it is removed with the batch; one that exists is used and left.
	 *
	 * Names are tidied the way XenForo tidies them - lower case, single spaces - so `Two  Words`
	 * is the tag `two words`. A name the forum would not accept as a tag is refused.
	 *
	 * @param string[] $tags
	 *
	 * @return static
	 */
	public function withTags(array $tags)
	{
		return $this->state(function (array $attributes) use ($tags)
		{
			return ['tags' => array_merge($attributes['tags'] ?? [], array_values($tags))];
		});
	}

	/**
	 * With a poll, which makes it a poll thread: a question, so many choices, one vote each, and
	 * results anyone may see.
	 *
	 * The forum has to take poll threads. A forum this factory makes for the thread does; one
	 * given with for() or recycle() must - see ForumFactory::allowing() - or the poll is refused.
	 *
	 * Up to `$votes` of the members given to recycle() vote, each for one choice; with nobody
	 * recycled the poll has no votes. A vote is written as a row and the poll's totals rebuilt,
	 * since XenForo's own voting queues a webhook.
	 *
	 * @return static
	 */
	public function withPoll(int $choices = 4, int $votes = 0)
	{
		if ($choices < 2)
		{
			throw new \InvalidArgumentException('A poll needs at least two choices');
		}

		return $this->state(function (array $attributes) use ($choices, $votes)
		{
			$state = ['poll' => $choices, 'poll_votes' => $votes];
			if ($attributes['node_id'] instanceof ForumFactory)
			{
				$state['node_id'] = $attributes['node_id']->allowing('poll');
			}

			return $state;
		});
	}

	/**
	 * Every member given to recycle() votes in the poll, each for one choice, rather than a
	 * random number of them up to withPoll()'s `$votes` - for a check that needs to know who
	 * voted. Has no effect without withPoll().
	 *
	 * @return static
	 */
	public function votedByAll()
	{
		return $this->state(['poll_every' => true]);
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
		$described = [
			'title' => $entity->title,
			'node_id' => $entity->node_id,
			'user_id' => $entity->user_id,
			'first_post_id' => $entity->first_post_id,
		];

		if ($entity->sticky)
		{
			$described['sticky'] = true;
		}
		if ($entity->featured)
		{
			$described['featured'] = true;
		}
		if ($entity->discussion_type === 'poll' && $entity->Poll)
		{
			$described['poll_id'] = $entity->Poll->poll_id;
			$described['poll_voters'] = $entity->Poll->voter_count;
		}
		if ($entity->tags)
		{
			$described['tags'] = array_values(array_column($entity->tags, 'tag'));
		}

		return $described;
	}

	public static function remove(Entity $entity): void
	{
		$postIds = \XF::db()->fetchAllColumn('SELECT post_id FROM xf_post WHERE thread_id = ?', $entity->thread_id);

		self::unfeature($entity);
		self::removeWithAttachments($entity, $postIds);
	}

	/**
	 * Logs the address it was posted from, as XenForo does for a post made in a browser: an IP log
	 * row for the post, dated with it, from one of the author's addresses for the run. See
	 * `IpHistory`.
	 *
	 * @return static
	 */
	public function withIp() { return $this->state(['log_ip' => true]); }

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
		$choices = (int) $attributes['poll'];
		if ($choices)
		{
			$this->poll($creator, $forum, $choices);
		}

		$own = array_diff_key($attributes, array_flip(
			['node_id', 'user_id', 'title', 'message', 'message_parts', 'tags', 'attachments', 'post_date',
				'deleted', 'delete_reason', 'deleted_by', 'featured', 'poll', 'poll_votes', 'poll_every', 'log_ip']
		));
		$this->fill($thread, $own);

		$this->pending($thread, [
			'service' => $creator,
			'author' => $author,
			'date' => (int) $attributes['post_date'],
			'title' => $attributes['title'],
			'message' => $attributes['message'],
			'attachments' => (int) $attributes['attachments'],
			'tags' => (array) $attributes['tags'],
			'deleted' => (bool) $attributes['deleted'],
			'delete_reason' => $attributes['delete_reason'],
			'deleted_by' => $attributes['deleted_by'],
			'featured' => !empty($attributes['featured']),
			'poll_votes' => $choices ? (int) $attributes['poll_votes'] : 0,
			'poll_every' => $choices && !empty($attributes['poll_every']),
			'log_ip' => !empty($attributes['log_ip']),
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

		if ($pending['log_ip'])
		{
			self::logIpFor($entity->FirstPost, $pending);
		}

		$this->tag($entity, $pending);
		$this->vote($entity, $pending);

		if ($pending['featured'])
		{
			self::feature($entity, $pending['author']->user_id, $pending['date']);
		}
	}

	/**
	 * Give the thread being made a poll, as the thread form does: the thread becomes a poll
	 * thread and the poll is saved with it.
	 */
	protected function poll(CreatorService $creator, Entity $forum, int $choices): void
	{
		if (!$forum->TypeHandler->isThreadTypeAllowed('poll', $forum))
		{
			throw new \InvalidArgumentException("Forum {$forum->node_id} is a {$forum->forum_type_id} forum, which does not take poll threads");
		}

		$faker = $this->context()->faker;

		$responses = [];
		while (count($responses) < $choices)
		{
			$responses[] = ucfirst($faker->words(mt_rand(1, 3), true)) . (count($responses) >= 20 ? ' ' . count($responses) : '');
		}

		$saver = \XF::service(PollCreatorService::class, $creator->getThread());
		$poll = $saver->getPollCreator();
		$poll->setQuestion(rtrim($faker->sentence(mt_rand(4, 8)), '.') . '?');
		$poll->setMaxVotes('single');
		$poll->setMaxResponses($choices);
		$poll->setOptions(['change_vote' => true, 'public_votes' => false, 'view_results_unvoted' => true]);
		$poll->addResponses(array_values(array_unique($responses)));
		static::silence($poll->getPoll());

		$creator->setDiscussionTypeAndDataRaw('poll');
		$creator->setTypeDataSaver($saver);
	}

	/**
	 * Votes in the thread's poll, from the members given to recycle(): a row each and then the
	 * totals rebuilt, the way XenForo rebuilds them. Each is dated after the thread and after the
	 * voter joined.
	 */
	protected function vote(Entity $thread, array $pending): void
	{
		$voters = $this->recycled['XF:User'] ?? [];
		$wanted = $pending['poll_every'] ? count($voters) : min($pending['poll_votes'], count($voters));
		$poll = $wanted ? \XF::em()->findOne('XF:Poll', ['content_type' => 'thread', 'content_id' => $thread->thread_id]) : null;
		if (!$poll)
		{
			return;
		}

		$responseIds = $poll->Responses->keys();
		$timeline = $this->context()->timeline;

		shuffle($voters);
		foreach (array_slice($voters, 0, $pending['poll_every'] ? $wanted : mt_rand(1, $wanted)) AS $voter)
		{
			\XF::db()->insert('xf_poll_vote', [
				'user_id' => $voter->user_id,
				'poll_response_id' => $responseIds[mt_rand(0, count($responseIds) - 1)],
				'poll_id' => $poll->poll_id,
				'vote_date' => $timeline->after(max($pending['date'], $voter->register_date)),
			], false, false, 'IGNORE');
		}

		\XF::repository(PollRepository::class)->rebuildPollData($poll->poll_id);
	}

	/**
	 * Through the tag repository, not the thread creator: the creator only tags where the author
	 * has permission to and the forum allows it, which a seeded member in a seeded forum may not.
	 */
	protected function tag(Entity $thread, array $pending): void
	{
		if (!$pending['tags'])
		{
			return;
		}

		$repo = \XF::repository(TagRepository::class);

		$ids = [];
		foreach ($pending['tags'] AS $name)
		{
			$name = $repo->normalizeTag((string) $name);
			if (!$repo->isValidTag($name))
			{
				throw new \InvalidArgumentException("'{$name}' is not a tag this forum would accept");
			}

			$tag = \XF::em()->findOne('XF:Tag', ['tag' => $name]) ?: TagFactory::new()->create(['tag' => $name]);
			$ids[$tag->tag_id] = $tag->tag_id;
		}

		static::silence($thread);
		$repo->modifyContentTags('thread', $thread->thread_id, array_values($ids), [], $pending['author']->user_id);

		// XenForo stamps a tag's last use with the real time; it is the latest date anything
		// visible was filed under it
		\XF::db()->query(
			'UPDATE xf_tag SET last_use_date = (
					SELECT COALESCE(MAX(add_date), 0) FROM xf_tag_content
					WHERE xf_tag_content.tag_id = xf_tag.tag_id AND visible = 1
				) WHERE tag_id IN (' . \XF::db()->quote(array_values($ids)) . ')'
		);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $this->pending($entity)['date'];
	}
}
