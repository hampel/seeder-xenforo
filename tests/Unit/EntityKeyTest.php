<?php namespace Tests\Unit;

use Hampel\Seeder\Record\EntityKey;
use Hampel\Seeder\Record\TableRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EntityKeyTest extends TestCase
{
	public function test_a_single_column_key_is_stored_as_it_is()
	{
		$entity = $this->app()->em()->create('XF:CaptchaQuestion');
		$entity->setTrusted('captcha_question_id', 42);

		$this->assertSame('42', EntityKey::of($entity));
		$this->assertSame('42', EntityKey::decode('42'));
	}

	public function test_a_compound_key_round_trips_keyed_by_column()
	{
		$entity = $this->makeEntity('XF:UserFollow', ['user_id' => 7, 'follow_user_id' => 9]);

		$key = EntityKey::of($entity);

		$this->assertSame(['user_id' => 7, 'follow_user_id' => 9], EntityKey::decode($key));
	}

	public function test_an_unsaved_entity_cannot_be_recorded()
	{
		$this->expectException(\LogicException::class);

		EntityKey::of($this->app()->em()->create('XF:CaptchaQuestion'));
	}

	public static function batchNames(): array
	{
		return [
			['run-1', true],
			['2026-10-07_14.30', true],
			[str_repeat('a', 50), true],
			[str_repeat('a', 51), false],
			['', false],
			['two words', false],
			["quote'", false],
			['slash/name', false],
		];
	}

	#[DataProvider('batchNames')]
	public function test_batch_names_are_restricted_to_what_is_safe_to_type($name, $valid)
	{
		$this->assertSame($valid, TableRecorder::isValidBatchName($name));

		if (!$valid)
		{
			$this->expectException(\InvalidArgumentException::class);
		}
		$this->assertSame($name, (new TableRecorder($name))->getBatch());
	}
}
