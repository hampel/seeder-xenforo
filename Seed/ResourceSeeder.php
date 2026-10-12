<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ResourceCategoryFactory;
use Hampel\Seeder\Factory\ResourceFactory;
use Hampel\Seeder\Factory\ResourceRatingFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * Resources in XenForo Resource Manager, which has to be installed.
 *
 * Parameters:
 *
 *     resources    how many (6)
 *     categories   how many categories to create for them (1)
 *     category     an existing resource category's id, to use it instead of creating any
 *     authors      how many members to create as their authors (3)
 *     reviews      how many ratings with a written review each resource gets (0)
 *     ratings      how many ratings with nothing written each resource gets as well (0)
 *     featured     how many of the resources are featured (0)
 *
 * Every resource is visible and has no file. A rating is by one of the other authors, or by a
 * new member where a resource needs more of them than there are. Nobody is alerted or mailed.
 */
class ResourceSeeder extends AbstractSeeder
{
	public function run()
	{
		if (!\XF::isAddOnActive('XFRM'))
		{
			throw new \InvalidArgumentException('The Resource seeder needs XenForo Resource Manager (XFRM), which is not installed and active on this forum');
		}

		$existing = $this->param('category');
		if ($existing !== null)
		{
			$category = \XF::em()->find('XFRM:Category', $existing);
			if (!$category)
			{
				throw new \InvalidArgumentException("There is no resource category {$existing}");
			}
			$categories = [$category];
		}
		else
		{
			$categories = ResourceCategoryFactory::new()->count(max(1, $this->count('categories', 1)))->create();
		}

		$authors = UserFactory::new()->count(max(1, $this->count('authors', 3)))->create();

		$resources = ResourceFactory::new()->recycle($categories)->recycle($authors);
		$count = $this->count('resources', 6);
		$featured = min($count, $this->count('featured', 0));

		$made = array_merge(
			$resources->featured()->count($featured)->create()->toArray(),
			$resources->count($count - $featured)->create()->toArray()
		);

		$reviews = $this->count('reviews', 0);
		$ratings = $this->count('ratings', 0);
		// those made to rate one resource can rate the next as well
		$raters = [];
		foreach ($authors AS $author)
		{
			$raters[$author->user_id] = $author;
		}
		foreach ($made AS $resource)
		{
			foreach ([[$reviews, false], [$ratings, true]] AS [$wanted, $bare])
			{
				for ($i = 0; $i < $wanted; $i++)
				{
					$factory = ResourceRatingFactory::new()->for($resource)->recycle($raters);
					$rating = ($bare ? $factory->withoutReview() : $factory)->create();
					$raters[$rating->user_id] = $rating->User;
				}
			}
		}
	}
}
