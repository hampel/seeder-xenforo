<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Config;
use Hampel\Seeder\Cli\Command\Validate;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * seeder:config - what this forum is told - and seeder:validate - whether it can do it.
 */
class CommandsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('enableMail', false);
		$this->withActiveAddOns();
		$this->fakesErrors();
		$this->fakesMail();
	}

	/**
	 * XenForo loads every add-on's command classes to list them, so one that cannot load takes down
	 * every command in cmd.php, not only its own.
	 */
	public function test_every_command_this_add_on_ships_is_one_xenforo_can_load()
	{
		$classes = $this->assertConsoleCommandsLoad('Hampel/Seeder');
		sort($classes);

		// the whole list, so that a command added without a test fails here
		$this->assertSame([
			'Hampel\\Seeder\\Cli\\Command\\Clean',
			'Hampel\\Seeder\\Cli\\Command\\Config',
			'Hampel\\Seeder\\Cli\\Command\\Forget',
			'Hampel\\Seeder\\Cli\\Command\\ListBatches',
			'Hampel\\Seeder\\Cli\\Command\\Run',
			'Hampel\\Seeder\\Cli\\Command\\Validate',
		], $classes);
	}

	public function test_config_shows_the_guard_and_where_each_value_comes_from()
	{
		[$code, $output] = $this->runCommand(new Config());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/seeding allowed \.+ no \(not in config\.php\)/', $output);
		$this->assertMatchesRegularExpression('/locale \.+ en_AU \(default\)/', $output);
		$this->assertMatchesRegularExpression('/Faker \.+ v?\d+\.\d+/', $output);
		$this->assertMatchesRegularExpression('/enableMail \.+ no$/m', $output);

		$this->setConfig('hampelSeeder', ['enabled' => true, 'locale' => 'en_GB']);
		[, $output] = $this->runCommand(new Config());

		$this->assertMatchesRegularExpression('/seeding allowed \.+ yes \(config\.php\)/', $output);
		$this->assertMatchesRegularExpression('/locale \.+ en_GB \(config\.php\)/', $output);
	}

	/**
	 * The transport option also holds the transport's credentials.
	 */
	public function test_config_prints_the_mail_transport_type_and_none_of_its_settings()
	{
		$this->setOption('emailTransport', [
			'emailTransport' => 'smtp',
			'smtpHost' => 'mail.example.com',
			'smtpLoginUsername' => 'a-user-name',
			'smtpLoginPassword' => 'a-secret-value',
		]);

		[, $output] = $this->runCommand(new Config());

		$this->assertMatchesRegularExpression('/transport \.+ smtp \(options\)/', $output);
		$this->assertStringNotContainsString('a-secret-value', $output);
		$this->assertStringNotContainsString('a-user-name', $output);
		$this->assertStringNotContainsString('mail.example.com', $output);
	}

	public function test_config_sends_nothing()
	{
		$this->runCommand(new Config());

		$this->assertNoMailSent();
	}

	public function test_validate_with_seeding_off_warns_and_still_exits_zero()
	{
		[$code, $output] = $this->runCommand($this->validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[warn\] seeding allowed +switched off/', $output);
		$this->assertStringContainsString('Validated, with warnings', $output);
	}

	public function test_strict_makes_a_warning_exit_two()
	{
		[$code] = $this->runCommand($this->validate(), ['--strict' => true]);

		$this->assertSame(2, $code);
	}

	public function test_validate_is_clean_when_seeding_is_on_and_mail_is_off()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand($this->validate());
		[$strict, $strictOutput] = $this->runCommand($this->validate(), ['--strict' => true]);

		$this->assertSame(0, $code);
		$this->assertSame(0, $strict, $strictOutput);
		$this->assertMatchesRegularExpression('/\[ ok \] seeding allowed/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] email addresses +reserved domains only/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] enableMail +off/', $output);
		$this->assertStringNotContainsString('[warn]', $output);
		$this->assertStringNotContainsString('[fail]', $output);
	}

	public function test_a_forum_that_can_send_mail_is_a_warning()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->setConfig('enableMail', true);

		[$code, $output] = $this->runCommand($this->validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[warn\] enableMail +on - this forum can send mail/', $output);
	}

	public function test_a_locale_faker_does_not_know_is_a_warning()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true, 'locale' => 'xx_XX']);

		[$code, $output] = $this->runCommand($this->validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[warn\] locale +xx_XX has no Faker providers/', $output);
	}

	/**
	 * A check that throws is a [fail] row, and the checks after it still run.
	 */
	public function test_a_check_that_throws_fails_the_run_without_ending_it()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		$command = new class extends Validate
		{
			protected function roundTrip(): array
			{
				return CommandsTest::roundTripResult();
			}

			protected function generator(): void
			{
				throw new \RuntimeException('the generator broke');
			}
		};

		[$code, $output] = $this->runCommand($command);
		[$strict] = $this->runCommand($command, ['--strict' => true]);

		$this->assertSame(1, $code);
		$this->assertSame(1, $strict, '1 means failed with --strict too');
		$this->assertMatchesRegularExpression('/\[fail\] Faker +RuntimeException: the generator broke/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] enableMail/', $output, 'the check after the failure still ran');
		$this->assertStringContainsString('Validation failed', $output);
		$this->assertExceptionLogged(\RuntimeException::class, 'Seeder: seeder:validate - Faker: ');
	}

	public function test_a_failure_outranks_a_warning()
	{
		$command = new class extends Validate
		{
			protected function roundTrip(): array
			{
				return CommandsTest::roundTripResult();
			}

			protected function generator(): void
			{
				throw new \RuntimeException('the generator broke');
			}
		};

		[$code, $output] = $this->runCommand($command, ['--strict' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('[warn]', $output);
	}

	public function test_validate_sends_nothing()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		$this->runCommand($this->validate());
		$this->runCommand($this->validate(), ['--unattended' => true]);

		$this->assertNoMailSent();
	}

	public function test_validate_warns_about_each_add_on_that_reports_on_the_error_log()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true, 'errorLogQuietHours' => 6]);

		[$code, $quiet] = $this->runCommand($this->validate(), ['--strict' => true]);
		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[ ok \] quiet period +no seeded error is dated within 6 hours of now/', $quiet);

		$this->withActiveAddOns(['Hampel/LogDigest', 'Hampel/EventLogging']);
		[$code, $output] = $this->runCommand($this->validate(), ['--strict' => true]);

		$this->assertSame(2, $code);
		$this->assertMatchesRegularExpression('/\[warn\] Hampel\/LogDigest +active - seeded errors dated inside its window/', $output);
		$this->assertMatchesRegularExpression('/\[warn\] Hampel\/EventLogging +active - every seeded error will be forwarded/', $output);

		[, $config] = $this->runCommand(new Config());
		$this->assertMatchesRegularExpression('/quiet period \.+ 6 hours \(config\.php\)/', $config);
	}

	public function test_the_round_trip_is_skipped_unattended_and_with_seeding_off()
	{
		[, $off] = $this->runCommand($this->validate(['leaks' => ['xf_post' => [1, 2]]]));
		$this->assertMatchesRegularExpression('/\[    \] round trip +skipped - seeding is switched off/', $off);
		$this->assertStringNotContainsString('[fail]', $off);

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		[$code, $unattended] = $this->runCommand($this->validate(['leaks' => ['xf_post' => [1, 2]]]), ['--unattended' => true]);
		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[    \] round trip +skipped - --unattended/', $unattended);
	}

	public function test_a_round_trip_that_leaves_nothing_reports_what_it_made_and_compared()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand($this->validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[ ok \] seeded +XF:User x 3, XF:Thread x 8 in 1\.5s/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] cleaned +11 removed/', $output);
		$this->assertMatchesRegularExpression("/\[ ok \] no trace +280 tables compared, XenForo's own all back/", $output);
	}

	public function test_a_core_table_that_does_not_come_back_fails_the_run()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand($this->validate(['leaks' => ['xf_news_feed' => [28, 40]]]));

		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/\[fail\] left behind +xf_news_feed went from 28 rows to 40/', $output);
		$this->assertStringNotContainsString('no trace', $output);
	}

	public function test_another_add_ons_table_and_new_error_log_rows_are_warnings()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand($this->validate([
			'other' => ['xf_someone_log' => [5, 9]],
			'errors' => ['Something else broke'],
		]));
		[$strict] = $this->runCommand($this->validate(['other' => ['xf_someone_log' => [5, 9]]]), ['--strict' => true]);

		$this->assertSame(0, $code);
		$this->assertSame(2, $strict);
		$this->assertMatchesRegularExpression('/\[warn\] other add-on +xf_someone_log went from 5 rows to 9/', $output);
		$this->assertMatchesRegularExpression('/\[warn\] server error log +1 new while this ran, first: Something else broke/', $output);
		$this->assertMatchesRegularExpression('/\[ ok \] no trace/', $output);
	}

	public function test_a_seeder_failure_and_a_removal_failure_each_fail_the_run()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand($this->validate([
			'seed_failure' => 'RuntimeException: could not post',
			'failed' => [['entity' => 'XF:Post', 'id' => '9', 'error' => 'LogicException: no']],
		]));

		$this->assertSame(1, $code);
		$this->assertMatchesRegularExpression('/\[fail\] seeded +RuntimeException: could not post/', $output);
		$this->assertMatchesRegularExpression('/\[fail\] cleaned +1 could not be removed, first XF:Post 9 - LogicException: no\. Left in batch validate-test/', $output);
	}

	/**
	 * What a round trip that left nothing behind reports.
	 */
	public static function roundTripResult(array $with = []): array
	{
		return $with + [
			'batch' => 'validate-test',
			'created' => ['XF:User' => 3, 'XF:Thread' => 8],
			'seed_failure' => null,
			'removed' => 11,
			'gone' => 0,
			'failed' => [],
			'tables' => 280,
			'leaks' => [],
			'other' => [],
			'errors' => [],
			'seconds' => 1.5,
		];
	}

	/**
	 * seeder:validate with its round trip replaced by a given result. The real one creates and
	 * removes content, which no test outside a transaction may do.
	 */
	private function validate(array $with = []): Validate
	{
		$command = new class extends Validate
		{
			public $result = [];

			protected function roundTrip(): array
			{
				return CommandsTest::roundTripResult($this->result);
			}
		};
		$command->result = $with;

		return $command;
	}

	private function runCommand($command, array $input = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
