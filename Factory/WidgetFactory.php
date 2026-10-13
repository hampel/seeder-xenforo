<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;
use XF\Util\File;

/**
 * A widget, with its title phrase, shown in one or more widget positions. Left alone it is the
 * forum statistics widget, in the sidebar of the forum list.
 *
 * `title` and `html` are not columns: the title goes into the widget's phrase, and the markup
 * into the template an HTML widget shows.
 */
class WidgetFactory extends AbstractFactory
{
	protected $entity = 'XF:Widget';

	/** @var \WeakMap what each unsaved widget still needs when it is stored */
	private static $pending;

	public function definition()
	{
		$faker = $this->context()->faker;

		return [
			'widget_key' => function ()
			{
				return $this->uniqueKey();
			},
			'definition_id' => 'forum_statistics',
			'title' => ucfirst($faker->words(mt_rand(2, 3), true)),
			'html' => '',
			'positions' => ['forum_list_sidebar' => mt_rand(1, 20) * 10],
			'options' => [],
		];
	}

	/**
	 * A widget of another definition - `members_online`, `new_posts`, `online_statistics`, or one
	 * another add-on adds - with the options that definition takes. Options left out are the
	 * definition's own defaults.
	 *
	 * @return static
	 */
	public function of(string $definitionId, array $options = [])
	{
		return $this->state(['definition_id' => $definitionId, 'options' => $options]);
	}

	/**
	 * Shown in these widget positions, and no others - `forum_list_sidebar`, `thread_view_sidebar`,
	 * `pub_sidebar_top`. With none it is in no position, and is shown nowhere.
	 *
	 * @return static
	 */
	public function at(string ...$positions)
	{
		return $this->state(function () use ($positions)
		{
			$order = mt_rand(1, 20) * 10;

			return ['positions' => array_fill_keys($positions, $order)];
		});
	}

	/**
	 * An HTML widget showing this markup, or a sentence if none is given. The markup is a
	 * template: XenForo's template syntax works in it, and text is not escaped.
	 *
	 * An HTML widget has a template of its own, and saving or deleting a template does to the
	 * forum what editing one in the admin control panel does: every style is marked as changed
	 * and the cached CSS is emptied.
	 *
	 * @return static
	 */
	public function html(?string $markup = null)
	{
		return $this->state(function () use ($markup)
		{
			return [
				'definition_id' => 'html',
				'options' => [],
				'html' => $markup ?? $this->context()->faker->sentence(mt_rand(8, 14)),
			];
		});
	}

	public static function describe(Entity $entity): array
	{
		return [
			'widget_key' => $entity->widget_key,
			'definition_id' => $entity->definition_id,
			'positions' => array_keys($entity->positions),
		];
	}

	/**
	 * XenForo compiles each widget to a file and does not delete it with the widget.
	 */
	public static function remove(Entity $entity): void
	{
		$compiled = self::compiledPath($entity);

		parent::remove($entity);

		File::deleteFromAbstractedPath($compiled);
	}

	protected function newEntity(array $attributes): Entity
	{
		$definitionId = (string) $attributes['definition_id'];
		if (!\XF::em()->find('XF:WidgetDefinition', $definitionId))
		{
			throw new \InvalidArgumentException("'{$definitionId}' is not a widget definition this forum has");
		}
		foreach (array_keys((array) $attributes['positions']) AS $positionId)
		{
			if (!\XF::em()->find('XF:WidgetPosition', $positionId))
			{
				throw new \InvalidArgumentException("'{$positionId}' is not a widget position this forum has");
			}
		}

		$pending = ['title' => (string) $attributes['title'], 'html' => (string) $attributes['html']];
		unset($attributes['title'], $attributes['html']);

		if ($definitionId === 'html')
		{
			$attributes['options'] = [
				'advanced_mode' => !empty($attributes['options']['advanced_mode']),
				'template_title' => '_widget_' . $attributes['widget_key'],
			];
		}

		$widget = parent::newEntity($attributes);

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$widget] = $pending;

		return $widget;
	}

	protected function store(Entity $entity): void
	{
		$pending = self::$pending[$entity];

		// an HTML widget shows a template of its own, which XenForo's widget form saves before
		// the widget; deleting the widget deletes it
		if ($entity->definition_id === 'html')
		{
			$template = \XF::em()->create('XF:Template');
			$template->type = 'public';
			$template->title = $entity->options['template_title'];
			$template->style_id = 0;
			$template->addon_id = '';
			$template->template = '<div class="block-body block-row">' . $pending['html'] . '</div>';
			$template->save();
		}

		$entity->save();

		$phrase = $entity->getMasterPhrase();
		$phrase->phrase_text = $pending['title'];
		$phrase->save();
	}

	/**
	 * A key no widget has: letters, digits and underscores, 50 characters at most.
	 */
	protected function uniqueKey(): string
	{
		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$key = 'seed_' . substr(preg_replace('/[^a-z]/', '', strtolower($this->context()->faker->word())), 0, 20) . mt_rand(100, 999);
			if (!\XF::db()->fetchOne('SELECT 1 FROM xf_widget WHERE widget_key = ?', $key))
			{
				return $key;
			}
		}

		throw new \RuntimeException('Could not find a free widget key after 50 attempts');
	}

	private static function compiledPath(Entity $entity): string
	{
		return "code-cache://widgets/_{$entity->widget_id}_{$entity->widget_key}.php";
	}
}
