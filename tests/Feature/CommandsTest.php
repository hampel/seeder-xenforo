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
		$this->fakesErrors();
		$this->fakesMail();
	}

	/**
	 * XenForo loads every add-on's command classes to list them, so one that cannot load takes down
	 * every command in cmd.php, not only its own.
	 */
	public function test_both_commands_are_valid_xenforo_commands()
	{
		$runner = new \XF\Cli\Runner();
		$isValid = new \ReflectionMethod($runner, 'isValidCommandClass');

		$this->assertTrue($isValid->invoke($runner, Config::class));
		$this->assertTrue($isValid->invoke($runner, Validate::class));
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
		[$code, $output] = $this->runCommand(new Validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[warn\] seeding allowed +switched off/', $output);
		$this->assertStringContainsString('Validated, with warnings', $output);
	}

	public function test_strict_makes_a_warning_exit_two()
	{
		[$code] = $this->runCommand(new Validate(), ['--strict' => true]);

		$this->assertSame(2, $code);
	}

	public function test_validate_is_clean_when_seeding_is_on_and_mail_is_off()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		[$code, $output] = $this->runCommand(new Validate());
		[$strict, $strictOutput] = $this->runCommand(new Validate(), ['--strict' => true]);

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

		[$code, $output] = $this->runCommand(new Validate());

		$this->assertSame(0, $code);
		$this->assertMatchesRegularExpression('/\[warn\] enableMail +on - this forum can send mail/', $output);
	}

	public function test_a_locale_faker_does_not_know_is_a_warning()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true, 'locale' => 'xx_XX']);

		[$code, $output] = $this->runCommand(new Validate());

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
		$this->assertExceptionLogged(\RuntimeException::class);
	}

	public function test_a_failure_outranks_a_warning()
	{
		$command = new class extends Validate
		{
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

		$this->runCommand(new Validate());
		$this->runCommand(new Validate(), ['--unattended' => true]);

		$this->assertNoMailSent();
	}

	private function runCommand($command, array $input = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
