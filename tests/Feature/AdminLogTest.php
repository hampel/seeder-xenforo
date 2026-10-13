<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\AdminLogFactory;
use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Seed\AdminLogSeeder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Administrators and admin log entries, created for real inside a transaction.
 */
class AdminLogTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_user', 'xf_admin', 'xf_admin_permission_entry', 'xf_admin_log', 'xf_user_group_change',
		'xf_moderator_log', 'xf_user_alert', 'xf_change_log'];

	/** @var Context|null */
	private $previous;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->fakesMail();
		$this->fakesJobs();
		ErrorLogFactory::quietFor(ErrorLogFactory::MINIMUM_QUIET);
		$this->previous = Context::use(null);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);
		ErrorLogFactory::quietFor(ErrorLogFactory::MINIMUM_QUIET);

		parent::tearDown();
	}

	public function test_an_administrator_has_the_record_behind_the_flag_and_no_permissions()
	{
		$this->recordInto('test-admin');

		$member = UserFactory::new()->administrator()->create();

		$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'is_admin' => 1, 'is_banned' => 0]);
		$this->assertDatabaseHas('xf_admin', ['user_id' => $member->user_id, 'is_super_admin' => 0, 'extra_user_group_ids' => '']);
		$this->assertDatabaseCount('xf_admin_permission_entry', 0, ['user_id' => $member->user_id]);

		$admin = $this->app()->em()->find('XF:Admin', $member->user_id);
		$this->assertSame([], $admin->permission_cache);
		$this->assertGreaterThanOrEqual($member->register_date, $admin->last_login);

		$fresh = $this->app()->em()->find('XF:User', $member->user_id);
		$this->assertFalse($fresh->hasAdminPermission('user'));
		$this->assertContains('admin', UserFactory::describe($member)['has']);
	}

	public function test_an_administrator_has_the_permissions_named_and_one_the_forum_lacks_is_refused()
	{
		$this->recordInto('test-admin-permissions');

		$member = UserFactory::new()->administrator('user', 'option')->create();

		$this->assertDatabaseCount('xf_admin_permission_entry', 2, ['user_id' => $member->user_id]);
		$fresh = $this->app()->em()->find('XF:User', $member->user_id);
		$this->assertTrue($fresh->hasAdminPermission('user'));
		$this->assertTrue($fresh->hasAdminPermission('option'));
		$this->assertFalse($fresh->hasAdminPermission('style'));
		$this->assertFalse($fresh->is_super_admin);

		try
		{
			UserFactory::new()->administrator('nonsense')->create();
			$this->fail('a permission that does not exist was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString("no administrator permission 'nonsense'", $e->getMessage());
		}

		$this->expectExceptionMessage('cannot be banned');
		UserFactory::new()->administrator()->banned()->create();
	}

	public function test_cleaning_an_administrator_leaves_no_record_permission_or_member()
	{
		$before = $this->counts();
		$this->recordInto('test-admin-clean');

		UserFactory::new()->administrator('user')->count(2)->create();
		$this->assertSame($before['xf_admin'] + 2, $this->counts()['xf_admin']);

		$result = (new Cleaner())->clean('test-admin-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->counts());
	}

	public function test_an_entry_reads_as_one_xenforo_wrote()
	{
		$this->recordInto('test-adminlog');

		$entry = AdminLogFactory::new()->create();
		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_admin_log WHERE admin_log_id = ?', $entry->admin_log_id);

		$this->assertDatabaseHas('xf_admin', ['user_id' => $row['user_id']]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $row['user_id'], 'is_admin' => 1]);

		// a route path, never a full address
		$this->assertDoesNotMatchRegularExpression('#^(/|https?:|admin\.php)#', $row['request_url']);
		$data = json_decode($row['request_data'], true);
		$this->assertIsArray($data);
		$this->assertSame('********', $data['_xfToken'], 'masked, as XenForo masks it');

		$this->assertSame(4, strlen($row['ip_address']));
		$this->assertMatchesRegularExpression('/^198\.1[89]\./', \XF\Util\Ip::binaryToString($row['ip_address']));

		$admin = $this->app()->em()->find('XF:User', $row['user_id']);
		$this->assertGreaterThanOrEqual($admin->register_date, $row['request_date']);
		$this->assertLessThanOrEqual(time() - ErrorLogFactory::MINIMUM_QUIET, $row['request_date']);

		// and XenForo's own log page reads it back
		$found = $this->app()->em()->find('XF:AdminLog', $entry->admin_log_id);
		$this->assertSame($admin->username, $found->User->username);
		$this->assertSame($row['request_url'], $found->request_url);

		// the control panel's front page, which XenForo logs with no path at all
		$front = AdminLogFactory::new()->action('', ['_xfToken' => '********'])->create();
		$this->assertDatabaseHas('xf_admin_log', ['admin_log_id' => $front->admin_log_id, 'request_url' => '']);
		$this->assertSame('', $front->request_url);
	}

	public function test_no_entry_is_dated_inside_the_quiet_period_whatever_was_asked_for()
	{
		// a span so short that every date the timeline gives is inside the quiet period
		$this->recordInto('test-adminlog-quiet', '30h');
		$admin = AdminLogFactory::administrator()->create();
		$this->assertLessThan(time() - ErrorLogFactory::MINIMUM_QUIET, $admin->register_date);

		$entries = AdminLogFactory::new()->by($admin)->count(60)->create();
		$asked = AdminLogFactory::new()->by($admin)->create(['request_date' => time() - 60]);
		$future = AdminLogFactory::new()->by($admin)->create(['request_date' => time() + 86400]);

		$latest = time() - ErrorLogFactory::MINIMUM_QUIET;
		foreach (array_merge($entries->toArray(), [$asked, $future]) AS $entry)
		{
			$this->assertLessThanOrEqual($latest, (int) $this->app()->db()->fetchOne(
				'SELECT request_date FROM xf_admin_log WHERE admin_log_id = ?', $entry->admin_log_id
			));
		}

		ErrorLogFactory::quietFor(6 * 3600);
		$later = AdminLogFactory::new()->by($admin)->create(['request_date' => time() - 3 * 3600]);
		$this->assertLessThanOrEqual(time() - 6 * 3600, $later->request_date);
	}

	public function test_a_log_has_the_same_thing_done_again_by_the_same_administrator_and_things_done_once()
	{
		$this->recordInto('test-adminlog-repeats');
		$admins = AdminLogFactory::administrator()->count(3)->create();

		$entries = AdminLogFactory::new()->recycle($admins)->count(150)->create();
		$ids = implode(',', $entries->pluckNamed('admin_log_id'));
		$db = $this->app()->db();

		$this->assertSame(3, (int) $db->fetchOne("SELECT COUNT(DISTINCT user_id) FROM xf_admin_log WHERE admin_log_id IN ({$ids})"));

		// alike in the four things a reader collapses on
		$distinct = (int) $db->fetchOne(
			"SELECT COUNT(*) FROM (SELECT 1 FROM xf_admin_log WHERE admin_log_id IN ({$ids})
				GROUP BY user_id, ip_address, request_url, request_data) AS grouped"
		);
		$this->assertLessThan(110, $distinct, 'repeats');
		$this->assertGreaterThan(20, $distinct, 'and not only repeats');
		$this->assertGreaterThan(8, (int) $db->fetchOne("SELECT COUNT(DISTINCT request_url) FROM xf_admin_log WHERE admin_log_id IN ({$ids})"));

		// markup in what was posted, for whatever shows it to escape
		$this->assertGreaterThan(0, (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_admin_log WHERE admin_log_id IN ({$ids}) AND request_data LIKE '%<%' AND request_data LIKE '%>%'"
		));
		$this->assertSame(0, (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_admin_log WHERE admin_log_id IN ({$ids}) AND request_data LIKE '%password%' AND request_data NOT LIKE '%\"password\":\"********\"%'"
		), 'no password is ever logged');
		$this->assertSame(0, (int) $db->fetchOne(
			"SELECT COUNT(*) FROM xf_admin_log WHERE admin_log_id IN ({$ids}) AND request_data LIKE '%http%' AND request_data NOT LIKE '%example.com%'"
		));
	}

	public function test_an_entry_can_be_by_a_named_administrator_for_a_named_action_from_a_named_address()
	{
		$this->recordInto('test-adminlog-states');
		$admin = UserFactory::new()->administrator()->create();

		$entry = AdminLogFactory::new()->by($admin)->action('options/update', ['options' => ['boardTitle' => 'x']])->from('203.0.113.9')->create();

		$this->assertDatabaseHas('xf_admin_log', [
			'admin_log_id' => $entry->admin_log_id, 'user_id' => $admin->user_id, 'request_url' => 'options/update',
			'request_data' => '{"options":{"boardTitle":"x"}}', 'ip_address' => \XF\Util\Ip::stringToBinary('203.0.113.9', false),
		]);
		$this->assertSame(
			['user_id' => $admin->user_id, 'request_url' => 'options/update', 'request_date' => $entry->request_date],
			AdminLogFactory::describe($entry)
		);

		$this->expectExceptionMessage('is not an address');
		AdminLogFactory::new()->from('nowhere');
	}

	/**
	 * XenForo's own structure calls this entity XF:Adminlog, a name no later process can load.
	 * The entity manager goes by the name it was asked for, so the factory's is what is recorded.
	 */
	public function test_entries_are_recorded_under_a_name_that_can_be_loaded_and_cleaning_removes_them()
	{
		$before = $this->counts();
		$recorder = $this->recordInto('test-adminlog-clean');
		$admin = UserFactory::new()->administrator()->create();

		AdminLogFactory::new()->by($admin)->count(5)->create();

		$this->assertSame(5, $recorder->counts()['XF:AdminLog']);
		$this->assertDatabaseCount('xf_hampel_seeder_record', 5, ['batch' => 'test-adminlog-clean', 'entity' => 'XF:AdminLog']);
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_hampel_seeder_record WHERE batch = 'test-adminlog-clean' AND entity = BINARY 'XF:Adminlog'"
		));

		$result = (new Cleaner())->clean('test-adminlog-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame(5, $result['removed']['XF:AdminLog']);
		$this->assertSame($before, $this->counts());
	}

	public function test_the_seeder_makes_its_administrators_and_says_so_and_names_who_will_report()
	{
		$this->withActiveAddOns();
		$this->assertSame([], AdminLogSeeder::activeReaders());
		$this->assertCount(1, (new AdminLogSeeder())->notices());

		$this->withActiveAddOns(['Hampel/LogDigest']);
		$this->assertSame(['Hampel/LogDigest'], array_keys(AdminLogSeeder::activeReaders()));

		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'AdminLog', '--batch' => 'test-adminlog-seeder', '--seed' => '7', '--param' => ['entries=40', 'admins=2']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);
		$summary = $tester->getErrorOutput();

		$this->assertSame(0, $code, $summary);
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(40, $created['XF:AdminLog']);
		$this->assertCount(2, $created['XF:User']);
		$this->assertSame([], array_diff(array_column($created['XF:AdminLog'], 'user_id'), array_column($created['XF:User'], 'id')));

		$this->assertStringContainsString('Creates 2 administrators who can sign in to the admin control panel', $summary);
		$this->assertStringContainsString('Hampel/LogDigest is active: seeded entries dated inside its window', $summary);
		$this->assertLessThan(strpos($summary, 'created  XF:AdminLog'), strpos($summary, 'is active'), 'said before anything is created');
	}

	public function test_the_user_seeder_makes_administrators_when_asked()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'User', '--batch' => 'test-user-admins', '--param' => ['users=1', 'admins=2']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$members = json_decode($tester->getDisplay(), true)['created']['XF:User'];
		$this->assertCount(3, $members);
		$ids = implode(',', array_column($members, 'id'));
		$this->assertSame(2, (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_admin WHERE user_id IN ({$ids})"));
	}

	private function recordInto(string $batch, string $span = '90d'): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span($span), 'password'));

		return $recorder;
	}

	private function counts(): array
	{
		$counts = [];
		foreach (self::TABLES AS $table)
		{
			$counts[$table] = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
		}

		return $counts;
	}
}
