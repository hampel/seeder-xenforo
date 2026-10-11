<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Forget;
use Hampel\Seeder\Listener;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\PrintableException;

/**
 * Uninstalling drops the record table, so it is refused while a batch exists - and seeder:forget
 * is the way to say the content should stay.
 */
class UninstallTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const RECORDS = 'xf_hampel_seeder_record';

	public function test_the_listener_is_registered_for_this_add_on_only()
	{
		$listeners = $this->app()->db()->fetchAll(
			"SELECT * FROM xf_code_event_listener WHERE addon_id = 'Hampel/Seeder' AND event_id = 'addon_pre_uninstall'"
		);

		$this->assertCount(1, $listeners);
		$this->assertSame('Hampel/Seeder', $listeners[0]['hint']);
		$this->assertSame(Listener::class, $listeners[0]['callback_class']);
		$this->assertTrue(is_callable([$listeners[0]['callback_class'], $listeners[0]['callback_method']]));
	}

	public function test_uninstall_is_refused_while_a_batch_is_recorded_and_the_add_on_is_not_left_processing()
	{
		$this->record('test-uninstall');
		$addOn = $this->app()->addOnManager()->getById('Hampel/Seeder');
		$installed = $addOn->getInstalledAddOn();

		// what XenForo has already done by the time it fires the event
		$installed->is_processing = true;
		$installed->last_pending_action = 'uninstall:0';
		$installed->save();

		try
		{
			Listener::addonPreUninstall($addOn, $installed, []);
			$this->fail('the uninstall was allowed');
		}
		catch (PrintableException $e)
		{
			$this->assertStringContainsString('was not uninstalled', $e->getMessage());
			$this->assertStringContainsString('seeder:clean', $e->getMessage());
			$this->assertStringContainsString('seeder:forget', $e->getMessage());
		}

		$this->assertDatabaseHas('xf_addon', ['addon_id' => 'Hampel/Seeder', 'is_processing' => 0]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-uninstall']);
	}

	/**
	 * Through XenForo's own preUninstall(), which marks the add-on as processing, rebuilds the
	 * listener and extension caches without it, and only then fires the event. A listener that
	 * simply threw would leave the add-on stuck there: listed as processing, its listeners and
	 * class extensions out of the caches, and neither usable nor uninstallable.
	 */
	public function test_xenforos_own_uninstall_is_refused_and_leaves_the_add_on_as_it_was()
	{
		$this->record('test-uninstall-real');
		$db = $this->app()->db();
		$before = $db->fetchRow("SELECT active, is_processing, last_pending_action, version_id FROM xf_addon WHERE addon_id = 'Hampel/Seeder'");
		$addOn = $this->app()->addOnManager()->getById('Hampel/Seeder');

		try
		{
			$addOn->preUninstall();
			$this->fail('the uninstall was allowed');
		}
		catch (PrintableException $e)
		{
			$this->assertStringContainsString('Seeder was not uninstalled', $e->getMessage(), 'the phrase resolved');
			// the forum's real batches are counted too, so the numbers are whatever they are
			$this->assertMatchesRegularExpression('/holds \d+ seeded record\(s\) in \d+ batch\(es\)/', $e->getMessage());
			$this->assertStringNotContainsString('{records}', $e->getMessage());
		}

		$this->assertSame($before, $db->fetchRow("SELECT active, is_processing, last_pending_action, version_id FROM xf_addon WHERE addon_id = 'Hampel/Seeder'"));
		$this->assertSame('1', (string) $before['active']);
		$this->assertSame('0', (string) $before['is_processing']);

		// the caches XenForo rebuilt while the add-on was marked as processing have it back
		$listeners = $this->app()->registry()->get('codeEventListeners');
		$this->assertContains(Listener::class, array_column($this->flatten($listeners['addon_pre_uninstall'] ?? []), 0));
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-uninstall-real']);
		$this->assertDatabaseHas('xf_code_event_listener', ['addon_id' => 'Hampel/Seeder', 'event_id' => 'addon_pre_uninstall', 'active' => 1]);
	}

	/**
	 * And through the control panel, where the administrator is shown why.
	 */
	public function test_an_uninstall_from_the_control_panel_is_refused_with_the_reason()
	{
		$this->record('test-uninstall-acp');
		$db = $this->app()->db();
		$this->actingAsAdministrator(['addOn' => true], ['is_super_admin' => true]);
		$installed = $this->app()->addOnManager()->getById('Hampel/Seeder')->getInstalledAddOn();

		$reply = $this->dispatch('add-ons/' . $installed->addon_id_url . '/uninstall', 'admin', [], [], 'POST');

		$this->assertReplyIsError($reply);
		$errors = implode(' ', array_map('strval', $this->replyErrors($reply)));
		$this->assertStringContainsString('Seeder was not uninstalled', $errors);
		$this->assertStringContainsString('seeder:clean', $errors);
		$this->assertDatabaseHas('xf_addon', ['addon_id' => 'Hampel/Seeder', 'active' => 1, 'is_processing' => 0, 'last_pending_action' => null]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-uninstall-acp']);
		$this->assertNotFalse($db->fetchOne("SHOW TABLES LIKE 'xf_hampel_seeder_record'"), 'the record table is still there');
	}

	/**
	 * With the repository mocked: the real table holds other people's batches, and emptying it to
	 * set this up would be permanent the day this test's transaction did not hold.
	 */
	public function test_uninstall_is_allowed_once_nothing_is_recorded()
	{
		$this->mockRepository('Hampel\\Seeder:Record', function ($mock)
		{
			$mock->expects()->tableExists()->andReturns(true);
			$mock->expects()->getBatches()->andReturns([]);
		});
		$addOn = $this->app()->addOnManager()->getById('Hampel/Seeder');

		Listener::addonPreUninstall($addOn, $addOn->getInstalledAddOn(), []);

		$this->addToAssertionCount(1);
	}

	public function test_forget_discards_the_records_and_leaves_the_content()
	{
		$kept = $this->record('test-forget');
		$other = $this->record('test-forget-other');

		[$code, $output] = $this->forget(['batch' => 'test-forget', '--force' => true]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('forgot   test-forget - 1 record(s)', $output);
		$this->assertDatabaseCount(self::RECORDS, 0, ['batch' => 'test-forget']);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $kept]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-forget-other']);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $other]);
	}

	public function test_forget_asks_first_and_forgets_nothing_on_no_or_with_nobody_to_answer()
	{
		$this->record('test-forget-ask');

		[$code, $output] = $this->forget(['batch' => 'test-forget-ask'], ['n']);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('can never be cleaned', $output);

		$command = new Forget();
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		$tester = new CommandTester($command);
		$this->assertSame(1, $tester->execute(['batch' => 'test-forget-ask'], ['interactive' => false]));

		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-forget-ask']);
	}

	public function test_forget_runs_with_seeding_switched_off_and_refuses_an_unknown_batch()
	{
		$this->setConfig('hampelSeeder', []);
		$this->record('test-forget-off');

		[$code] = $this->forget(['batch' => 'test-forget-off', '--force' => true]);
		$this->assertSame(0, $code);

		[$code, $output] = $this->forget(['batch' => 'test-no-such-batch', '--force' => true]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString("no batch named 'test-no-such-batch'", $output);
	}

	public function test_forget_is_a_valid_xenforo_command()
	{
		$runner = new \XF\Cli\Runner();
		$isValid = new \ReflectionMethod($runner, 'isValidCommandClass');

		$this->assertTrue($isValid->invoke($runner, Forget::class));
	}

	private function record(string $batch): int
	{
		$question = $this->createEntity('XF:CaptchaQuestion', [
			'question' => 'Seeder test ' . bin2hex(random_bytes(6)),
			'answers' => ['yes'],
		]);
		(new TableRecorder($batch))->record($question);

		return $question->captcha_question_id;
	}

	/** the listener cache is [event => [hint => [[class, method], ...]]] */
	private function flatten(array $byHint): array
	{
		$flat = [];
		foreach ($byHint AS $callbacks)
		{
			foreach ($callbacks AS $callback)
			{
				$flat[] = $callback;
			}
		}

		return $flat;
	}

	private function forget(array $input, array $answers = []): array
	{
		$command = new Forget();
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		$tester = new CommandTester($command);
		$tester->setInputs($answers);
		$code = $tester->execute($input, ['decorated' => false]);

		return [$code, $tester->getDisplay()];
	}
}
