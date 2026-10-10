<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ApiKeyFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Repository\RecordRepository;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * API keys, created for real inside a transaction.
 */
class ApiKeyTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var Context|null */
	private $previous;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->fakesMail();
		$this->fakesJobs();
		$this->previous = Context::use(null);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_a_key_acts_as_a_member_by_default_and_its_value_is_reported()
	{
		$recorder = $this->recordInto('test-key');

		$key = ApiKeyFactory::new()->create();

		$this->assertDatabaseHas('xf_api_key', [
			'api_key_id' => $key->api_key_id, 'is_super_user' => 0, 'allow_all_scopes' => 1, 'active' => 1,
		]);
		$this->assertGreaterThan(0, $key->user_id);
		$this->assertSame('user', $key->key_type);
		$this->assertDatabaseHas('xf_user', ['user_id' => $key->user_id]);
		$this->assertSame(32, strlen($key->api_key));
		$this->assertTrue($key->hasScope('thread:write'));

		$member = $this->app()->em()->find('XF:User', $key->user_id);
		$this->assertGreaterThanOrEqual($member->register_date, $key->creation_date, 'made after the member was');
		$this->assertLessThanOrEqual(time(), $key->creation_date);

		$described = $recorder->created()['XF:ApiKey'][0];
		$this->assertSame(['id', 'title', 'type', 'user_id', 'active', 'api_key'], array_keys($described));
		$this->assertSame($key->api_key, $described['api_key']);
	}

	/**
	 * XenForo's key manager emails every super administrator about a new key.
	 */
	public function test_making_a_key_sends_no_mail()
	{
		$this->recordInto('test-key-quiet');

		ApiKeyFactory::new()->superUser()->create();
		ApiKeyFactory::new()->create();

		$this->assertNoMailSent();
	}

	public function test_for_names_a_member_even_when_others_are_recycled()
	{
		$this->recordInto('test-key-for');
		$members = UserFactory::new()->count(3)->create();
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		$key = ApiKeyFactory::new()->recycle($members)->for($members[1])->create();
		$fromPool = ApiKeyFactory::new()->recycle($members)->create();

		$this->assertSame($members[1]->user_id, $key->user_id);
		$this->assertContains($fromPool->user_id, array_values($members->pluckNamed('user_id')));
		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'), 'no member was made');
	}

	public function test_super_user_guest_and_inactive_keys()
	{
		$this->recordInto('test-key-kinds');
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		$super = ApiKeyFactory::new()->superUser()->create();
		$guest = ApiKeyFactory::new()->guest()->create();
		$off = ApiKeyFactory::new()->guest()->inactive()->create();

		$this->assertSame(['super', 'guest', 'guest'], [$super->key_type, $guest->key_type, $off->key_type]);
		$this->assertDatabaseHas('xf_api_key', ['api_key_id' => $super->api_key_id, 'is_super_user' => 1, 'user_id' => 0]);
		$this->assertDatabaseHas('xf_api_key', ['api_key_id' => $off->api_key_id, 'active' => 0]);
		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'), 'neither kind needs a member');
	}

	public function test_scopes_limit_the_key_and_are_written_as_rows_that_go_when_it_is_cleaned()
	{
		$this->recordInto('test-key-scopes');

		$key = ApiKeyFactory::new()->guest()->scopes(['thread:read', 'user:read'])->create();
		$id = $key->api_key_id;

		$this->assertDatabaseHas('xf_api_key', ['api_key_id' => $id, 'allow_all_scopes' => 0]);
		$this->assertDatabaseCount('xf_api_key_scope', 2, ['api_key_id' => $id]);
		$this->assertDatabaseHas('xf_api_key_scope', ['api_key_id' => $id, 'api_scope_id' => 'thread:read']);

		$fresh = $this->app()->em()->find('XF:ApiKey', $id);
		$this->assertTrue($fresh->hasScope('thread:read'));
		$this->assertFalse($fresh->hasScope('thread:write'));

		$result = (new Cleaner())->clean('test-key-scopes');

		$this->assertSame(['XF:ApiKey' => 1], $result['removed']);
		$this->assertDatabaseMissing('xf_api_key', ['api_key_id' => $id]);
		$this->assertDatabaseCount('xf_api_key_scope', 0, ['api_key_id' => $id]);
	}

	public function test_a_scope_the_forum_does_not_have_is_refused()
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('no API scope named seedertest:nothing');

		ApiKeyFactory::new()->guest()->scopes(['thread:read', 'seedertest:nothing'])->make();
	}

	public function test_a_used_key_was_last_used_after_it_was_made()
	{
		$this->recordInto('test-key-used');

		$used = ApiKeyFactory::new()->guest()->used()->create();
		$unused = ApiKeyFactory::new()->guest()->create();

		$this->assertGreaterThanOrEqual($used->creation_date, $used->last_use_date);
		$this->assertSame(0, $unused->last_use_date);
	}

	public function test_the_seeder_makes_each_kind_asked_for()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'ApiKey', '--batch' => 'test-key-seeder', '--param' => ['user_keys=2', 'guest_keys=1', 'super_keys=1', 'inactive=1']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertSame(['user' => 3, 'guest' => 1, 'super' => 1], array_count_values(array_column($created['XF:ApiKey'], 'type')));
		$this->assertCount(3, $created['XF:User']);
		$this->assertSame(1, count(array_filter(array_column($created['XF:ApiKey'], 'active'), function ($active) { return !$active; })));
		$this->assertNoMailSent();
	}

	public function test_a_batchs_records_can_be_listed_whole_or_by_kind()
	{
		$this->recordInto('test-records');
		$key = ApiKeyFactory::new()->create();
		$repo = $this->app()->repository(RecordRepository::class);

		$all = $repo->getRecords('test-records');
		$keys = $repo->getRecords('test-records', 'XF:ApiKey');

		$this->assertSame(['XF:User', 'XF:ApiKey'], array_column($all, 'entity'), 'in the order they were created');
		$this->assertSame([['entity' => 'XF:ApiKey', 'id' => (string) $key->api_key_id, 'factory' => ApiKeyFactory::class]], $keys);
		$this->assertSame([], $repo->getRecords('test-no-such-batch'));
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}
}
