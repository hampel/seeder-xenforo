<?php namespace Tests\Feature;

use Hampel\Seeder\Context;
use Hampel\Seeder\UnbatchedRecorder;
use Tests\TestCase;

/**
 * A factory used outside seeder:run puts what it makes in no batch. The default context says so,
 * once, unless it is inside a transaction - which is a test that will roll its work back.
 *
 * Nothing here writes: the recorder is handed an entity that already exists, and the error log is
 * faked.
 */
class UnbatchedTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->fakesErrors();
	}

	public function test_the_default_context_warns()
	{
		$previous = Context::use(null);

		try
		{
			$this->assertInstanceOf(UnbatchedRecorder::class, Context::current()->recorder);
		}
		finally
		{
			Context::use($previous);
		}
	}

	public function test_outside_a_transaction_it_says_once_what_was_made_and_how_to_record_it()
	{
		$out = fopen('php://memory', 'w+');
		$recorder = new UnbatchedRecorder($out);
		$member = $this->app()->em()->find('XF:User', 1);

		$recorder->record($member, 'Factory');
		$recorder->record($member, 'Factory');
		$recorder->recordRows('XF:SessionActivity', [['user_id' => 0, 'unique_key' => 'x']]);

		$this->assertExceptionLoggedTimes(\ErrorException::class, 1);
		$this->assertErrorLogged(
			'Seeder: XF:User 1 was created outside seeder:run, so it is in no batch and seeder:clean cannot remove it - '
			. 'nor anything else this process creates. Put the factory calls in a seeder and run it with seeder:run.'
		);

		rewind($out);
		$said = stream_get_contents($out);
		$this->assertSame(1, substr_count($said, "\n"), 'said once');
		$this->assertStringStartsWith('Seeder: XF:User 1 was created outside seeder:run', $said);
	}

	public function test_bulk_rows_are_named_by_count()
	{
		$out = fopen('php://memory', 'w+');
		(new UnbatchedRecorder($out))->recordRows('XF:SessionActivity', [['user_id' => 0, 'unique_key' => 'a'], ['user_id' => 0, 'unique_key' => 'b']]);

		rewind($out);
		$this->assertStringStartsWith('Seeder: 2 XF:SessionActivity rows were created', stream_get_contents($out));
	}

	public function test_inside_a_transaction_it_says_nothing()
	{
		$out = fopen('php://memory', 'w+');
		$recorder = new UnbatchedRecorder($out);
		$db = $this->app()->db();

		$db->beginTransaction();
		try
		{
			$recorder->record($this->app()->em()->find('XF:User', 1));
		}
		finally
		{
			$db->rollback();
		}

		$this->assertSame([], $this->getExceptions());
		rewind($out);
		$this->assertSame('', stream_get_contents($out));
	}
}
