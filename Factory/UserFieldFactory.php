<?php namespace Hampel\Seeder\Factory;

use Faker\Generator;
use XF\Mvc\Entity\Entity;

/**
 * A custom user field - one of the extra things a member can say about themselves - with its
 * title and description phrases, and a phrase for each choice where it has choices.
 *
 * `title` and `description` are those phrases. `field_choices` is `[key => label]`.
 *
 * Members are given values with UserFactory's withFields(), or withFieldValues() for a value in
 * every field the forum has.
 */
class UserFieldFactory extends AbstractFactory
{
	protected $entity = 'XF:UserField';

	private const TITLES = [
		'Occupation', 'Favourite team', 'Hobbies', 'Real name', 'Country', 'First car', 'Camera',
		'Pronouns', 'Year joined the club', 'Preferred contact', 'Experience', 'About my setup',
	];

	private const CHOICES = [
		['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'expert' => 'Expert'],
		['email' => 'Email', 'phone' => 'Phone', 'post' => 'Post', 'none' => 'Do not contact me'],
		['north' => 'North', 'south' => 'South', 'east' => 'East', 'west' => 'West', 'central' => 'Central'],
	];

	private const PHRASES = ['title' => true, 'description' => false];

	/** @var \WeakMap the phrase text each unsaved field still needs once it is saved */
	private static $pending;

	public function definition()
	{
		return [
			'field_id' => function ()
			{
				return $this->uniqueId();
			},
			'title' => self::TITLES[mt_rand(0, count(self::TITLES) - 1)],
			'description' => '',
			'display_group' => 'personal',
			'display_order' => mt_rand(1, 20) * 10,
			'field_type' => 'textbox',
			'user_editable' => 'yes',
		];
	}

	/** the kinds of field XenForo offers; its entity allows two more that it then never lists */
	public const TYPES = ['textbox', 'textarea', 'bbcode', 'select', 'radio', 'checkbox', 'multiselect', 'stars'];

	/**
	 * A field of this type: textbox, textarea, bbcode, select, radio, checkbox, multiselect or
	 * stars. One with choices is given a set of them unless field_choices says otherwise.
	 *
	 * A date or a colour is not a type of field in XenForo but a text box that checks its value:
	 * see matching().
	 *
	 * @return static
	 */
	public function type(string $type)
	{
		if (!in_array($type, self::TYPES, true))
		{
			throw new \InvalidArgumentException("'{$type}' is not a type of field; the types are " . implode(', ', self::TYPES)
				. (in_array($type, ['date', 'color'], true) ? ". For a {$type}, use matching('{$type}')" : ''));
		}

		return $this->state(function (array $attributes) use ($type)
		{
			$state = ['field_type' => $type];
			if (in_array($type, ['select', 'radio', 'checkbox', 'multiselect'], true) && empty($attributes['field_choices']))
			{
				$state['field_choices'] = self::CHOICES[mt_rand(0, count(self::CHOICES) - 1)];
			}

			return $state;
		});
	}

	/**
	 * @param array $choices [key => label]; a plain list is keyed by a tidied form of each label
	 *
	 * @return static a select field with these choices, unless type() has made it another kind
	 */
	public function choices(array $choices)
	{
		if (array_keys($choices) === range(0, count($choices) - 1))
		{
			$keyed = [];
			foreach ($choices AS $label)
			{
				$keyed[trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $label)), '_')] = (string) $label;
			}
			$choices = $keyed;
		}

		return $this->state(function (array $attributes) use ($choices)
		{
			$multiple = in_array($attributes['field_type'] ?? '', ['select', 'radio', 'checkbox', 'multiselect'], true);

			return ['field_choices' => $choices] + ($multiple ? [] : ['field_type' => 'select']);
		});
	}

	/**
	 * A text box whose value has to be of this form: number, alphanumeric, email, url, color or
	 * date.
	 *
	 * @return static
	 */
	public function matching(string $form)
	{
		if (!in_array($form, ['number', 'alphanumeric', 'email', 'url', 'color', 'date'], true))
		{
			throw new \InvalidArgumentException("'{$form}' is not a form a seeded field can check for");
		}

		return $this->state(['field_type' => 'textbox', 'match_type' => $form]);
	}

	/** @return static in this group of the member's details: personal, contact or preferences */
	public function group(string $group) { return $this->state(['display_group' => $group]); }

	/** @return static a field a member has to fill in */
	public function required() { return $this->state(['required' => true]); }

	/** @return static asked for when registering */
	public function onRegistration() { return $this->state(['show_registration' => true]); }

	/** @return static shown beside the member's posts as well as on their profile */
	public function onMessages() { return $this->state(['viewable_message' => true]); }

	public static function describe(Entity $entity): array
	{
		return [
			'field_id' => $entity->field_id,
			'title' => (string) $entity->title,
			'field_type' => $entity->field_type,
			'choices' => array_keys($entity->field_choices),
		];
	}

	/**
	 * Deleting a field deletes every member's value for it, as XenForo has it. The list of fields
	 * the forum keeps is put right at once, so nothing later in the run is offered a field that
	 * has gone.
	 */
	public static function remove(Entity $entity): void
	{
		parent::remove($entity);

		self::refresh();
	}

	/**
	 * A value this field would accept, or null where one cannot be made up - a field that checks
	 * its value with a pattern or a callback of the forum's own.
	 *
	 * @param \ArrayAccess|array $field a field's definition, or the entity
	 *
	 * @return string|array|null
	 */
	public static function valueFor($field, Generator $faker)
	{
		$choices = array_keys($field['field_choices'] ?? []);

		switch ($field['field_type'])
		{
			case 'select':
			case 'radio':
				return $choices ? $choices[mt_rand(0, count($choices) - 1)] : null;

			case 'checkbox':
			case 'multiselect':
				if (!$choices)
				{
					return null;
				}
				shuffle($choices);

				return array_slice($choices, 0, mt_rand(1, min(2, count($choices))));

			case 'stars':
				return (string) mt_rand(1, 5);
		}

		$max = (int) ($field['max_length'] ?? 0);

		switch ($field['match_type'] ?? 'none')
		{
			case 'none':
				$value = $field['field_type'] === 'textbox' ? $faker->words(mt_rand(1, 3), true) : $faker->sentence(mt_rand(6, 14));
				break;

			case 'alphanumeric':
				$value = $faker->userName();
				$value = preg_replace('/[^a-zA-Z0-9_]/', '', $value);
				break;

			case 'number':
				// only where any number will do: a field can set a range of its own
				if (array_filter((array) ($field['match_params'] ?? []), function ($param) { return $param !== '' && $param !== null && $param !== false; }))
				{
					return null;
				}
				$value = (string) mt_rand(1, 99);
				break;

			case 'color':
				return sprintf('rgb(%d, %d, %d)', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));

			case 'email':
				$value = $faker->safeEmail();
				break;

			case 'url':
				$value = 'https://example.com/' . $faker->slug(2);
				break;

			case 'date':
				// in the past, which suits a field that asks for a past date and one that does not;
				// one that asks for a future date is left empty
				if (($field['match_params']['date_constraint'] ?? '') === 'future')
				{
					return null;
				}

				return date('Y-m-d', mt_rand(strtotime('1950-01-01'), strtotime('2005-12-31')));

			default:
				return null;
		}

		return $max ? substr($value, 0, $max) : $value;
	}

	/**
	 * XenForo rebuilds its list of fields when the request ends, and keeps the list it first read
	 * for the life of the process. Neither will do where a field is made and then used at once.
	 */
	public static function refresh(): void
	{
		\XF::triggerRunOnce();
		\XF::app()->container()->decache('customFields.users');
	}

	protected function newEntity(array $attributes): Entity
	{
		$phrases = array_intersect_key($attributes, self::PHRASES);
		$field = parent::newEntity(array_diff_key($attributes, self::PHRASES));

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$field] = $phrases;

		return $field;
	}

	protected function store(Entity $entity): void
	{
		$entity->save();

		// as the control panel does it: the phrases are named after the field's id
		foreach (self::$pending[$entity] AS $attribute => $text)
		{
			$phrase = $entity->getMasterPhrase(self::PHRASES[$attribute]);
			$phrase->phrase_text = (string) $text;
			$phrase->save();
		}

		self::refresh();
	}

	/**
	 * An id no field has: letters, digits and underscores, 25 characters at most.
	 */
	protected function uniqueId(): string
	{
		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$id = 'seed_' . substr(preg_replace('/[^a-z]/', '', strtolower($this->context()->faker->word())), 0, 12) . mt_rand(10, 99);
			if (!\XF::db()->fetchOne('SELECT 1 FROM xf_user_field WHERE field_id = ?', $id))
			{
				return $id;
			}
		}

		throw new \RuntimeException('Could not find a free field id after 50 attempts');
	}
}
