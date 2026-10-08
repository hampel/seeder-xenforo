<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * A warning definition - one of the reasons a moderator picks from when warning a member - with
 * its three phrases. `title`, `conversation_title` and `conversation_text` are those phrases.
 */
class WarningDefinitionFactory extends AbstractFactory
{
	protected $entity = 'XF:WarningDefinition';

	private const TITLES = [
		'Spam', 'Advertising', 'Off-topic posting', 'Inappropriate language', 'Personal attack', 'Trolling',
		'Duplicate account', 'Inappropriate content', 'Thread hijacking', 'Posting in the wrong forum',
		'Excessive quoting', 'Cross-posting', 'Signature violation', 'Harassment', 'Misleading title',
		'Bumping threads', 'Sharing personal information', 'Copyright infringement', 'Ban evasion',
		'Ignoring moderator instructions', 'all caps posting', '2nd account abuse',
	];

	private const PHRASES = [
		'title' => 'MasterTitle',
		'conversation_title' => 'MasterConversationTitle',
		'conversation_text' => 'MasterConversationText',
	];

	/** @var \WeakMap the phrase text each unsaved definition still needs once it has an id */
	private static $pending;

	public function definition()
	{
		return [
			'title' => self::TITLES[mt_rand(0, count(self::TITLES) - 1)],
			'conversation_title' => 'Warning: {title}',
			'conversation_text' => "{name},\n\nYour message ({title}) has been found to break the rules of this forum.\n\n{url}\n\n{staff}",
			'points_default' => mt_rand(1, 5),
			'expiry_type' => 'months',
			'expiry_default' => mt_rand(1, 6),
			'is_editable' => true,
		];
	}

	/** @return static a warning that never expires */
	public function permanent() { return $this->state(['expiry_type' => 'never', 'expiry_default' => 0]); }

	/** @return static */
	public function points(int $points) { return $this->state(['points_default' => $points]); }

	public static function describe(Entity $entity): array
	{
		return ['title' => (string) $entity->title, 'points' => $entity->points_default];
	}

	protected function newEntity(array $attributes): Entity
	{
		$phrases = array_intersect_key($attributes, self::PHRASES);
		$definition = parent::newEntity(array_diff_key($attributes, self::PHRASES));

		self::$pending = self::$pending ?? new \WeakMap();
		self::$pending[$definition] = $phrases;

		return $definition;
	}

	protected function store(Entity $entity): void
	{
		$entity->save();

		// as the control panel does it: the phrases are named after the id, so they come second
		foreach (self::$pending[$entity] AS $attribute => $relation)
		{
			$phrase = $entity->getRelationOrDefault(self::PHRASES[$attribute], false);
			$phrase->addon_id = '';
			$phrase->phrase_text = (string) $relation;
			$phrase->save();
		}
	}
}
