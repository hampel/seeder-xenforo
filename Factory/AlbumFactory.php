<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\Mvc\Entity\Entity;
use XF\Util\File;

/**
 * An album in XenForo Media Gallery: a member's own, in no category, that anyone may view. Use
 * private() or membersOnly() for one a guest cannot view, and in() to put it in a category.
 *
 * Written through the gallery's own album creator, with its notifications never sent.
 */
class AlbumFactory extends AbstractFactory
{
	use NeedsAddOn;

	protected $entity = 'XFMG:Album';

	/** @var \WeakMap what each unsaved album still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$context = $this->context();

		return [
			'user_id' => UserFactory::new(),
			'category_id' => 0,
			'title' => function ()
			{
				return ucfirst(substr($this->context()->faker->words(mt_rand(2, 4), true), 0, 90));
			},
			'description' => $context->faker->sentence(),
			'view_privacy' => 'public',
			'create_date' => function (array $attributes) use ($context)
			{
				$owner = \XF::em()->find('XF:User', $attributes['user_id']);

				return $context->timeline->after($owner ? $owner->register_date : 0);
			},
		];
	}

	/** @return static one only its owner can view */
	public function private() { return $this->state(['view_privacy' => 'private']); }

	/** @return static one a guest cannot view and any member can */
	public function membersOnly() { return $this->state(['view_privacy' => 'members']); }

	/**
	 * In this category, which has to be one that holds albums; who may view it is then the
	 * category's to say.
	 *
	 * @param Entity|MediaCategoryFactory|null $category a new album category if omitted
	 *
	 * @return static
	 */
	public function in($category = null)
	{
		$category = $category ?? MediaCategoryFactory::new()->forAlbums();

		return $this->state([
			'category_id' => $category instanceof Entity ? $category->category_id : $category,
			'view_privacy' => 'inherit',
		]);
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'user_id' => $entity->user_id,
			'category_id' => $entity->category_id,
			'view_privacy' => $entity->view_privacy,
		];
	}

	/**
	 * The gallery deletes an album's thumbnail only while the album says it has one, and an album
	 * emptied of its items says it has none while the file is still there. So the file goes
	 * whatever the album says.
	 */
	public static function remove(Entity $entity): void
	{
		$thumbnail = $entity->getAbstractedThumbnailPath();

		parent::remove($entity);

		File::deleteFromAbstractedPath($thumbnail);
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFMG', 'Albums');

		$owner = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$owner)
		{
			throw new \InvalidArgumentException("An album needs an owner: there is no member {$attributes['user_id']}");
		}

		$creator = \XF::asVisitor($owner, function () use ($owner, $attributes)
		{
			$creator = \XF::service('XFMG:Album\Creator');
			$creator->setUser($owner);
			$creator->logIp(false);
			$creator->setTitle($attributes['title'], $attributes['description']);

			if ($attributes['category_id'])
			{
				$category = \XF::em()->find('XFMG:Category', $attributes['category_id']);
				if (!$category || $category->category_type !== 'album')
				{
					throw new \InvalidArgumentException("Media category {$attributes['category_id']} is not one that holds albums");
				}
				$creator->setCategory($category);
			}
			else
			{
				$creator->setViewPrivacy($attributes['view_privacy']);
			}

			return $creator;
		});

		$album = $creator->getAlbum();
		$this->fill($album, array_diff_key($attributes, array_flip(['user_id', 'category_id', 'title', 'description', 'view_privacy', 'create_date'])));

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$album] = ['service' => $creator, 'owner' => $owner, 'date' => (int) $attributes['create_date']];

		return $album;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$creator = $pending['service'];
		$date = $pending['date'];

		\XF::asVisitor($pending['owner'], function () use ($creator, $date)
		{
			if (!$creator->validate($errors))
			{
				throw new \RuntimeException('Media Gallery refused the album: ' . implode(' ', array_map('strval', $errors)));
			}

			// validation stamps the real time and readies the save
			$creator->getAlbum()->set('create_date', $date, ['forceSet' => true]);

			$creator->save();
		});
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$pending[$entity]['date'];
	}
}
