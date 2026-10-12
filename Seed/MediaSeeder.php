<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\AlbumFactory;
use Hampel\Seeder\Factory\MediaCategoryFactory;
use Hampel\Seeder\Factory\MediaCommentFactory;
use Hampel\Seeder\Factory\MediaFactory;
use Hampel\Seeder\Factory\UserFactory;

/**
 * Media items in XenForo Media Gallery, which has to be installed: real images, some in a
 * category, some in an album, and some where a guest cannot view them.
 *
 * Parameters:
 *
 *     media        how many items in categories (6)
 *     categories   how many media categories to create for them (1)
 *     category     an existing media category's id, to use it instead of creating any
 *     albums       how many albums anyone may view, each with two or three items (1)
 *     hidden       how many items in a private album, which a guest cannot view (1)
 *     authors      how many members to create as their authors (3)
 *     comments     how many comments each item in a category gets, from the authors (0)
 *
 * Nobody is alerted or mailed.
 */
class MediaSeeder extends AbstractSeeder
{
	public function run()
	{
		if (!\XF::isAddOnActive('XFMG'))
		{
			throw new \InvalidArgumentException('The Media seeder needs XenForo Media Gallery (XFMG), which is not installed and active on this forum');
		}

		$existing = $this->param('category');
		if ($existing !== null)
		{
			$category = \XF::em()->find('XFMG:Category', $existing);
			if (!$category || $category->category_type !== 'media')
			{
				throw new \InvalidArgumentException("There is no media category {$existing} that holds media items");
			}
			$categories = [$category];
		}
		else
		{
			$categories = MediaCategoryFactory::new()->count(max(1, $this->count('categories', 1)))->create();
		}

		$authors = UserFactory::new()->count(max(1, $this->count('authors', 3)))->create();
		$media = MediaFactory::new()->recycle($authors);

		$items = $media->recycle($categories)->count($this->count('media', 6));
		$comments = $this->count('comments', 0);
		if ($comments)
		{
			$items = $items->has(MediaCommentFactory::new()->count($comments));
		}
		$items->create();

		for ($i = $this->count('albums', 1); $i > 0; $i--)
		{
			$album = AlbumFactory::new()->recycle($authors)->create();
			$media->inAlbum($album)->count([2, 3])->create(['user_id' => $album->user_id]);
		}

		$hidden = $this->count('hidden', 1);
		if ($hidden)
		{
			$album = AlbumFactory::new()->recycle($authors)->private()->create();
			$media->inAlbum($album)->count($hidden)->create(['user_id' => $album->user_id]);
		}
	}
}
