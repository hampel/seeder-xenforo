<?php namespace Hampel\Seeder\Factory\Concerns;

use XF\Mvc\Entity\Entity;

/**
 * For a factory whose content XenForo can feature. The featured content record is written
 * through its entity: XenForo's own service writes the moderator log and queues a webhook.
 */
trait FeaturesContent
{
	/**
	 * Feature saved content: the record, with no image or title of its own, and the flag on the
	 * content.
	 *
	 * @param int $byUserId who is named as having featured it
	 * @param int $date     when - otherwise a column default, the moment the first feature of the
	 *                      run was made
	 */
	protected static function feature(Entity $content, int $byUserId, int $date): void
	{
		$type = $content->getEntityContentType();
		$handler = \XF::repository('XF:FeaturedContent')->getFeatureHandler($type, true);

		$feature = \XF::em()->create('XF:FeaturedContent');
		$feature->bulkSet([
			'content_type' => $type,
			'content_id' => $content->getEntityId(),
			'content_container_id' => $handler->getContentContainerId($content),
			'content_user_id' => $handler->getContentUserId($content),
			'content_username' => $handler->getContentUsername($content),
			'content_date' => $handler->getContentDate($content),
			'content_visible' => $handler->getContentVisibility($content),
			'feature_user_id' => $byUserId,
			'feature_date' => $date,
		]);
		$feature->save();

		$content->fastUpdate('featured', true);
	}

	/**
	 * The record is the content's, and goes before it does.
	 */
	protected static function unfeature(Entity $content): void
	{
		if ($content->Feature)
		{
			$content->Feature->delete();
		}
	}
}
