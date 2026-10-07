<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Clean;
use Hampel\Seeder\Cli\Command\ListBatches;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Repository\RecordRepository;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\Mvc\Entity\Entity;

/**
 * Record what was created, then remove exactly that. Runs against the real database inside a
 * transaction, using captcha questions: a core entity with no cache to rebuild and nothing
 * attached to it.
 *
 * Every test names its own batch. None cleans "every batch": on a forum that holds real seeded
 * content that would walk it, and an entity's delete can do things a rollback does not undo.
 */
class RecordAndCleanTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLE = 'xf_hampel_seeder_record';

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
	}

	public function test_the_install_step_and_the_entity_describe_the_same_table()
	{
		$columns = array_keys($this->app()->db()->fetchAllKeyed('SHOW COLUMNS FROM ' . self::TABLE, 'Field'));

		$this->assertSame(array_keys($this->app()->em()->getEntityStructure('Hampel\Seeder:Record')->columns), $columns);
		$this->assertSame(self::TABLE, TableRecorder::TABLE);
	}

	public function test_an_entity_is_recorded_as_it_is_saved()
	{
		$question = $this->question();

		(new TableRecorder('test-record'))->record($question, 'Some\Factory');

		$this->assertDatabaseHas(self::TABLE, [
			'batch' => 'test-record',
			'entity' => 'XF:CaptchaQuestion',
			'entity_id' => (string) $question->captcha_question_id,
			'factory' => 'Some\Factory',
		]);
	}

	public function test_the_record_carries_the_real_date_when_the_clock_has_been_moved()
	{
		$question = $this->question();
		$realTime = \XF::$time;
		\XF::$time = 86400;

		try
		{
			(new TableRecorder('test-clock'))->record($question);
		}
		finally
		{
			\XF::$time = $realTime;
		}

		$date = $this->app()->db()->fetchOne('SELECT record_date FROM ' . self::TABLE . ' WHERE batch = ?', 'test-clock');
		$this->assertEqualsWithDelta(time(), $date, 5);
	}

	public function test_recording_an_entity_twice_keeps_the_first_record()
	{
		$question = $this->question();

		(new TableRecorder('test-first'))->record($question);
		(new TableRecorder('test-second'))->record($question);

		$this->assertDatabaseCount(self::TABLE, 1, ['entity' => 'XF:CaptchaQuestion', 'entity_id' => (string) $question->captcha_question_id]);
		$this->assertDatabaseHas(self::TABLE, ['batch' => 'test-first']);
	}

	public function test_the_null_recorder_records_nothing()
	{
		$before = $this->app()->db()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);

		(new NullRecorder())->record($this->question());

		$this->assertSame($before, $this->app()->db()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
	}

	public function test_clean_removes_the_entities_and_their_records()
	{
		$recorder = new TableRecorder('test-clean');
		$ids = [];
		foreach (range(1, 3) AS $i)
		{
			$question = $this->question();
			$recorder->record($question);
			$ids[] = $question->captcha_question_id;
		}

		$result = (new Cleaner())->clean('test-clean');

		$this->assertSame(['XF:CaptchaQuestion' => 3], $result['removed']);
		$this->assertSame([], $result['missing']);
		$this->assertSame([], $result['failed']);
		foreach ($ids AS $id)
		{
			$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $id]);
		}
		$this->assertDatabaseCount(self::TABLE, 0, ['batch' => 'test-clean']);
	}

	public function test_clean_leaves_what_the_batch_did_not_create()
	{
		$mine = $this->question();
		$other = $this->question();
		$unrecorded = $this->question();
		(new TableRecorder('test-mine'))->record($mine);
		(new TableRecorder('test-other'))->record($other);

		(new Cleaner())->clean('test-mine');

		$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $mine->captcha_question_id]);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $other->captcha_question_id]);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $unrecorded->captcha_question_id]);
		$this->assertDatabaseCount(self::TABLE, 1, ['batch' => 'test-other']);
	}

	/**
	 * Creation order is dependency order, so the newest record goes first. More records than one
	 * chunk, so the cursor is exercised too.
	 */
	public function test_clean_walks_the_batch_newest_first()
	{
		$recorder = new TableRecorder('test-order');
		$created = [];
		foreach (range(1, 7) AS $i)
		{
			$question = $this->question();
			$recorder->record($question);
			$created[] = $question->captcha_question_id;
		}

		$cleaner = new class extends Cleaner
		{
			protected const CHUNK = 3;

			public $order = [];

			protected function remove(Entity $entity, ?string $factory): void
			{
				$this->order[] = $entity->captcha_question_id;
				parent::remove($entity, $factory);
			}
		};
		$result = $cleaner->clean('test-order');

		$this->assertSame(array_reverse($created), $cleaner->order);
		$this->assertSame(['XF:CaptchaQuestion' => 7], $result['removed']);
	}

	public function test_an_entity_already_deleted_is_reported_and_its_record_cleared()
	{
		$question = $this->question();
		(new TableRecorder('test-missing'))->record($question);
		$question->delete();

		$result = (new Cleaner())->clean('test-missing');

		$this->assertSame([], $result['removed']);
		$this->assertSame(['XF:CaptchaQuestion' => 1], $result['missing']);
		$this->assertDatabaseCount(self::TABLE, 0, ['batch' => 'test-missing']);
	}

	/**
	 * A record that cannot be removed stays, the rest of the batch is still cleaned, and the run
	 * ends - a failed record must not be offered again for ever.
	 */
	public function test_a_failed_removal_keeps_its_record_and_does_not_stop_the_rest()
	{
		$recorder = new TableRecorder('test-fail');
		$stubborn = $this->question();
		$ordinary = $this->question();
		$recorder->record($stubborn);
		$recorder->record($ordinary);

		$result = $this->stubbornCleaner($stubborn->captcha_question_id)->clean('test-fail');

		$this->assertSame(['XF:CaptchaQuestion' => 1], $result['removed']);
		$this->assertCount(1, $result['failed']);
		$this->assertSame((string) $stubborn->captcha_question_id, $result['failed'][0]['id']);
		$this->assertStringContainsString('will not go', $result['failed'][0]['error']);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $stubborn->captcha_question_id]);
		$this->assertDatabaseCount(self::TABLE, 1, ['batch' => 'test-fail']);
	}

	public function test_a_record_for_an_entity_type_that_no_longer_exists_fails_and_stays()
	{
		$this->app()->db()->insert(self::TABLE, [
			'batch' => 'test-gone', 'entity' => 'Vendor\Removed:Thing', 'entity_id' => '1', 'record_date' => time(),
		]);

		$result = (new Cleaner())->clean('test-gone');

		$this->assertCount(1, $result['failed']);
		$this->assertDatabaseCount(self::TABLE, 1, ['batch' => 'test-gone']);
	}

	public function test_the_clean_command_refuses_when_seeding_is_switched_off()
	{
		$question = $this->question();
		(new TableRecorder('test-guard'))->record($question);
		$this->setConfig('hampelSeeder', []);

		[$code, $output] = $this->runCommand(new Clean(), ['batch' => 'test-guard', '--force' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Seeding is switched off', $output);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $question->captcha_question_id]);
	}

	public function test_the_clean_command_asks_first_and_deletes_nothing_on_no()
	{
		$question = $this->question();
		(new TableRecorder('test-ask'))->record($question);

		[$code, $output] = $this->runCommand(new Clean(), ['batch' => 'test-ask'], ['n']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Delete 1 seeded record(s) in 1 batch(es)', $output);
		$this->assertStringContainsString('Nothing was deleted', $output);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $question->captcha_question_id]);

		[$code] = $this->runCommand(new Clean(), ['batch' => 'test-ask'], ['y']);

		$this->assertSame(0, $code);
		$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $question->captcha_question_id]);
	}

	/**
	 * An agent with no terminal gets the question's default, which has to be no.
	 */
	public function test_the_clean_command_deletes_nothing_without_force_when_nobody_can_answer()
	{
		$question = $this->question();
		(new TableRecorder('test-quiet'))->record($question);

		$command = new Clean();
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		$tester = new CommandTester($command);
		$code = $tester->execute(['batch' => 'test-quiet'], ['interactive' => false]);

		$this->assertSame(1, $code);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $question->captcha_question_id]);
	}

	public function test_the_clean_command_reports_json_and_exits_zero()
	{
		(new TableRecorder('test-json'))->record($this->question());

		[$code, $output] = $this->runCommand(new Clean(), ['batch' => 'test-json', '--force' => true, '--json' => true]);

		$this->assertSame(0, $code);
		$this->assertSame(
			['batches' => [['batch' => 'test-json', 'removed' => ['XF:CaptchaQuestion' => 1], 'missing' => [], 'failed' => []]]],
			json_decode($output, true)
		);
	}

	public function test_json_without_force_is_refused()
	{
		$question = $this->question();
		(new TableRecorder('test-json-ask'))->record($question);

		[$code] = $this->runCommand(new Clean(), ['batch' => 'test-json-ask', '--json' => true]);

		$this->assertSame(1, $code);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $question->captcha_question_id]);
	}

	public function test_the_clean_command_exits_one_when_something_could_not_be_removed()
	{
		$question = $this->question();
		(new TableRecorder('test-exit'))->record($question);

		$stubborn = $this->stubbornCleaner($question->captcha_question_id);
		$command = new class($stubborn) extends Clean
		{
			private $stubborn;

			public function __construct(Cleaner $stubborn)
			{
				$this->stubborn = $stubborn;
				parent::__construct();
			}

			protected function cleaner(): Cleaner
			{
				return $this->stubborn;
			}
		};

		[$code, $output] = $this->runCommand($command, ['batch' => 'test-exit', '--force' => true]);

		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/failed\s+XF:CaptchaQuestion ' . $question->captcha_question_id . ' - .*will not go/', $output);
	}

	public function test_an_unknown_batch_is_an_error()
	{
		[$code, $output] = $this->runCommand(new Clean(), ['batch' => 'test-no-such-batch', '--force' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString("no batch named 'test-no-such-batch'", $output);
	}

	public function test_list_shows_each_batch_and_what_it_holds()
	{
		$recorder = new TableRecorder('test-list');
		$recorder->record($this->question());
		$recorder->record($this->question());

		$batches = $this->app()->repository(RecordRepository::class)->getBatches();
		$this->assertSame(2, $batches['test-list']['records']);
		$this->assertSame(['XF:CaptchaQuestion' => 2], $batches['test-list']['entities']);
		$this->assertTrue($this->app()->repository(RecordRepository::class)->batchExists('test-list'));
		$this->assertFalse($this->app()->repository(RecordRepository::class)->batchExists('test-not-there'));

		[$code, $output] = $this->runCommand(new ListBatches());
		$this->assertSame(0, $code);
		$this->assertStringContainsString('test-list', $output);
		$this->assertStringContainsString('XF:CaptchaQuestion x 2', $output);

		[, $json] = $this->runCommand(new ListBatches(), ['--json' => true]);
		$listed = array_column(json_decode($json, true)['batches'], null, 'batch');
		$this->assertSame(2, $listed['test-list']['records']);
		$this->assertSame(['XF:CaptchaQuestion' => 2], $listed['test-list']['entities']);
	}

	public function test_list_works_with_seeding_switched_off()
	{
		$this->setConfig('hampelSeeder', []);
		(new TableRecorder('test-list-off'))->record($this->question());

		[$code, $output] = $this->runCommand(new ListBatches());

		$this->assertSame(0, $code);
		$this->assertStringContainsString('test-list-off', $output);
	}

	public function test_the_new_commands_are_valid_xenforo_commands()
	{
		$runner = new \XF\Cli\Runner();
		$isValid = new \ReflectionMethod($runner, 'isValidCommandClass');

		$this->assertTrue($isValid->invoke($runner, Clean::class));
		$this->assertTrue($isValid->invoke($runner, ListBatches::class));
	}

	private function question(): Entity
	{
		return $this->createEntity('XF:CaptchaQuestion', [
			'question' => 'Seeder test ' . bin2hex(random_bytes(6)),
			'answers' => ['yes'],
		]);
	}

	private function stubbornCleaner(int $refuses): Cleaner
	{
		return new class($refuses) extends Cleaner
		{
			private $refuses;

			public function __construct(int $refuses)
			{
				$this->refuses = $refuses;
			}

			protected function remove(Entity $entity, ?string $factory): void
			{
				if ($entity->captcha_question_id === $this->refuses)
				{
					throw new \RuntimeException('this one will not go');
				}

				parent::remove($entity, $factory);
			}
		};
	}

	private function runCommand($command, array $input = [], array $answers = []): array
	{
		// cmd.php supplies the helpers through its application; a bare command has none
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		$tester = new CommandTester($command);
		$tester->setInputs($answers);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
