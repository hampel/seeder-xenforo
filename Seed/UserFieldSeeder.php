<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserFieldFactory;

/**
 * Custom user fields of several kinds, and members with values in them.
 *
 * Parameters:
 *
 *     fields    how many fields to create, cycling through a text box, a choice of one, a text
 *               area, a choice of several, a date, a star rating, radio buttons, check boxes,
 *               BB code, a colour and a number (4); with 0 the forum's own fields are used
 *     members   how many members to create with values (20)
 *     filled    the percentage of fields each member has a value in (80)
 *
 * Members get values in every field the forum has, its own included.
 */
class UserFieldSeeder extends AbstractSeeder
{
	/** [type or the form a text box checks for, title, choices] */
	private const KINDS = [
		['textbox', 'Occupation', null],
		['select', 'Experience', ['Beginner', 'Intermediate', 'Advanced', 'Expert']],
		['textarea', 'About my setup', null],
		['multiselect', 'Interests', ['Racing', 'Touring', 'Restoration', 'Photography', 'Track days']],
		['date', 'Member of the club since', null],
		['stars', 'How I rate my own skills', null],
		['radio', 'Preferred contact', ['Email', 'Phone', 'Post', 'Do not contact me']],
		['checkbox', 'Newsletters', ['Monthly', 'Events', 'Classifieds']],
		['bbcode', 'My projects', null],
		['color', 'Favourite colour', null],
		['number', 'Years of experience', null],
	];

	private const GROUPS = ['personal', 'contact', 'preferences'];

	public function run()
	{
		$fields = $this->count('fields', 4);
		$filled = $this->count('filled', 80);
		if ($filled > 100)
		{
			throw new \InvalidArgumentException('filled is a percentage: 0 to 100');
		}

		for ($i = 0; $i < $fields; $i++)
		{
			[$kind, $title, $choices] = self::KINDS[$i % count(self::KINDS)];
			$round = intdiv($i, count(self::KINDS));

			$field = UserFieldFactory::new()->group(self::GROUPS[$i % count(self::GROUPS)]);
			$field = in_array($kind, UserFieldFactory::TYPES, true) ? $field->type($kind) : $field->matching($kind);
			if ($choices)
			{
				$field = $field->choices($choices);
			}

			$field->create(['title' => $title . ($round ? ' ' . ($round + 1) : ''), 'display_order' => ($i + 1) * 10]);
		}

		UserFactory::new()->withFieldValues($filled / 100)->count($this->count('members', 20))->create();
	}
}
