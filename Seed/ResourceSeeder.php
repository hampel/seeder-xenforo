<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ResourceCategoryFactory;
use Hampel\Seeder\Factory\ResourceFactory;
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
 *
 * Every resource is visible and has no file. Nobody is alerted or mailed.
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

		ResourceFactory::new()->recycle($categories)->recycle($authors)->count($this->count('resources', 6))->create();
	}
}
