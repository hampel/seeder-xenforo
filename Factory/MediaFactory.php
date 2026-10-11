<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Factory\Concerns\MakesImages;
use Hampel\Seeder\Factory\Concerns\NeedsAddOn;
use XF\FileWrapper;
use XF\Mvc\Entity\Entity;
use XF\Repository\AttachmentRepository;
use XF\Service\Attachment\PreparerService;

/**
 * A media item in XenForo Media Gallery: a real PNG image, uploaded as the gallery takes an
 * upload, with the thumbnail the gallery makes of it. In a media category unless inAlbum() or
 * hidden() puts it in an album.
 *
 * The upload goes through the gallery's own attachment handler and temporary-media service; the
 * item itself is saved through its entity, so nobody watching the category or album is told.
 */
class MediaFactory extends AbstractFactory
{
	use MakesImages;
	use NeedsAddOn;

	protected $entity = 'XFMG:MediaItem';

	/** @var \WeakMap what each unsaved item still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$context = $this->context();

		return [
			'user_id' => UserFactory::new(),
			'album_id' => 0,
			'category_id' => function (array $attributes)
			{
				// an album's category is its items' category; without an album, a media category
				if ($attributes['album_id'])
				{
					$album = \XF::em()->find('XFMG:Album', $attributes['album_id']);

					return $album ? $album->category_id : 0;
				}

				return $this->related(MediaCategoryFactory::new())->category_id;
			},
			'title' => function ()
			{
				return ucfirst(substr($this->context()->faker->words(mt_rand(2, 4), true), 0, 90));
			},
			'description' => $context->faker->sentence(),
			'media_date' => function (array $attributes) use ($context)
			{
				$earliest = 0;
				if ($author = \XF::em()->find('XF:User', $attributes['user_id']))
				{
					$earliest = $author->register_date;
				}
				if ($attributes['album_id'] && ($album = \XF::em()->find('XFMG:Album', $attributes['album_id'])))
				{
					$earliest = max($earliest, $album->create_date);
				}

				return $context->timeline->after($earliest);
			},
		];
	}

	/**
	 * In this album, or in a new one of the author's own.
	 *
	 * @param Entity|AlbumFactory|null $album
	 *
	 * @return static
	 */
	public function inAlbum($album = null)
	{
		if ($album instanceof Entity)
		{
			return $this->state(['album_id' => $album->album_id]);
		}

		$album = $album ?? AlbumFactory::new();

		return $this->state(['album_id' => function (array $attributes) use ($album)
		{
			$album = clone $album;
			$album->recycled += $this->recycled;

			// the author's own album
			return $album->count(null)->create(['user_id' => $attributes['user_id']])->album_id;
		}]);
	}

	/**
	 * One a guest cannot view: in a new album of the author's that only its owner can see.
	 *
	 * @return static
	 */
	public function hidden()
	{
		return $this->inAlbum(AlbumFactory::new()->private());
	}

	public static function describe(Entity $entity): array
	{
		return [
			'title' => $entity->title,
			'user_id' => $entity->user_id,
			'category_id' => $entity->category_id,
			'album_id' => $entity->album_id,
			'media_type' => $entity->media_type,
			// who can view an item in an album is the album's to say; it is not asked of the
			// gallery here, whose answer for a category made a moment ago is not yet settled
			'album_view_privacy' => $entity->album_id && $entity->Album ? $entity->Album->view_privacy : null,
		];
	}

	/**
	 * The gallery drops the attachment row with the item and leaves the image file for an hourly
	 * clean-up; the file goes here.
	 */
	public static function remove(Entity $entity): void
	{
		$dataIds = \XF::db()->fetchAllColumn(
			"SELECT data_id FROM xf_attachment WHERE content_type = 'xfmg_media' AND content_id = ?", $entity->media_id
		);

		parent::remove($entity);

		foreach ($dataIds AS $dataId)
		{
			$data = \XF::em()->find('XF:AttachmentData', $dataId);
			if ($data && !\XF::db()->fetchOne('SELECT 1 FROM xf_attachment WHERE data_id = ? LIMIT 1', $dataId))
			{
				$data->delete();
			}
		}
	}

	protected function newEntity(array $attributes): Entity
	{
		self::needs('XFMG', 'Media items');

		$author = \XF::em()->find('XF:User', $attributes['user_id']);
		if (!$author)
		{
			throw new \InvalidArgumentException("A media item needs an author: there is no member {$attributes['user_id']}");
		}

		$album = $attributes['album_id'] ? \XF::em()->find('XFMG:Album', $attributes['album_id']) : null;
		$category = $attributes['category_id'] ? \XF::em()->find('XFMG:Category', $attributes['category_id']) : null;
		if ($attributes['album_id'] && !$album)
		{
			throw new \InvalidArgumentException("There is no album {$attributes['album_id']}");
		}
		if (!$album && (!$category || $category->category_type !== 'media'))
		{
			throw new \InvalidArgumentException('A media item outside an album needs a category that holds media items');
		}

		$item = \XF::em()->create('XFMG:MediaItem');
		$this->fill($item, ['username' => $author->username] + $attributes);

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$item] = ['author' => $author, 'date' => (int) $attributes['media_date']];

		return $item;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];
		$author = $pending['author'];
		$date = $pending['date'];

		\XF::asVisitor($author, function () use ($entity, $author, $date)
		{
			$handler = \XF::repository(AttachmentRepository::class)->getAttachmentHandler('xfmg_media');
			$file = new FileWrapper(self::image(), 'seeded-image.png');
			$attachment = \XF::service(PreparerService::class)->insertAttachment($handler, $file, $author, md5(uniqid('seeder', true)));

			// both dates are column defaults, fixed when their structures were first built
			$attachment->fastUpdate('attach_date', $date);
			$attachment->Data->fastUpdate('upload_date', $date);

			// The gallery's attachment handler has made its holding record for the upload by now,
			// thumbnail included. If the item cannot be saved, neither is left lying about.
			$upload = \XF::em()->findOne('XFMG:MediaTemp', ['attachment_id' => $attachment->attachment_id]);
			if (!$upload)
			{
				self::discard($attachment);
				throw new \RuntimeException('Media Gallery made no holding record for the upload');
			}

			try
			{
				$entity->bulkSet(['media_hash' => $upload->media_hash, 'media_type' => $upload->media_type]);
				$entity->save();
			}
			catch (\Throwable $e)
			{
				self::discard($attachment);
				throw $e;
			}

			// as the gallery's creator does once the item has an id: the upload becomes the item's,
			// and the thumbnail moves to its place
			\XF::db()->update('xf_attachment',
				['content_id' => $entity->media_id, 'temp_hash' => '', 'unassociated' => 0],
				'attachment_id = ?', $attachment->attachment_id
			);
			$entity->hydrateRelation('Attachment', $attachment);
			$handler->onAssociation($attachment, $entity);

			// the gallery dates the thumbnail by the real clock
			if ($entity->thumbnail_date)
			{
				$entity->fastUpdate('thumbnail_date', $date);
			}
		});
	}

	/**
	 * Remove an upload that never became an item: the attachment, its holding record and files.
	 */
	protected static function discard(Entity $attachment): void
	{
		$data = $attachment->Data;
		$attachment->delete();

		if ($data && !\XF::db()->fetchOne('SELECT 1 FROM xf_attachment WHERE data_id = ? LIMIT 1', $data->data_id))
		{
			$data->delete();
		}
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$pending[$entity]['date'];
	}
}
