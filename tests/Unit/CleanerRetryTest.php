<?php namespace Tests\Unit;

use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Tests\TestCase;
use XF\Db\DeadlockException;
use XF\Mvc\Entity\Entity;

/**
 * On a forum several things write to at once, a removal can lose a deadlock. MySQL has undone it
 * whole by then, so the cleaner tries it again instead of leaving it for another run.
 *
 * Deliberately not inside a transaction of the test's own: the retry is only made where the
 * cleaner's was the outermost one. It writes two rows for real and removes them itself.
 */
class CleanerRetryTest extends TestCase
{
	/** @var int[] */
	private $questions = [];

	protected function tearDown(): void
	{
		$db = $this->app()->db();
		if ($this->questions)
		{
			$db->delete('xf_captcha_question', 'captcha_question_id IN (' . $db->quote($this->questions) . ')');
		}
		$db->delete(TableRecorder::TABLE, "batch LIKE 'test-retry-%'");

		parent::tearDown();
	}

	public function test_a_removal_that_loses_a_deadlock_is_tried_again()
	{
		$question = $this->question('test-retry-once');

		$cleaner = $this->cleanerThatDeadlocks(2);
		$result = $cleaner->clean('test-retry-once');

		$this->assertSame(['XF:CaptchaQuestion' => 1], $result['removed']);
		$this->assertSame([], $result['failed']);
		$this->assertSame(3, $cleaner->attempts, 'two deadlocks, then it went through');
		$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $question]);
		$this->assertDatabaseCount(TableRecorder::TABLE, 0, ['batch' => 'test-retry-once']);
		$this->assertFalse($this->app()->db()->inTransaction(), 'no transaction was left open');
	}

	public function test_it_gives_up_after_three_tries_and_keeps_the_record()
	{
		$question = $this->question('test-retry-never');

		$cleaner = $this->cleanerThatDeadlocks(99);
		$result = $cleaner->clean('test-retry-never');

		$this->assertSame(3, $cleaner->attempts);
		$this->assertCount(1, $result['failed']);
		$this->assertStringContainsString('DeadlockException', $result['failed'][0]['error']);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $question]);
		$this->assertDatabaseCount(TableRecorder::TABLE, 1, ['batch' => 'test-retry-never']);
		$this->assertFalse($this->app()->db()->inTransaction());
	}

	private function question(string $batch): int
	{
		$question = $this->createEntity('XF:CaptchaQuestion', [
			'question' => 'Seeder test ' . bin2hex(random_bytes(6)), 'answers' => ['yes'], 'active' => false,
		]);
		$this->questions[] = $question->captcha_question_id;
		(new TableRecorder($batch))->record($question);

		return $question->captcha_question_id;
	}

	private function cleanerThatDeadlocks(int $times): Cleaner
	{
		return new class($times) extends Cleaner
		{
			public $attempts = 0;

			private $times;

			public function __construct(int $times)
			{
				$this->times = $times;
			}

			protected function remove(Entity $entity, ?string $factory): void
			{
				$this->attempts++;
				if ($this->attempts <= $this->times)
				{
					throw new DeadlockException('MySQL query error [1213]: Deadlock found when trying to get lock; try restarting transaction');
				}

				parent::remove($entity, $factory);
			}
		};
	}
}
