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
use Tests\Fixtures\CallingSeeder;
use Tests\Fixtures\QuestionFactory;
use Tests\Fixtures\QuestionSeeder;
use Tests\TestCase;

/**
 * Creating content for real, inside a transaction: the factory's create(), the member factory, and
 * seeder:run.
 */
class SeedingTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const RECORDS = 'xf_hampel_seeder_record';

	/** @var Context|null */
	private $previous;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->fakesMail();

		// Jobs are recorded, not queued. A real job run here would run with only this add-on
		// loaded, throw on the first entity another add-on extends, and the job manager answers a
		// throw with rollbackAll() - ending this test's transaction and making its writes permanent.
		$this->fakesJobs();
		QuestionFactory::$clock = null;
		$this->previous = Context::use(null);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_create_saves_records_and_names_its_factory()
	{
		$recorder = $this->recordInto('test-create');

		$question = QuestionFactory::new()->create();

		$this->assertTrue($question->exists());
		$this->assertDatabaseHas(self::RECORDS, [
			'batch' => 'test-create',
			'entity' => 'XF:CaptchaQuestion',
			'entity_id' => (string) $question->captcha_question_id,
			'factory' => QuestionFactory::class,
		]);
		$this->assertSame(
			['XF:CaptchaQuestion' => [['id' => $question->captcha_question_id, 'question' => $question->question]]],
			$recorder->created()
		);
	}

	public function test_create_with_a_count_saves_each_and_runs_after_creating()
	{
		$this->recordInto('test-count');
		$seen = [];

		$questions = QuestionFactory::new()->count(3)
			->afterCreating(function ($question) use (&$seen) { $seen[] = $question->captcha_question_id; })
			->create();

		$this->assertCount(3, $questions);
		$this->assertDatabaseCount(self::RECORDS, 3, ['batch' => 'test-count']);
		$this->assertSame(array_values($questions->pluckNamed('captcha_question_id')), $seen);
	}

	public function test_the_clock_is_moved_while_the_entity_is_saved_and_put_back()
	{
		$real = \XF::$time;
		QuestionFactory::$clock = 86400;

		// the entity has no dated column, so read the clock from inside the save
		$factory = new class extends QuestionFactory
		{
			public static $during;

			protected function store(\XF\Mvc\Entity\Entity $entity): void
			{
				self::$during = \XF::$time;
				parent::store($entity);
			}
		};

		$factory->create();
		$during = $factory::$during;

		$this->assertSame(86400, $during);
		$this->assertSame($real, \XF::$time);
	}

	public function test_a_member_is_whole_dated_and_can_log_in()
	{
		$this->recordInto('test-member', 'the-run-password');

		$user = UserFactory::new()->create();
		$id = $user->user_id;

		foreach (['xf_user', 'xf_user_option', 'xf_user_profile', 'xf_user_privacy', 'xf_user_authenticate'] AS $table)
		{
			$this->assertDatabaseHas($table, ['user_id' => $id]);
		}
		$this->assertDatabaseHas('xf_user_group_relation', ['user_id' => $id, 'user_group_id' => 2]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-member', 'entity' => 'XF:User', 'factory' => UserFactory::class]);

		$fresh = $this->app()->em()->find('XF:User', $id);
		$this->assertSame('valid', $fresh->user_state);
		$this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $fresh->email);
		$this->assertTrue($fresh->Auth->authenticate('the-run-password'));
		$this->assertFalse($fresh->Auth->authenticate('something else'));

		$timeline = Context::current()->timeline;
		$this->assertGreaterThanOrEqual($timeline->start(), $fresh->register_date);
		$this->assertLessThanOrEqual($timeline->end(), $fresh->register_date);
		$this->assertGreaterThanOrEqual($fresh->register_date, $fresh->getValue('last_activity'));
		$this->assertSame($fresh->register_date, $fresh->Profile->password_date);
	}

	public function test_a_member_can_be_given_a_date_a_name_and_a_state()
	{
		$this->recordInto('test-states');

		$user = UserFactory::new()->moderated()->create(['username' => 'SeederTestNamed', 'register_date' => 1700000000]);

		$this->assertSame('SeederTestNamed', $user->username);
		$this->assertSame('seedertestnamed', strstr($user->email, '@', true));
		$this->assertSame('moderated', $user->user_state);
		$this->assertSame(1700000000, $user->register_date);
		$this->assertSame('email_confirm', UserFactory::new()->unconfirmed()->make()->user_state);
	}

	public function test_members_get_names_and_addresses_nobody_already_has()
	{
		$this->recordInto('test-unique');

		$users = UserFactory::new()->count(40)->create();

		$this->assertCount(40, array_unique(array_map('strtolower', $users->pluckNamed('username'))));
		$this->assertCount(40, array_unique($users->pluckNamed('email')));
	}

	public function test_a_state_can_set_a_members_option_profile_and_privacy_rows()
	{
		$this->recordInto('test-related');

		$on = UserFactory::new()->create([
			'Option.receive_admin_email' => true,
			'Profile.location' => 'Seedertown',
			'Privacy.allow_view_profile' => 'none',
		]);
		$off = UserFactory::new()->state(['Option.receive_admin_email' => false])->create();

		$this->assertDatabaseHas('xf_user_option', ['user_id' => $on->user_id, 'receive_admin_email' => 1]);
		$this->assertDatabaseHas('xf_user_profile', ['user_id' => $on->user_id, 'location' => 'Seedertown']);
		$this->assertDatabaseHas('xf_user_privacy', ['user_id' => $on->user_id, 'allow_view_profile' => 'none']);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $off->user_id, 'receive_admin_email' => 0]);
	}

	/**
	 * Whatever the forum gives a member who registers, a seeded member gets too.
	 */
	public function test_a_member_starts_with_the_forums_registration_defaults()
	{
		$this->recordInto('test-defaults');
		$defaults = \XF::options()->registrationDefaults;

		$this->setOption('registrationDefaults', ['receive_admin_email' => true] + $defaults);
		$this->assertTrue(UserFactory::new()->create()->Option->receive_admin_email);

		$this->setOption('registrationDefaults', ['receive_admin_email' => false] + $defaults);
		$this->assertFalse(UserFactory::new()->create()->Option->receive_admin_email);
	}

	public function test_a_member_can_have_no_email_address()
	{
		$this->recordInto('test-no-email');

		$user = UserFactory::new()->withoutEmail()->create();

		$this->assertDatabaseHas('xf_user', ['user_id' => $user->user_id, 'email' => '']);
		$this->assertNotSame('', UserFactory::new()->create()->email, 'the state must not leak to other members');
	}

	public function test_secondary_groups_may_be_given_as_one_id_or_a_list()
	{
		$this->recordInto('test-groups');

		$one = UserFactory::new()->create(['secondary_group_ids' => 3]);
		$two = UserFactory::new()->create(['secondary_group_ids' => [3, 4]]);

		$this->assertSame([3], $one->secondary_group_ids);
		$this->assertSame([3, 4], $two->secondary_group_ids);
		$this->assertDatabaseCount('xf_user_group_relation', 3, ['user_id' => $two->user_id]);
	}

	/**
	 * The forum decides what a member may be called, and its limits differ from one to the next.
	 */
	public function test_member_names_obey_the_forums_own_length_limit()
	{
		$this->recordInto('test-length');
		$this->setOption('usernameLength', ['min' => 3, 'max' => 10]);

		$users = UserFactory::new()->count(30)->create();

		$this->assertLessThanOrEqual(10, max(array_map('strlen', $users->pluckNamed('username'))));
	}

	public function test_creating_members_sends_no_mail_and_switches_their_webhooks_off()
	{
		$this->recordInto('test-quiet');

		$user = UserFactory::new()->create();

		$this->assertNoMailSent();
		$this->assertFalse($user->getBehavior('XF:Webhook')->getOption('enabled'));
	}

	public function test_cleaning_a_member_leaves_no_rows_behind()
	{
		$this->recordInto('test-member-clean');
		$tables = ['xf_user', 'xf_user_option', 'xf_user_profile', 'xf_user_privacy', 'xf_user_authenticate',
			'xf_user_group_relation', 'xf_ip', 'xf_change_log'];
		$before = $this->counts($tables);

		UserFactory::new()->count(3)->create();
		$this->assertSame($before['xf_user'] + 3, $this->counts(['xf_user'])['xf_user']);

		$result = (new Cleaner())->clean('test-member-clean');

		$this->assertSame(['XF:User' => 3], $result['removed']);
		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->counts($tables));

		// the member's own clean-up ran here and was not left for the queue; what was queued is
		// the search re-index, which a real run drains before it returns
		$this->assertJobNotQueued(\XF\Job\UserDeleteCleanUp::class);
		$this->assertJobQueuedTimes(\XF\Job\SearchUserChange::class, 3);
	}

	public function test_run_prints_what_it_created_as_json_and_a_summary_separately()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => QuestionSeeder::class, '--param' => ['questions=3'], '--batch' => 'test-run', '--seed' => '5',
		]);

		$this->assertSame(0, $code, $summary);
		$result = json_decode($json, true);
		$this->assertSame('test-run', $result['batch']);
		$this->assertSame(QuestionSeeder::class, $result['seeder']);
		$this->assertSame(5, $result['seed']);
		$this->assertTrue($result['complete']);
		$this->assertCount(3, $result['created']['XF:CaptchaQuestion']);
		$this->assertArrayHasKey('id', $result['created']['XF:CaptchaQuestion'][0]);
		$this->assertMatchesRegularExpression('/^[a-z]{4}-[a-z]{4}-[0-9]{4}$/', $result['password']);

		$this->assertStringContainsString('created  XF:CaptchaQuestion x 3', $summary);
		$this->assertStringContainsString('batch    test-run', $summary);
		$this->assertDatabaseCount(self::RECORDS, 3, ['batch' => 'test-run']);
	}

	public function test_run_creates_members_whose_password_is_the_one_it_prints()
	{
		[$code, $json] = $this->run_(['seeder' => 'User', '--param' => ['users=2'], '--batch' => 'test-run-users']);

		$this->assertSame(0, $code);
		$result = json_decode($json, true);
		$this->assertCount(2, $result['created']['XF:User']);
		$member = $result['created']['XF:User'][0];
		$this->assertSame(['id', 'username', 'email'], array_keys($member));
		$this->assertTrue($this->app()->em()->find('XF:User', $member['id'])->Auth->authenticate($result['password']));
		$this->assertNoMailSent();
	}

	public function test_the_same_seed_gives_the_same_members()
	{
		[, $first] = $this->run_(['seeder' => 'User', '--param' => ['users=5'], '--batch' => 'test-seed-a', '--seed' => '77']);
		(new Cleaner())->clean('test-seed-a');
		[, $second] = $this->run_(['seeder' => 'User', '--param' => ['users=5'], '--batch' => 'test-seed-b', '--seed' => '77']);

		$first = json_decode($first, true);
		$second = json_decode($second, true);
		$this->assertSame(array_column($first['created']['XF:User'], 'username'), array_column($second['created']['XF:User'], 'username'));
		$this->assertSame($first['password'], $second['password']);
	}

	public function test_run_restores_the_context_it_replaced()
	{
		$mine = $this->recordInto('test-context');

		$this->run_(['seeder' => QuestionSeeder::class, '--batch' => 'test-context-run']);

		$this->assertSame($mine, Context::current()->recorder);
	}

	public function test_a_seeder_calls_others_and_passes_its_parameters_down()
	{
		[$code, $json] = $this->run_(['seeder' => CallingSeeder::class, '--param' => ['questions=4'], '--batch' => 'test-call']);

		$this->assertSame(0, $code);
		// one from the call that overrides the parameter, four from the call that inherits it
		$this->assertCount(5, json_decode($json, true)['created']['XF:CaptchaQuestion']);
	}

	public function test_run_refuses_when_seeding_is_switched_off()
	{
		$this->setConfig('hampelSeeder', []);
		$before = $this->counts(['xf_captcha_question', self::RECORDS]);

		[$code, $json, $summary] = $this->run_(['seeder' => QuestionSeeder::class, '--batch' => 'test-off']);

		$this->assertSame(1, $code);
		$this->assertSame('', trim($json));
		$this->assertStringContainsString('Seeding is switched off', $summary);
		$this->assertSame($before, $this->counts(['xf_captcha_question', self::RECORDS]));
	}

	public function test_run_refuses_a_batch_that_already_exists()
	{
		$this->run_(['seeder' => QuestionSeeder::class, '--batch' => 'test-twice']);

		[$code, , $summary] = $this->run_(['seeder' => QuestionSeeder::class, '--batch' => 'test-twice']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString("already a batch named 'test-twice'", $summary);
		$this->assertDatabaseCount(self::RECORDS, 2, ['batch' => 'test-twice']);
	}

	public function test_run_refuses_bad_input_before_creating_anything()
	{
		$before = $this->counts(['xf_captcha_question', self::RECORDS]);

		foreach ([
			['seeder' => 'NoSuchSeeder'],
			['seeder' => QuestionSeeder::class, '--span' => 'ages'],
			['seeder' => QuestionSeeder::class, '--seed' => 'abc'],
			['seeder' => QuestionSeeder::class, '--param' => ['no-equals']],
			['seeder' => QuestionSeeder::class, '--batch' => 'two words'],
		] AS $input)
		{
			[$code] = $this->run_($input);
			$this->assertSame(1, $code, json_encode($input));
		}

		$this->assertSame($before, $this->counts(['xf_captcha_question', self::RECORDS]));
	}

	/**
	 * What a seeder made before it threw is recorded, reported, and can be cleaned.
	 */
	public function test_a_seeder_that_fails_exits_one_and_leaves_its_work_recorded()
	{
		$this->fakesErrors();

		[$code, $json, $summary] = $this->run_([
			'seeder' => QuestionSeeder::class, '--param' => ['fail=1'], '--batch' => 'test-fail-run',
		]);

		$this->assertSame(1, $code);
		$result = json_decode($json, true);
		$this->assertFalse($result['complete']);
		$this->assertCount(2, $result['created']['XF:CaptchaQuestion']);
		$this->assertStringContainsString('The seeder failed: RuntimeException: the seeder broke', $summary);
		$this->assertStringContainsString('seeder:clean test-fail-run', $summary);
		$this->assertDatabaseCount(self::RECORDS, 2, ['batch' => 'test-fail-run']);
		$this->assertExceptionLogged(\RuntimeException::class);

		$cleaned = (new Cleaner())->clean('test-fail-run');
		$this->assertSame(['XF:CaptchaQuestion' => 2], $cleaned['removed']);
	}

	public function test_no_record_creates_content_and_records_nothing()
	{
		$before = $this->counts(['xf_captcha_question', self::RECORDS]);

		[$code, $json, $summary] = $this->run_(['seeder' => QuestionSeeder::class, '--no-record' => true]);

		$this->assertSame(0, $code);
		$this->assertNull(json_decode($json, true)['batch']);
		$this->assertStringContainsString('cannot be removed by seeder:clean', $summary);
		$after = $this->counts(['xf_captcha_question', self::RECORDS]);
		$this->assertSame($before['xf_captcha_question'] + 2, $after['xf_captcha_question']);
		$this->assertSame($before[self::RECORDS], $after[self::RECORDS]);
	}

	private function recordInto(string $batch, string $password = 'password'): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), $password));

		return $recorder;
	}

	private function counts(array $tables): array
	{
		$counts = [];
		foreach ($tables AS $table)
		{
			$counts[$table] = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
		}

		return $counts;
	}

	/**
	 * @return array [exit code, standard output, standard error]
	 */
	private function run_(array $input): array
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute($input, ['decorated' => false, 'capture_stderr_separately' => true]);

		return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
	}
}
