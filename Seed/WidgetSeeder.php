<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\WidgetFactory;

/**
 * Widgets with a title, in one widget position: forum statistics, members online and new posts
 * by turns.
 *
 * Parameters:
 *
 *     widgets    how many (3)
 *     position   the widget position they are shown in (forum_list_sidebar)
 *     html       how many more are HTML widgets with a sentence of text (0). Each has a template
 *                of its own, and saving or deleting a template marks every style as changed and
 *                empties the cached CSS, as editing one in the admin control panel does
 *
 * They are on the forum's own pages for every visitor until the batch is cleaned.
 */
class WidgetSeeder extends AbstractSeeder
{
	public function run()
	{
		$position = (string) $this->param('position', 'forum_list_sidebar');

		$html = $this->count('html', 0);

		WidgetFactory::new()
			->count($this->count('widgets', 3))
			->at($position)
			->sequence(
				[],
				['definition_id' => 'members_online'],
				['definition_id' => 'new_posts']
			)
			->create();

		if ($html)
		{
			WidgetFactory::new()->count($html)->at($position)->html()->create();
		}
	}
}
