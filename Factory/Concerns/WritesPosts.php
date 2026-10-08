<?php namespace Hampel\Seeder\Factory\Concerns;

use Hampel\Seeder\Clock;
use XF\Entity\User;
use XF\FileWrapper;
use XF\Mvc\Entity\Entity;
use XF\Repository\AttachmentRepository;
use XF\Service\Attachment\PreparerService;
use XF\Util\File;

/**
 * What the thread and reply factories share: both write a post through one of XenForo's services,
 * which is built when the entity is made and saved when it is stored.
 */
trait WritesPosts
{
	/** @var \WeakMap what each unsaved entity still needs when it is stored */
	private static $pending;

	/**
	 * Keep, or fetch, what an entity needs when it is stored.
	 */
	protected function pending(Entity $entity, ?array $data = null): array
	{
		self::$pending = self::$pending ?? new \WeakMap();
		if ($data !== null)
		{
			self::$pending[$entity] = $data;
		}

		return self::$pending[$entity];
	}

	protected function author(int $userId): User
	{
		$author = \XF::em()->find('XF:User', $userId);
		if (!$author)
		{
			throw new \InvalidArgumentException("A post needs an author: there is no member {$userId}");
		}

		return $author;
	}

	/**
	 * Soft-delete what was posted, if that was asked for, once its replies and reactions exist:
	 * after the last of them, by the member named or else by its author. Posted, replied to and
	 * then deleted is the history deleted content really has, and it leaves the counters and the
	 * reaction scores where XenForo would.
	 */
	protected function finish(Entity $entity): void
	{
		$pending = $this->pending($entity);
		if (!$pending['deleted'])
		{
			return;
		}

		$by = $pending['deleted_by'] ? $this->author((int) $pending['deleted_by']) : $pending['author'];
		$when = $this->context()->timeline->after(max($pending['date'], (int) ($entity->last_post_date ?? 0)));

		static::silence($entity);
		Clock::at($when, function () use ($entity, $pending, $by)
		{
			$entity->softDelete((string) $pending['delete_reason'], $by);
		});

		// the log's own date is a column default, fixed when its structure was first built
		$log = \XF::em()->findOne('XF:DeletionLog', [
			'content_type' => $entity->getEntityContentType(), 'content_id' => $entity->getEntityId(),
		]);
		if ($log)
		{
			$log->fastUpdate('delete_date', $when);
		}
	}

	/**
	 * @param object $service a thread creator or replier, already validated or not
	 */
	protected function assertValid($service): void
	{
		if (!$service->validate($errors))
		{
			throw new \RuntimeException('XenForo refused the post: ' . implode(' ', array_map('strval', $errors)));
		}
	}

	/**
	 * Upload the images a post asked for and hand them to the service. They are real PNG files:
	 * XenForo judges an upload by what is in it, not by what it is called.
	 *
	 * @return string|null the message with the first image shown in it, or null if there are none
	 */
	protected function attach($service, array $pending): ?string
	{
		if ($pending['attachments'] < 1)
		{
			return null;
		}

		$hash = md5(uniqid('seeder', true));
		$handler = \XF::repository(AttachmentRepository::class)->getAttachmentHandler('post');
		$preparer = \XF::service(PreparerService::class);

		$first = null;
		for ($i = 1; $i <= $pending['attachments']; $i++)
		{
			$file = new FileWrapper(self::image(), "seeded-image-{$i}.png");
			$attachment = $preparer->insertAttachment($handler, $file, $pending['author'], $hash);
			$first = $first ?? $attachment->attachment_id;

			// both dates are column defaults, fixed when their structures were first built
			$attachment->fastUpdate('attach_date', $pending['date']);
			$attachment->Data->fastUpdate('upload_date', $pending['date']);
		}

		$service->setAttachmentHash($hash);

		return $pending['message'] . "\n\n[ATTACH type=\"full\"]{$first}[/ATTACH]";
	}

	/**
	 * Delete an entity and the files of any attachments its posts had. XenForo drops the
	 * attachment rows with the post and leaves the files for an hourly clean-up.
	 *
	 * @param int[] $postIds
	 */
	protected static function removeWithAttachments(Entity $entity, array $postIds): void
	{
		$dataIds = [];
		if ($postIds)
		{
			$dataIds = \XF::db()->fetchAllColumn(
				"SELECT data_id FROM xf_attachment WHERE content_type = 'post' AND content_id IN (" . \XF::db()->quote($postIds) . ')'
			);
		}

		static::silence($entity);
		$entity->delete();

		foreach ($dataIds AS $dataId)
		{
			$data = \XF::em()->find('XF:AttachmentData', $dataId);
			if ($data && !\XF::db()->fetchOne('SELECT 1 FROM xf_attachment WHERE data_id = ? LIMIT 1', $dataId))
			{
				$data->delete();
			}
		}
	}

	/**
	 * @return string the path of a small PNG, different each time
	 */
	private static function image(): string
	{
		$path = File::getTempFile();
		$image = imagecreatetruecolor(480, 270);
		imagefill($image, 0, 0, imagecolorallocate($image, mt_rand(40, 215), mt_rand(40, 215), mt_rand(40, 215)));
		imagefilledellipse($image, mt_rand(80, 400), mt_rand(60, 210), mt_rand(60, 160), mt_rand(60, 160),
			imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
		imagepng($image, $path);
		imagedestroy($image);

		return $path;
	}
}
