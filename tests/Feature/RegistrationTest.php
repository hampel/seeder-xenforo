<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\IpHistory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fixtures\RegistrationProbe;
use Tests\Fixtures\RegistrationsSeen;
use Tests\TestCase;
use XF\Service\User\RegistrationService;
use XF\Util\Ip;

/**
 * Members registered through XenForo's registration service, and the request it is made on,
 * created for real inside a transaction.
 */
class RegistrationTest extends TestCase
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
		RegistrationsSeen::$seen = [];
		RegistrationsSeen::$fail = false;

		$this->setOption('registrationSetup', ['emailConfirmation' => false, 'moderation' => false, 'requireDob' => false, 'requireLocation' => false]
			+ $this->app()->options()->registrationSetup);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_a_registered_member_goes_through_the_service_on_the_request_given_and_nobody_hears_of_it()
	{
		// as another add-on's extension of the service would be. A class can be extended once in a
		// process, so this is the one test that does it
		$this->app()->extension()->addClassExtension(RegistrationService::class, RegistrationProbe::class);

		$this->recordInto('test-registered');
		$db = $this->app()->db();
		// queued as a forum queues it, so a queued mail would be a job
		$this->setConfig('enableMailQueue', true);
		$this->app()->container()->decache('mailer');
		$this->setOption('registrationWelcome', ['emailEnabled' => true, 'messageEnabled' => true, 'messageParticipants' => [1]]
			+ $this->app()->options()->registrationWelcome);
		$this->setOption('enableTrophies', true);
		// one the member would be awarded at once: written as a row, since a trophy's title is a phrase
		$db->insert('xf_trophy', ['trophy_points' => 1, 'user_criteria' => json_encode([['rule' => 'registered_days', 'data' => ['days' => 1]]])]);
		$welcome = $this->app()->options()->registrationWelcome;
		$request = $this->app()->request();
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');
		$conversations = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_conversation_master');

		$member = UserFactory::new()
			->registered(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Probe/1.0'])
			->create(['register_date' => 1700000000, 'last_activity' => 1700000500]);

		$this->assertSame([[
			'user_id' => $member->user_id, 'ip' => '203.0.113.9', 'user_agent' => 'Probe/1.0', 'method' => 'post', 'time' => 1700000000,
		]], RegistrationsSeen::$seen, 'the extension ran once, on the request given, at the registration date');

		$this->assertSame('valid', $member->user_state);
		$this->assertSame(1700000000, $member->register_date);
		$this->assertSame(1700000000, $member->Profile->password_date);
		$this->assertTrue($member->Auth->authenticate('password'), 'the run\'s password');
		$this->assertDatabaseHas('xf_ip', [
			'user_id' => $member->user_id, 'content_type' => 'user', 'action' => 'register', 'ip' => Ip::stringToBinary('203.0.113.9'), 'log_date' => 1700000000,
		]);
		$this->assertSame('203.0.113.9', IpHistory::addressesFor(Context::current(), $member->user_id)[0], 'the address is the member\'s own');

		// the welcome mail and message, trophies, the mail the extension sent and queued
		$this->assertNoMailSent();
		$this->assertJobNotQueued(\XF\Job\MailSend::class);
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'));
		$this->assertSame($conversations, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_conversation_master'));
		$this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_trophy WHERE user_id = ?', $member->user_id));
		$this->assertFalse($member->getBehavior('XF:Webhook')->getOption('enabled'));

		// and everything put back
		$this->assertSame($welcome, $this->app()->options()->registrationWelcome);
		$this->assertTrue($this->app()->options()->enableTrophies);
		$this->assertSame($request, $this->app()->request());
		$this->app()->mailer()->newMail()->setTo('after@example.com')->setContent('After', '<p>After</p>')->send();
		$this->assertMailSent(1); // mail goes out again once the registration is done

		// an add-on that fails after the member is saved leaves no member behind
		RegistrationsSeen::$fail = true;
		$users = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user');
		try
		{
			UserFactory::new()->registered()->create();
			$this->fail('the add-on\'s failure was swallowed');
		}
		catch (\RuntimeException $e)
		{
			$this->assertSame('the probe failed', $e->getMessage());
		}
		$this->assertSame($users, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'));
		$this->assertSame($welcome, $this->app()->options()->registrationWelcome);
	}

	public function test_the_forums_registration_setup_decides_the_state_unless_one_is_asked_for()
	{
		$this->recordInto('test-registered-state');
		$setup = $this->app()->options()->registrationSetup;

		$this->setOption('registrationSetup', ['emailConfirmation' => true] + $setup);
		$confirming = UserFactory::new()->registered()->create();
		$this->assertSame('email_confirm', $confirming->user_state);
		$this->assertDatabaseHas('xf_user_confirmation', ['user_id' => $confirming->user_id, 'confirmation_type' => 'email']);
		$this->assertNoMailSent();

		$this->setOption('registrationSetup', ['moderation' => true] + $setup);
		$queued = UserFactory::new()->registered()->create();
		$this->assertSame('moderated', $queued->user_state);
		$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'user', 'content_id' => $queued->user_id]);

		$this->setOption('registrationSetup', $setup);
		$this->assertSame('moderated', UserFactory::new()->registered()->moderated()->create()->user_state);

		// valid asked for is valid whatever the setup, the last state asked for wins, and the
		// setup is put back
		$strict = ['emailConfirmation' => true, 'moderation' => true] + $setup;
		$this->setOption('registrationSetup', $strict);
		$valid = UserFactory::new()->registered()->valid()->create();
		$this->assertSame('valid', $valid->user_state);
		$this->assertDatabaseMissing('xf_user_confirmation', ['user_id' => $valid->user_id]);
		$this->assertDatabaseMissing('xf_approval_queue', ['content_type' => 'user', 'content_id' => $valid->user_id]);
		$this->assertSame('valid', UserFactory::new()->registered()->moderated()->valid()->create()->user_state);
		$this->assertSame('moderated', UserFactory::new()->registered()->valid()->moderated()->create()->user_state);
		$this->assertSame($strict, $this->app()->options()->registrationSetup);
		$this->assertNoMailSent();

		// a pending confirmation asked for as well is the one the registration left
		$this->setOption('registrationSetup', ['emailConfirmation' => true] + $setup);
		$pending = UserFactory::new()->registered()->withConfirmationPending()->create();
		$this->assertSame(1, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user_confirmation WHERE user_id = ?', $pending->user_id));
		$this->assertNoMailSent();
	}

	public function test_a_forum_that_asks_for_a_date_of_birth_and_a_location_is_given_them()
	{
		$this->recordInto('test-registered-required');
		$this->setOption('registrationSetup', ['requireDob' => true, 'minimumAge' => 21, 'requireLocation' => true]
			+ $this->app()->options()->registrationSetup);

		$member = UserFactory::new()->registered()->create();

		$this->assertGreaterThanOrEqual(21, $member->Profile->getAge(true));
		$this->assertNotSame('', (string) $member->Profile->location);
	}

	public function test_an_ip_log_has_one_registration_from_the_address_registered_from()
	{
		$this->recordInto('test-registered-ips');

		$member = UserFactory::new()->registered(['REMOTE_ADDR' => '198.51.100.40'])->withIpLog(4)->create();

		$rows = $this->app()->db()->fetchAll("SELECT action, ip FROM xf_ip WHERE user_id = ? AND content_type = 'user' AND action = 'register'", $member->user_id);
		$this->assertSame([['action' => 'register', 'ip' => Ip::stringToBinary('198.51.100.40')]], $rows);
		$this->assertSame('198.51.100.40', IpHistory::addressesFor(Context::current(), $member->user_id)[0]);
	}

	public function test_a_registration_xenforo_refuses_says_why_and_leaves_nothing()
	{
		$this->recordInto('test-registered-refused');
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');

		try
		{
			UserFactory::new()->registered()->create(['email' => 'not an address']);
			$this->fail('an invalid address was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('XenForo refused the registration', $e->getMessage());
		}

		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
	}

	public function test_cleaning_removes_a_registered_member_and_what_the_registration_wrote()
	{
		$recorder = $this->recordInto('test-registered-clean');
		$this->setOption('registrationSetup', ['emailConfirmation' => true] + $this->app()->options()->registrationSetup);
		$tables = ['xf_user', 'xf_ip', 'xf_change_log', 'xf_user_confirmation', 'xf_approval_queue', 'xf_user_alert', 'xf_conversation_master'];
		$before = $this->tableCounts($tables);

		UserFactory::new()->registered()->withIpLog()->count(2)->create();
		UserFactory::new()->registered()->moderated()->create();
		$this->assertCount(3, $recorder->created()['XF:User']);

		$result = (new Cleaner())->clean('test-registered-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->tableCounts($tables));
	}

	public function test_the_seeder_registers_every_member_it_makes_and_says_so()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'User', '-p' => ['users=2', 'moderated=1', 'registered=1'], '--batch' => 'test-registered-seeder'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$members = array_column(json_decode($tester->getDisplay(), true)['created']['XF:User'], 'id');
		$this->assertCount(3, $members);
		// the row only the service writes, given no IP log was asked for
		$this->assertSame(3, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_ip WHERE content_type = 'user' AND action = 'register' AND user_id IN (" . implode(',', array_map('intval', $members)) . ')'
		));
		$this->assertStringContainsString('registered=1', $tester->getErrorOutput());
		$this->assertNoMailSent();

		$this->assertSame([], (new Cleaner())->clean('test-registered-seeder')['failed']);
	}

	public function test_a_request_is_made_from_the_values_given_for_the_callback_and_the_real_one_put_back()
	{
		$real = $this->app()->request();

		$seen = Context::withRequest(['REMOTE_ADDR' => '192.0.2.5', 'HTTP_CF_IPCOUNTRY' => 'AU'], function ()
		{
			$request = \XF::app()->request();

			return [$request->getIp(), $request->getServer('HTTP_CF_IPCOUNTRY'), $request->getRequestMethod()];
		});
		$this->assertSame(['192.0.2.5', 'AU', 'get'], $seen, 'GET unless a method is given');
		$this->assertSame($real, $this->app()->request());

		try
		{
			Context::withRequest(['REQUEST_METHOD' => 'POST'], function ()
			{
				throw new \RuntimeException('thrown inside');
			});
			$this->fail('the exception was swallowed');
		}
		catch (\RuntimeException $e)
		{
			$this->assertSame('thrown inside', $e->getMessage());
		}
		$this->assertSame($real, $this->app()->request(), 'put back after a throw as well');
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}

	private function tableCounts(array $tables): array
	{
		$counts = [];
		foreach ($tables AS $table)
		{
			$counts[$table] = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
		}

		return $counts;
	}
}
