<?php namespace Tests\Unit;

use Hampel\Seeder\Context;
use Hampel\Seeder\Seed\DefaultSeeder;
use Hampel\Seeder\Seed\Resolver;
use Hampel\Seeder\Seed\UserSeeder;
use Hampel\Seeder\Cli\Command\Run;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\QuestionFactory;
use Tests\Fixtures\QuestionSeeder;
use Tests\TestCase;
use XF\Mvc\Entity\ArrayCollection;

/**
 * The factory's Laravel-shaped surface, using make() - nothing here touches the database.
 */
class FactoryTest extends TestCase
{
	public function test_make_builds_an_unsaved_entity_from_the_definition()
	{
		$question = QuestionFactory::new()->make();

		$this->assertSame('XF:CaptchaQuestion', $question->structure()->shortName);
		$this->assertStringStartsWith('Seeder test ', $question->question);
		$this->assertFalse($question->active);
		$this->assertFalse($question->exists());
	}

	public function test_overrides_win_over_states_which_win_over_the_definition()
	{
		$factory = QuestionFactory::new(['question' => 'from new'])->active();

		$this->assertSame('from new', $factory->make()->question);
		$this->assertTrue($factory->make()->active);
		$this->assertSame('from make', $factory->make(['question' => 'from make'])->question);
		$this->assertFalse($factory->make(['active' => false])->active);
	}

	public function test_states_apply_in_the_order_they_were_added()
	{
		$factory = QuestionFactory::new()->state(['question' => 'first'])->state(['question' => 'second']);

		$this->assertSame('second', $factory->make()->question);
	}

	public function test_a_state_closure_sees_the_attributes_so_far()
	{
		$factory = QuestionFactory::new(['question' => 'base'])->state(function (array $attributes)
		{
			return ['question' => $attributes['question'] . ' and more'];
		});

		$this->assertSame('base and more', $factory->make()->question);
	}

	public function test_an_attribute_closure_is_given_the_other_attributes()
	{
		$raw = QuestionFactory::new()->raw([
			'active' => true,
			'question' => function (array $attributes) { return $attributes['active'] ? 'is active' : 'is not'; },
		]);

		$this->assertSame('is active', $raw['question']);
	}

	public function test_every_modifier_returns_a_copy()
	{
		$base = QuestionFactory::new();
		$changed = $base->active()->count(3)->state(['question' => 'changed']);

		$this->assertNotSame($base, $changed);
		$this->assertFalse($base->make()->active);
		$this->assertNotSame('changed', $base->make()->question);
		$this->assertNotInstanceOf(ArrayCollection::class, $base->make());
	}

	public function test_count_gives_a_collection_and_each_entity_its_own_definition()
	{
		$questions = QuestionFactory::new()->count(4)->make();

		$this->assertInstanceOf(ArrayCollection::class, $questions);
		$this->assertCount(4, $questions);
		$this->assertCount(4, array_unique($questions->pluckNamed('question')));
		$this->assertCount(0, QuestionFactory::new()->count(0)->make());
	}

	public function test_a_sequence_cycles_and_a_closure_in_it_is_given_the_index()
	{
		$questions = QuestionFactory::new()->count(5)
			->sequence(['question' => 'a'], ['question' => 'b'], function ($index) { return ['question' => "c{$index}"]; })
			->make();

		$this->assertSame(['a', 'b', 'c2', 'a', 'b'], array_values($questions->pluckNamed('question')));
	}

	public function test_after_making_runs_for_each_entity_and_after_creating_does_not_run_for_make()
	{
		$made = 0;
		$created = 0;

		QuestionFactory::new()->count(3)
			->afterMaking(function () use (&$made) { $made++; })
			->afterCreating(function () use (&$created) { $created++; })
			->make();

		$this->assertSame(3, $made);
		$this->assertSame(0, $created);
	}

	public function test_the_default_context_records_nothing_and_is_replaced_by_use()
	{
		$previous = Context::use(null);

		try
		{
			$default = Context::current();
			$this->assertInstanceOf(\Hampel\Seeder\NullRecorder::class, $default->recorder);
			$this->assertSame($default, Context::current());

			$mine = new Context(Context::faker('en_US', 1), $default->recorder, $default->timeline, 'secret');
			$this->assertSame($default, Context::use($mine));
			$this->assertSame($mine, Context::current());
		}
		finally
		{
			Context::use($previous);
		}
	}

	public function test_a_seeded_generator_produces_the_same_usable_member_names()
	{
		$first = Context::faker('en_US', 1234);
		$names = [];
		for ($i = 0; $i < 300; $i++)
		{
			$names[] = $first->forumUsername();
		}

		$second = Context::faker('en_US', 1234);
		$again = [];
		for ($i = 0; $i < 300; $i++)
		{
			$again[] = $second->forumUsername();
		}

		$this->assertSame($names, $again);
		foreach ($names AS $name)
		{
			$this->assertMatchesRegularExpression('/^[A-Za-z0-9 ._-]{3,25}$/', $name);
			$this->assertSame($name, trim($name));
		}
		$this->assertGreaterThan(250, count(array_unique($names)), 'names should rarely repeat');

		for ($i = 0; $i < 100; $i++)
		{
			$this->assertLessThanOrEqual(12, strlen($first->forumUsername(12)));
		}
	}

	public static function seederNames(): array
	{
		return [
			['Default', DefaultSeeder::class],
			['User', UserSeeder::class],
			['Hampel/Seeder:User', UserSeeder::class],
			[QuestionSeeder::class, QuestionSeeder::class],
			['\\' . QuestionSeeder::class, QuestionSeeder::class],
		];
	}

	#[DataProvider('seederNames')]
	public function test_a_seeder_is_found_by_name($name, $class)
	{
		$this->assertSame($class, Resolver::resolve($name));
	}

	public static function notSeeders(): array
	{
		return [['NoSuchThing'], ['Vendor/AddOn:Missing'], ['Tests\Fixtures\QuestionFactory'], ['../../etc/passwd'], ['a b'], ['']];
	}

	#[DataProvider('notSeeders')]
	public function test_a_name_that_leads_nowhere_is_refused($name)
	{
		$this->expectException(\InvalidArgumentException::class);

		Resolver::resolve($name);
	}

	public function test_parameters_are_name_value_pairs()
	{
		$this->assertSame(['users' => '50', 'title' => 'a=b', 'empty' => ''], Run::params(['users=50', 'title=a=b', 'empty=']));

		$this->expectException(\InvalidArgumentException::class);
		Run::params(['users']);
	}
}
