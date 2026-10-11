<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The other ways into a member's account, and a member who has used the forum without posting,
 * created for real inside a transaction.
 */
class AccountAccessTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = [
		'xf_user', 'xf_user_connected_account', 'xf_user_tfa', 'xf_passkey', 'xf_user_remember', 'xf_user_confirmation',
		'xf_ip', 'xf_session_activity', 'xf_user_alert', 'xf_user_follow', 'xf_post', 'xf_thread', 'xf_change_log',
	];

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

	public function test_each_way_in_writes_the_rows_xenforo_keeps_for_it()
	{
		$recorder = $this->recordInto('test-access');
		$db = $this->app()->db();
		$provider = $db->fetchOne('SELECT provider_id FROM xf_connected_account_provider LIMIT 1');

		$connected = UserFactory::new()->withConnectedAccount($provider)->create();
		$this->assertDatabaseHas('xf_user_connected_account', ['user_id' => $connected->user_id, 'provider' => $provider]);
		$this->assertArrayHasKey($provider, json_decode($db->fetchOne('SELECT connected_accounts FROM xf_user_profile WHERE user_id = ?', $connected->user_id), true));

		$twoStep = UserFactory::new()->withTwoStep()->create();
		$this->assertDatabaseHas('xf_user_tfa', ['user_id' => $twoStep->user_id, 'provider_id' => 'totp']);
		$this->assertDatabaseHas('xf_user_tfa', ['user_id' => $twoStep->user_id, 'provider_id' => 'backup']);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $twoStep->user_id, 'use_tfa' => 1]);
		$this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_tfa WHERE user_id = ? AND last_used_date > ?', [$twoStep->user_id, time() - 60]), 'not dated now');

		$passkey = UserFactory::new()->withPasskey()->create();
		$this->assertDatabaseHas('xf_passkey', ['user_id' => $passkey->user_id, 'name' => 'Seeded passkey']);
		$this->assertDatabaseHas('xf_user_tfa', ['user_id' => $passkey->user_id, 'provider_id' => 'passkey'], 'listed among their two-step methods');

		$remembered = UserFactory::new()->withRememberKey()->create();
		$row = $db->fetchRow('SELECT * FROM xf_user_remember WHERE user_id = ?', $remembered->user_id);
		$this->assertSame(32, strlen($row['remember_key']));
		$this->assertGreaterThan(time(), $row['expiry_date']);
		$this->assertLessThan(time() - 60, $row['start_date']);

		$pending = UserFactory::new()->withConfirmationPending()->create();
		$this->assertSame('email_confirm', $pending->user_state);
		$this->assertDatabaseHas('xf_user_confirmation', ['user_id' => $pending->user_id, 'confirmation_type' => 'email', 'confirmation_date' => $pending->register_date]);
		$this->assertDatabaseCount('xf_user_confirmation', 0, ['user_id' => UserFactory::new()->unconfirmed()->create()->user_id], 'unconfirmed() alone writes no row');

		$this->assertNoMailSent();
		$has = array_column($recorder->created()['XF:User'], 'has');
		$this->assertSame([['connected'], ['two_step'], ['passkey'], ['remember'], ['confirmation']], array_slice($has, 0, 5));
	}

	public function test_a_member_active_without_content_has_logged_in_been_alerted_follows_and_is_followed()
	{
		$this->recordInto('test-access-active');
		$db = $this->app()->db();
		$users = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user');

		$member = UserFactory::new()->activeWithoutContent()->create();
		$id = $member->user_id;

		$this->assertSame($users + 2, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'), 'one companion for the alert and both follows');
		$login = $db->fetchRow("SELECT * FROM xf_ip WHERE user_id = ? AND content_type = 'user' AND action = 'login'", $id);
		$this->assertSame($id, (int) $login['content_id']);
		$this->assertStringStartsWith('198.18.', \XF\Util\Ip::binaryToString($login['ip']));
		$this->assertSame(min($member->last_activity, time()), (int) $login['log_date']);
		$this->assertDatabaseHas('xf_session_activity', ['user_id' => $id, 'unique_key' => (string) $id, 'view_state' => 'valid']);
		$this->assertDatabaseHas('xf_user_alert', ['alerted_user_id' => $id, 'action' => 'following', 'read_date' => 0]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $id, 'alerts_unread' => 1, 'alerts_unviewed' => 1, 'message_count' => 0]);
		$other = (int) $db->fetchOne('SELECT follow_user_id FROM xf_user_follow WHERE user_id = ?', $id);
		$this->assertDatabaseHas('xf_user_follow', ['user_id' => $other, 'follow_user_id' => $id]);
		$this->assertSame((string) $other, $db->fetchOne('SELECT following FROM xf_user_profile WHERE user_id = ?', $id));
		$this->assertSame(0, (int) $db->fetchOne('SELECT (SELECT COUNT(*) FROM xf_post WHERE user_id IN (?, ?)) + (SELECT COUNT(*) FROM xf_thread WHERE user_id IN (?, ?))', [$id, $other, $id, $other]));
		// at the member's last activity, or when the later of the two joined - never after now
		$this->assertSame(0, (int) $db->fetchOne(
			'SELECT COUNT(*) FROM xf_user_follow f JOIN xf_user a ON a.user_id = f.user_id JOIN xf_user b ON b.user_id = f.follow_user_id
				WHERE f.user_id IN (?, ?) AND (f.follow_date > ? OR f.follow_date < GREATEST(a.register_date, b.register_date))', [$id, $other, time()]
		));
		$this->assertNoMailSent();
	}

	public function test_a_named_member_can_be_the_other_party_and_nobody_is_created()
	{
		$this->recordInto('test-access-named');
		$db = $this->app()->db();
		$other = UserFactory::new()->create(['register_date' => 1600000000]);
		$users = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user');

		$member = UserFactory::new()->following($other)->followedBy($other)->withAlert($other)->create();

		$this->assertSame($users + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'));
		$this->assertDatabaseHas('xf_user_follow', ['user_id' => $member->user_id, 'follow_user_id' => $other->user_id]);
		$this->assertDatabaseHas('xf_user_follow', ['user_id' => $other->user_id, 'follow_user_id' => $member->user_id]);
		$this->assertDatabaseHas('xf_user_alert', ['alerted_user_id' => $member->user_id, 'user_id' => $other->user_id]);
	}

	public function test_an_unknown_provider_is_refused_before_anyone_is_created()
	{
		$this->recordInto('test-access-bad');
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		try
		{
			UserFactory::new()->withConnectedAccount('seedertestnothere')->create();
			$this->fail('an unknown provider was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString("no connected account provider 'seedertestnothere'", $e->getMessage());
		}

		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
	}

	public function test_a_member_whose_extras_fail_is_not_left_behind_unrecorded()
	{
		$this->recordInto('test-access-fails');
		$db = $this->app()->db();
		$users = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user');
		$other = UserFactory::new()->create();

		try
		{
			// the second follow of the same member is refused by XenForo, after the member is saved
			UserFactory::new()->following($other)->following($other)->create();
			$this->fail('following the same member twice was accepted');
		}
		catch (\Throwable $e)
		{
			$this->assertStringContainsStringIgnoringCase('follow', $e->getMessage());
		}

		$this->assertSame($users + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'), 'only the other member remains');
		$this->assertDatabaseCount('xf_user_follow', 0, ['follow_user_id' => $other->user_id]);
	}

	public function test_the_seeder_makes_them_and_cleaning_leaves_nothing()
	{
		$db = $this->app()->db();
		$before = $this->tableCounts();

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'User', '-p' => ['users=2', 'access=5', 'active=2'], '--batch' => 'test-access-seeder', '--seed' => '4'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created']['XF:User'];
		$this->assertCount(11, $created, 'two plain, five with a way in, two active and a companion for each');
		$has = array_values(array_filter(array_map(function ($user) { return isset($user['has']) ? implode(',', $user['has']) : null; }, $created)));
		sort($has);
		$this->assertSame(['confirmation', 'connected', 'login,alert,following,followed', 'login,alert,following,followed', 'passkey', 'remember', 'two_step'], $has);

		$this->assertSame([], (new Cleaner())->clean('test-access-seeder')['failed']);
		$this->assertSame($before, $this->tableCounts());
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}

	private function tableCounts(): array
	{
		$counts = [];
		foreach (self::TABLES AS $table)
		{
			$counts[$table] = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
		}

		return $counts;
	}
}
