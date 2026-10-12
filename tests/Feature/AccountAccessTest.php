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
		'xf_approval_queue', 'xf_user_authenticate', 'xf_user_profile', 'xf_user_option', 'xf_user_privacy',
		'xf_user_ban', 'xf_user_group_change', 'xf_user_group_relation',
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

	public function test_a_banned_member_has_xenforos_ban_record_and_the_flag_it_sets()
	{
		$recorder = $this->recordInto('test-banned');
		$db = $this->app()->db();
		$bans = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_ban');
		$until = time() + 7 * 86400;

		$permanent = UserFactory::new()->banned()->count(2)->create();
		$temporary = UserFactory::new()->banned($until)->create();
		$ordinary = UserFactory::new()->create();

		$by = [];
		foreach ($permanent AS $member)
		{
			$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'is_banned' => 1, 'user_state' => 'valid']);
			$this->assertDatabaseHas('xf_user_ban', ['user_id' => $member->user_id, 'end_date' => 0, 'triggered' => 0]);
			$ban = $db->fetchRow('SELECT * FROM xf_user_ban WHERE user_id = ?', $member->user_id);
			$this->assertGreaterThanOrEqual($member->register_date, $ban['ban_date'], 'banned after they joined');
			$this->assertLessThanOrEqual(time(), $ban['ban_date']);
			$this->assertNotSame('', $ban['user_reason']);
			$by[] = $ban['ban_user_id'];
		}
		$this->assertDatabaseHas('xf_user_ban', ['user_id' => $temporary->user_id, 'end_date' => $until]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $temporary->user_id, 'is_banned' => 1]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $ordinary->user_id, 'is_banned' => 0]);

		$this->assertCount(1, array_unique($by), 'one moderator banned both, not one each');
		$this->assertDatabaseHas('xf_user', ['user_id' => $by[0], 'is_moderator' => 1, 'is_banned' => 0]);

		$has = array_column($recorder->created()['XF:User'], 'has', 'username');
		$this->assertSame(['banned'], $has[$temporary->username]);

		$this->assertSame([], (new Cleaner())->clean('test-banned')['failed']);
		$this->assertSame($bans, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_ban'), 'removing the member takes their ban');
	}

	public function test_a_named_member_can_be_the_one_who_banned_them()
	{
		$this->recordInto('test-banned-by');
		$moderator = UserFactory::new()->state(['is_moderator' => true, 'is_staff' => true])->create();
		$members = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		$banned = UserFactory::new()->banned(null, $moderator)->count(2)->create();

		$this->assertSame($members + 2, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
		foreach ($banned AS $member)
		{
			$this->assertDatabaseHas('xf_user_ban', ['user_id' => $member->user_id, 'ban_user_id' => $moderator->user_id]);
		}
	}

	public function test_a_permanent_ban_takes_a_moderated_member_out_of_the_queue_and_a_temporary_one_does_not()
	{
		$this->recordInto('test-banned-moderated');

		$permanent = UserFactory::new()->moderated()->banned()->create();
		$temporary = UserFactory::new()->moderated()->banned(time() + 86400)->create();

		// XenForo's doing, and written down so nobody is surprised by it
		$this->assertDatabaseHas('xf_user', ['user_id' => $permanent->user_id, 'is_banned' => 1, 'user_state' => 'valid']);
		$this->assertDatabaseCount('xf_approval_queue', 0, ['content_type' => 'user', 'content_id' => $permanent->user_id]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $temporary->user_id, 'is_banned' => 1, 'user_state' => 'moderated']);
		$this->assertDatabaseCount('xf_approval_queue', 1, ['content_type' => 'user', 'content_id' => $temporary->user_id]);
	}

	public function test_a_forum_with_a_banned_group_puts_the_member_in_it_and_cleaning_takes_them_out()
	{
		$this->recordInto('test-banned-group');
		$db = $this->app()->db();
		$changes = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_group_change');
		$relations = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_group_relation');

		// the ban entity reads the forum's option once, when its structure is first built
		$structure = $this->app()->em()->getEntityStructure('XF:UserBan');
		$was = $structure->options['ban_user_group'];
		$structure->options['ban_user_group'] = 4;
		try
		{
			$member = UserFactory::new()->banned()->create();
		}
		finally
		{
			$structure->options['ban_user_group'] = $was;
		}

		$this->assertDatabaseHas('xf_user_group_change', ['user_id' => $member->user_id, 'change_key' => 'banGroup']);
		$this->assertContains(4, array_map('intval', $this->app()->find('XF:User', $member->user_id)->secondary_group_ids));

		$this->assertSame([], (new Cleaner())->clean('test-banned-group')['failed']);
		$this->assertSame($changes, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_group_change'));
		$this->assertSame($relations, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_group_relation'));
	}

	public function test_staff_cannot_be_banned_or_bouncing_and_nobody_is_created()
	{
		$this->recordInto('test-banned-staff');
		$members = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		$refused = [];
		foreach ([
			'banned moderator' => UserFactory::new()->banned()->state(['is_moderator' => true]),
			'banned administrator' => UserFactory::new()->state(['is_admin' => true])->banned(),
			'bouncing moderator' => UserFactory::new()->bouncing()->state(['is_moderator' => true]),
			'bouncing staff' => UserFactory::new()->state(['is_staff' => true])->bouncing(),
		] AS $what => $factory)
		{
			try
			{
				$factory->create();
			}
			catch (\InvalidArgumentException $e)
			{
				$refused[] = $what;
			}
		}

		$this->assertSame(['banned moderator', 'banned administrator', 'bouncing moderator', 'bouncing staff'], $refused);
		$this->assertSame($members, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
	}

	public function test_a_ban_that_has_already_run_out_is_refused()
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('has to end in the future');

		UserFactory::new()->banned(time() - 60);
	}

	public function test_a_bouncing_member_has_the_state_xenforo_gives_one_and_is_otherwise_ordinary()
	{
		$this->recordInto('test-bouncing');

		$member = UserFactory::new()->bouncing()->create();

		$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'user_state' => 'email_bounce', 'is_banned' => 0]);
		$this->assertNotSame('', $member->email);
		$this->assertDatabaseCount('xf_approval_queue', 0, ['content_type' => 'user', 'content_id' => $member->user_id]);
	}

	public function test_the_seeder_makes_banned_and_bouncing_members_and_cleaning_leaves_nothing()
	{
		$before = $this->tableCounts();

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'User', '-p' => ['users=1', 'banned=3', 'bouncing=2'], '--batch' => 'test-banned-seeder', '--seed' => '6'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		// one ordinary, the moderator, three banned and two bouncing
		$this->assertCount(7, json_decode($tester->getDisplay(), true)['created']['XF:User']);

		$db = $this->app()->db();
		$newest = $db->fetchAllColumn('SELECT user_id FROM xf_user ORDER BY user_id DESC LIMIT 7');
		$in = $db->quote($newest);
		$this->assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user_ban WHERE end_date = 0 AND user_id IN ({$in})"));
		$this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user_ban WHERE end_date > ? AND user_id IN ({$in})", time()));
		$this->assertSame(3, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user WHERE is_banned = 1 AND user_id IN ({$in})"));
		$this->assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user WHERE user_state = 'email_bounce' AND user_id IN ({$in})"));

		$this->assertSame([], (new Cleaner())->clean('test-banned-seeder')['failed']);
		$this->assertSame($before, $this->tableCounts());
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_creating_a_member_does_not_write_the_table_xenforos_cron_rewrites()
	{
		$this->recordInto('test-member-stat');
		$before = $this->app()->db()->fetchAll('SELECT member_stat_id, cache_expiry, MD5(IFNULL(cache_results, \'\')) AS results FROM xf_member_stat ORDER BY member_stat_id');

		UserFactory::new()->create();

		$this->memberStats->shouldHaveReceived('emptyCache')->with('staff_members');
		$this->assertSame($before, $this->app()->db()->fetchAll('SELECT member_stat_id, cache_expiry, MD5(IFNULL(cache_results, \'\')) AS results FROM xf_member_stat ORDER BY member_stat_id'));
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

	public function test_a_moderated_member_is_in_the_approval_queue_at_the_date_they_registered()
	{
		$this->recordInto('test-moderated');
		$queued = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_approval_queue WHERE content_type = 'user'");

		$members = UserFactory::new()->moderated()->count(3)->create();
		$plain = UserFactory::new()->create();

		$dates = [];
		foreach ($members AS $member)
		{
			$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'user_state' => 'moderated']);
			$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'user', 'content_id' => $member->user_id, 'content_date' => $member->register_date]);
			$dates[] = $member->register_date;
		}
		$this->assertCount(3, array_unique($dates), 'each registered at a moment of their own, so the queue has an order');
		$this->assertDatabaseCount('xf_approval_queue', 0, ['content_type' => 'user', 'content_id' => $plain->user_id]);

		$this->assertSame([], (new Cleaner())->clean('test-moderated')['failed']);
		$this->assertSame($queued, (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_approval_queue WHERE content_type = 'user'"), 'removing the member takes their queue entry');
	}

	public function test_a_registration_date_given_to_a_moderated_member_is_the_date_in_the_queue()
	{
		$this->recordInto('test-moderated-date');
		$when = \XF::$time - 86400 * 400;

		$member = UserFactory::new()->moderated()->create(['register_date' => $when]);

		$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'user', 'content_id' => $member->user_id, 'content_date' => $when]);
	}

	public function test_the_seeder_makes_moderated_members_and_cleaning_empties_the_queue_of_them()
	{
		$before = $this->tableCounts();

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'User', '-p' => ['users=1', 'moderated=4'], '--batch' => 'test-moderated-seeder', '--seed' => '4'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$this->assertCount(5, json_decode($tester->getDisplay(), true)['created']['XF:User']);

		$after = $this->tableCounts();
		$this->assertSame($before['xf_user'] + 5, $after['xf_user']);
		$this->assertSame($before['xf_approval_queue'] + 4, $after['xf_approval_queue']);

		$this->assertSame([], (new Cleaner())->clean('test-moderated-seeder')['failed']);
		$this->assertSame($before, $this->tableCounts());
	}

	public function test_a_member_without_a_password_has_xenforos_no_password_scheme_and_cannot_log_in_with_one()
	{
		$recorder = $this->recordInto('test-no-password');
		$db = $this->app()->db();
		$provider = $db->fetchOne('SELECT provider_id FROM xf_connected_account_provider LIMIT 1');

		$member = UserFactory::new()->withoutPassword()->create();
		$viaProvider = UserFactory::new()->withoutPassword()->withConnectedAccount($provider)->create();
		$ordinary = UserFactory::new()->create();

		foreach ([$member, $viaProvider] AS $user)
		{
			$this->assertDatabaseHas('xf_user_authenticate', ['user_id' => $user->user_id, 'scheme_class' => 'XF:NoPassword']);
			$auth = $this->app()->find('XF:UserAuth', $user->user_id);
			$this->assertFalse($auth->getAuthenticationHandler()->hasPassword());
			$this->assertFalse($auth->authenticate('password'), 'the password every other seeded member has');
			$this->assertFalse($auth->authenticate(''));
		}
		$this->assertDatabaseHas('xf_user_connected_account', ['user_id' => $viaProvider->user_id, 'provider' => $provider]);
		$this->assertDatabaseCount('xf_user_connected_account', 0, ['user_id' => $member->user_id]);
		$this->assertTrue($this->app()->find('XF:UserAuth', $ordinary->user_id)->authenticate('password'), 'and an ordinary member still can');

		$has = array_map(function ($user) { return $user['has'] ?? []; }, $recorder->created()['XF:User']);
		$this->assertSame([['no_password'], ['no_password', 'connected'], []], $has);
	}

	public function test_a_member_without_authentication_has_no_such_record_and_is_otherwise_whole()
	{
		$recorder = $this->recordInto('test-no-auth');
		$before = $this->tableCounts();

		$member = UserFactory::new()->withoutAuthentication()->create();

		$this->assertDatabaseCount('xf_user_authenticate', 0, ['user_id' => $member->user_id]);
		foreach (['xf_user', 'xf_user_profile', 'xf_user_option', 'xf_user_privacy'] AS $table)
		{
			$this->assertDatabaseHas($table, ['user_id' => $member->user_id]);
		}
		$this->assertNull($member->Auth, 'the entity handed back agrees with the database');
		$this->app()->em()->clearEntityCache();
		$this->assertNull($this->app()->find('XF:User', $member->user_id)->Auth);
		$this->assertSame([['no_authentication']], array_column($recorder->created()['XF:User'], 'has'));

		$this->assertSame([], (new Cleaner())->clean('test-no-auth')['failed'], 'XenForo can still delete such a member');
		$this->assertSame($before, $this->tableCounts());
	}

	public function test_the_seeder_makes_both_and_cleaning_leaves_nothing()
	{
		$before = $this->tableCounts();

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'User', '-p' => ['users=1', 'passwordless=2', 'no_auth=2'], '--batch' => 'test-auth-seeder', '--seed' => '4'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$after = $this->tableCounts();
		$this->assertSame($before['xf_user'] + 5, $after['xf_user']);
		$this->assertSame($before['xf_user_authenticate'] + 3, $after['xf_user_authenticate'], 'two of the five have no record');
		$this->assertSame($before['xf_user_connected_account'] + 2, $after['xf_user_connected_account']);
		$newest = $this->app()->db()->fetchAllColumn('SELECT user_id FROM xf_user ORDER BY user_id DESC LIMIT 5');
		$this->assertSame(2, (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_user_authenticate WHERE scheme_class = 'XF:NoPassword' AND user_id IN (" . $this->app()->db()->quote($newest) . ')'));

		$this->assertSame([], (new Cleaner())->clean('test-auth-seeder')['failed']);
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
