<?php namespace Tests\Feature;

use Hampel\Seeder\Context;
use Hampel\Seeder\Record\RoundTrip;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The round trip itself, for real, inside a transaction. The job queue is faked, so this proves
 * the comparison and the entity-level clean-up; the queue-level half is seeder:validate's to prove
 * on a real forum.
 */
class RoundTripTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->useTemporaryCodeCache();
		$this->fakesMail();
		$this->fakesJobs();
	}

	public function test_a_seeded_forum_is_made_removed_and_leaves_no_table_changed()
	{
		$previous = Context::current();

		$result = (new RoundTrip())->run();

		$this->assertNull($result['seed_failure']);
		$this->assertSame([], $result['failed']);
		$this->assertSame(9, $result['created']['XF:Thread']);
		$this->assertSame(5, $result['created']['XF:Warning']);
		$this->assertSame(2, $result['created']['XF:WarningDefinition']);
		$this->assertSame(1, $result['created']['XF:ThreadPrefix']);
		$this->assertSame(array_sum($result['created']), $result['removed']);
		$this->assertSame([], $result['leaks']);
		$this->assertSame([], $result['other']);
		$this->assertGreaterThan(200, $result['tables']);
		$this->assertDatabaseCount(TableRecorder::TABLE, 0, ['batch' => $result['batch']]);
		$this->assertSame($previous, Context::current(), 'the run put back the context it replaced');
		$this->assertNoMailSent();
	}

	/**
	 * A check that cannot fail is not evidence: leave something behind and it has to say so.
	 *
	 * No table is created here to stand for another add-on's. DDL commits, which would end this
	 * test's transaction and make everything after it permanent; compare() is tested for that.
	 */
	public function test_a_row_left_behind_is_reported_as_a_leak_and_new_error_log_rows_are_listed()
	{
		$roundTrip = new class extends RoundTrip
		{
			protected function clean(string $batch): array
			{
				$result = parent::clean($batch);

				\XF::db()->insert('xf_captcha_question', ['question' => 'Seeder test leak', 'answers' => '[]']);
				\XF::db()->insert('xf_error_log', [
					'exception_date' => time(), 'exception_type' => 'Test', 'message' => 'Seeder test entry',
					'filename' => '', 'line' => 0, 'trace_string' => '', 'request_state' => '',
				]);

				return $result;
			}
		};

		$result = $roundTrip->run();

		$this->assertSame(['xf_captcha_question'], array_keys($result['leaks']));
		$this->assertSame(1, $result['leaks']['xf_captcha_question'][1] - $result['leaks']['xf_captcha_question'][0]);
		$this->assertSame(['Seeder test entry'], $result['errors']);
	}

	public function test_differences_are_sorted_into_leaks_other_add_ons_and_tables_that_move_anyway()
	{
		$before = ['xf_post' => 10, 'xf_thread' => 5, 'xf_session' => 3, 'xf_error_log' => 7, 'xf_someone_log' => 2,
			'xf_gone' => 4, TableRecorder::TABLE => 0];
		$after = ['xf_post' => 12, 'xf_thread' => 5, 'xf_session' => 9, 'xf_error_log' => 8, 'xf_someone_log' => 6,
			'xf_new_table' => 1, TableRecorder::TABLE => 3];

		[$leaks, $other] = RoundTrip::compare($before, $after, ['xf_post', 'xf_thread', 'xf_session', 'xf_error_log']);

		$this->assertSame(['xf_post' => [10, 12], TableRecorder::TABLE => [0, 3]], $leaks);
		$this->assertSame(['xf_someone_log' => [2, 6], 'xf_new_table' => [0, 1], 'xf_gone' => [4, 0]], $other);
	}
}
