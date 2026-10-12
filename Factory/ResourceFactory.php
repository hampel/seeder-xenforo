<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\FeaturesContent;
use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;

/**
 * A resource in XenForo Resource Manager: the resource, its description and its first version.
 * It has no file - the kind XenForo calls fileless - and is visible unless moderated() is used.
 *
 * Written through the Resource Manager's own creator, with its notifications never sent.
 *
 * Where the category names a discussion forum the resource gets its discussion thread there, as
 * a posted one does: the same title, first post and thread type. That thread is made by
 * ThreadFactory and not by the Resource Manager, which would date it now and queue a webhook; it
 * is recorded on its own and removed with the batch. Its author does not watch it.
 *
 * Besides the resource's columns, the attributes are:
 *
 *     message          the description, as BB code
 *     version_string   the first version's number
 *     featured         true to feature it - see featured()
 */
class ResourceFactory extends AbstractFactory
{
	use FeaturesContent;
	use NeedsAddOn;

	protected $entity = 'XFRM:ResourceItem';

	/** @var \WeakMap what each unsaved resource still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$context = $this->context();

		return [
			'resource_category_id' => ResourceCategoryFactory::new(),
			'user_id' => UserFactory::new(),
			'title' => function ()
			{
				return ucfirst(substr($this->context()->faker->words(mt_rand(2, 4), true), 0, 90));
			},
			'tag_line' => function ()
			{
				return substr(rtrim($this->context()->faker->sentence(mt_rand(4, 8)), '.'), 0, 100);
			},
			'message' => $context->faker->bbCodeMessage(['short']),
			'version_string' => mt_rand(1, 3) . '.' . mt_rand(0, 9) . '.' . mt_rand(0, 9),
			'resource_date' => function (array $attributes) use ($context)
			{
				$author = \XF::em()->find('XF:User', $attributes['user_id']);

				return $context->timeline->after($author ? $author->register_date : 0);
			},
		];
	}

	/** @return static awaiting approval by a moderator */
	public function moderated() { return $this->state(['resource_state' => 'moderated']); }

	/**
	 * Featured, with the featured content record XenForo keeps and not the flag alone: it has no
	 * image or title of its own, and the resource's author is named as the one who featured it.
	 * Written through the entity, since XenForo's own service writes the moderator log and queues
	 * a webhook.
	 *
	 * @return static
	 */
	public function featured() { return $this->state(['featured' => true]); }

	/**
	 * The featured content record is the resource's, and goes with it.
	 */
	public static function remove(Entity $entity): void
	{
		self::unfeature($entity);

		// removing a resource closes or hides its discussion thread, which saves the thread
		if ($entity->discussion_thread_id && $entity->Discussion)
		{
			static::silence($entity->Discussion);
		}

		parent::remove($entity);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'resource_category_id' => $entity->resource_category_id,
			'user_id' => $entity->user_id,
			'resource_state' => $entity->resource_state,
		] + ($entity->featured ? ['featured' => true] : [])
			+ ($entity->discussion_thread_id ? ['discussion_thread_id' => $entity->discussion_thread_id] : []);
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFRM', 'Resources');

		$category = \XF::em()->find('XFRM:Category', $attributes['resource_category_id']);
		if (!$category)
		{
			throw new \InvalidArgumentException("A resource needs a category: there is no resource category {$attributes['resource_category_id']}");
		}
		$author = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$author)
		{
			throw new \InvalidArgumentException("A resource needs an author: there is no member {$attributes['user_id']}");
		}

		$date = (int) $attributes['resource_date'];

		// the creator takes the author from whoever is the visitor
		$creator = \XF::asVisitor($author, function () use ($category, $attributes)
		{
			$creator = \XF::service('XFRM:ResourceItem\Create', $category);
			$creator->setIsAutomated();
			$creator->logIp(false);
			$creator->setContent($attributes['title'], $attributes['message']);
			$creator->setVersionString($attributes['version_string'], true);
			$creator->setFileless();
			$creator->setTags([]);

			return $creator;
		});

		$resource = $creator->getResource();
		$this->fill($resource, array_diff_key($attributes, array_flip(
			['resource_category_id', 'user_id', 'title', 'message', 'version_string', 'resource_date', 'featured']
		)));

		// each of these dates is otherwise a column default, which is the moment the first
		// resource of the run was made and not this one's
		$resource->bulkSet(['resource_date' => $date, 'last_update' => $date]);
		$creator->getDescription()->post_date = $date;
		$creator->getVersion()->release_date = $date;

		foreach ([$resource, $creator->getDescription(), $creator->getVersion()] AS $entity)
		{
			static::silence($entity);
		}

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$resource] = [
			'service' => $creator, 'author' => $author, 'date' => $date, 'featured' => !empty($attributes['featured']),
			'category' => $category, 'message' => $attributes['message'],
		];

		return $resource;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$creator = $pending['service'];
		$category = $pending['category'];

		// the Resource Manager would start the discussion thread itself, dated now and with its
		// webhook on; it is kept from seeing the forum while it saves, and the thread made below
		$forum = $category->thread_node_id ? $category->ThreadForum : null;
		if ($forum)
		{
			$category->hydrateRelation('ThreadForum', null);
		}

		try
		{
			\XF::asVisitor($pending['author'], function () use ($creator)
			{
				if (!$creator->validate($errors))
				{
					throw new \RuntimeException('Resource Manager refused the resource: ' . implode(' ', array_map('strval', $errors)));
				}

				$creator->save();
			});
		}
		finally
		{
			if ($forum)
			{
				$category->hydrateRelation('ThreadForum', $forum);
			}
		}

		if ($forum)
		{
			$this->discuss($entity, $forum, $pending);
		}

		if ($pending['featured'])
		{
			self::feature($entity, $pending['author']->user_id, $pending['date']);
		}
	}

	/**
	 * The resource's discussion thread, worded as the Resource Manager words it.
	 */
	protected function discuss(Entity $resource, Entity $forum, array $pending): void
	{
		$app = \XF::app();
		$author = $pending['author'];

		$message = \XF::phrase('xfrm_resource_thread_create', [
			'title' => $resource->title_,
			'tag_line' => $resource->tag_line_,
			'username' => $author->username,
			'snippet' => $app->bbCode()->render(
				$app->stringFormatter()->wholeWordTrim($pending['message'], 500), 'bbCodeClean', 'post', null
			),
			'resource_link' => $app->router('public')->buildLink('canonical:resources', $resource),
		])->render('raw');

		$thread = ThreadFactory::new()->create([
			'node_id' => $forum->node_id,
			'user_id' => $author->user_id,
			'title' => $resource->getExpectedThreadTitle(),
			'message' => $message,
			'post_date' => $pending['date'],
			'discussion_type' => 'resource',
			'discussion_state' => $resource->resource_state,
			'prefix_id' => $pending['category']->thread_prefix_id,
		]);

		$resource->fastUpdate('discussion_thread_id', $thread->thread_id);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$pending[$entity]['date'];
	}
}
