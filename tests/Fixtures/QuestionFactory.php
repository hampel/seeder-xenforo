<?php namespace Tests\Fixtures;

use Hampel\Seeder\Factory\AbstractFactory;
use XF\Mvc\Entity\Entity;

/**
 * The smallest factory there can be, over a core entity with nothing attached to it.
 */
class QuestionFactory extends AbstractFactory
{
	protected $entity = 'XF:CaptchaQuestion';

	/** @var int|null what clockFor() answers */
	public static $clock;

	public function definition()
	{
		return [
			'question' => 'Seeder test ' . bin2hex(random_bytes(6)),
			'answers' => ['yes'],
			'active' => false,
		];
	}

	public function active()
	{
		return $this->state(['active' => true]);
	}

	public static function describe(Entity $entity): array
	{
		return ['question' => $entity->question];
	}

	protected function clockFor(Entity $entity): ?int
	{
		return self::$clock;
	}
}
