<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\PaymentProfileFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserFieldFactory;
use Hampel\Seeder\Factory\UserUpgradeActiveFactory;
use Hampel\Seeder\Factory\UserUpgradeExpiredFactory;
use Hampel\Seeder\Factory\UserUpgradeFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Custom user fields with members' values, and user upgrades held and run out, created for real
 * inside a transaction.
 */
class FieldAndUpgradeTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var Context|null */
	private $previous;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->useTemporaryCodeCache();
		$this->fakesMail();
		$this->fakesJobs();
		$this->previous = Context::use(null);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);
		// the list of fields is kept for the life of the process, and the rollback does not know
		$this->app()->container()->decache('customFields.users');

		parent::tearDown();
	}

	public function test_a_field_has_its_phrases_and_its_choices_and_cleaning_removes_them()
	{
		$this->recordInto('test-field');
		$db = $this->app()->db();
		$phrases = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_phrase');

		$field = UserFieldFactory::new()->choices(['Red', 'Dark blue'])->group('preferences')->required()
			->create(['field_id' => 'seedertest_colour', 'title' => 'Seedertest colour', 'description' => 'Pick one']);

		$this->assertDatabaseHas('xf_user_field', ['field_id' => 'seedertest_colour', 'field_type' => 'select', 'display_group' => 'preferences', 'required' => 1]);
		$this->assertSame(['red' => 'Red', 'dark_blue' => 'Dark blue'], $field->field_choices);
		$this->assertDatabaseHas('xf_phrase', ['title' => 'user_field_title.seedertest_colour', 'phrase_text' => 'Seedertest colour']);
		$this->assertDatabaseHas('xf_phrase', ['title' => 'user_field_desc.seedertest_colour', 'phrase_text' => 'Pick one']);
		$this->assertDatabaseHas('xf_phrase', ['title' => 'user_field_choice.seedertest_colour_dark_blue', 'phrase_text' => 'Dark blue']);
		$this->assertArrayHasKey('seedertest_colour', $this->app()->registry()->get('userFieldsInfo'), 'the forum knows of it at once');

		$result = (new Cleaner())->clean('test-field');

		$this->assertSame([], $result['failed']);
		$this->assertDatabaseMissing('xf_user_field', ['field_id' => 'seedertest_colour']);
		$this->assertSame($phrases, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_phrase'));
		$this->assertArrayNotHasKey('seedertest_colour', $this->app()->registry()->get('userFieldsInfo'));
	}

	public function test_a_date_or_a_colour_is_a_text_box_that_checks_its_value()
	{
		$this->recordInto('test-field-forms');

		$field = UserFieldFactory::new()->matching('date')->create();
		$this->assertSame(['textbox', 'date'], [$field->field_type, $field->match_type]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("use matching('date')");
		UserFieldFactory::new()->type('date');
	}

	public function test_members_are_given_values_which_go_when_the_member_or_the_field_goes()
	{
		$this->recordInto('test-field-fields');
		$db = $this->app()->db();
		$text = UserFieldFactory::new()->create(['field_id' => 'seedertest_text']);
		$many = UserFieldFactory::new()->type('multiselect')->choices(['One', 'Two', 'Three'])->create(['field_id' => 'seedertest_many']);

		$recorder = $this->recordInto('test-field-values');
		$member = UserFactory::new()->withFields(['seedertest_text' => 'Hello there', 'seedertest_many' => ['one', 'three']])->create();

		$this->assertDatabaseHas('xf_user_field_value', ['user_id' => $member->user_id, 'field_id' => 'seedertest_text', 'field_value' => 'Hello there']);
		$this->assertDatabaseCount('xf_user_field_value', 2, ['user_id' => $member->user_id]);
		$cache = json_decode($db->fetchOne('SELECT custom_fields FROM xf_user_profile WHERE user_id = ?', $member->user_id), true);
		$this->assertSame('Hello there', $cache['seedertest_text']);
		$this->assertSame(['one', 'three'], array_keys($cache['seedertest_many']));
		$this->assertSame('Hello there', $recorder->created()['XF:User'][0]['fields']['seedertest_text']);

		$other = UserFactory::new()->withFields(['seedertest_text' => 'Stays until the field goes'])->create();
		$recorder = $this->recordInto('test-field-other');
		UserFactory::remove($member);
		$this->assertDatabaseCount('xf_user_field_value', 0, ['user_id' => $member->user_id]);

		UserFieldFactory::remove($text);
		UserFieldFactory::remove($many);
		$this->assertDatabaseCount('xf_user_field_value', 0, ['user_id' => $other->user_id]);
	}

	public function test_every_kind_of_field_gets_a_value_it_accepts_and_a_share_leaves_some_empty()
	{
		$this->recordInto('test-field-all');
		$db = $this->app()->db();
		$ids = [];
		foreach (UserFieldFactory::TYPES AS $type)
		{
			$ids[] = UserFieldFactory::new()->type($type)->create()->field_id;
		}
		foreach (['number', 'alphanumeric', 'email', 'url', 'color', 'date'] AS $form)
		{
			$ids[] = UserFieldFactory::new()->matching($form)->create()->field_id;
		}
		$in = $db->quote($ids);

		$member = UserFactory::new()->withFieldValues()->create();
		$this->assertSame(count($ids), (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user_field_value WHERE user_id = ? AND field_id IN ({$in})", $member->user_id));

		$members = UserFactory::new()->withFieldValues(0.5)->count(8)->create();
		$values = (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user_field_value WHERE field_id IN ({$in}) AND user_id IN (" . $db->quote(array_values($members->pluckNamed('user_id'))) . ')');
		$this->assertGreaterThan(count($ids) * 8 * 0.2, $values);
		$this->assertLessThan(count($ids) * 8 * 0.8, $values);
	}

	public function test_a_field_the_forum_does_not_have_or_a_value_it_would_not_take_is_refused()
	{
		$this->recordInto('test-field-bad');
		UserFieldFactory::new()->choices(['Yes', 'No'])->create(['field_id' => 'seedertest_yesno']);

		try
		{
			UserFactory::new()->withFields(['seedertest_nothere' => 'x'])->create();
			$this->fail('an unknown field was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString("no custom user field 'seedertest_nothere'", $e->getMessage());
		}

		try
		{
			UserFactory::new()->withFields(['seedertest_yesno' => 'maybe'])->create();
			$this->fail('a choice the field does not offer was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString("'seedertest_yesno' has no choice 'maybe'", $e->getMessage());
		}

		UserFieldFactory::new()->matching('number')->create(['field_id' => 'seedertest_number']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('would not take its value');
		UserFactory::new()->withFields(['seedertest_number' => 'twelve'])->create();
	}

	public function test_an_upgrade_is_not_for_sale_and_names_a_profile_that_takes_no_payments()
	{
		$recorder = $this->recordInto('test-upgrade');

		$upgrade = UserUpgradeFactory::new()->lasting(3, 'month')->create(['title' => 'Seedertest supporter']);

		$this->assertDatabaseHas('xf_user_upgrade', ['user_upgrade_id' => $upgrade->user_upgrade_id, 'can_purchase' => 0, 'length_amount' => 3, 'length_unit' => 'month']);
		$this->assertCount(1, $upgrade->payment_profile_ids);
		$this->assertDatabaseHas('xf_payment_profile', ['payment_profile_id' => $upgrade->payment_profile_ids[0], 'active' => 0, 'provider_id' => 'paypal']);
		$this->assertSame(0, UserUpgradeFactory::endOf(UserUpgradeFactory::new()->permanent()->make(), 1700000000));

		$result = (new Cleaner())->clean('test-upgrade');

		$this->assertSame([], $result['failed']);
		$this->assertDatabaseMissing('xf_user_upgrade', ['user_upgrade_id' => $upgrade->user_upgrade_id]);
		$this->assertDatabaseMissing('xf_payment_profile', ['payment_profile_id' => $upgrade->payment_profile_ids[0]]);
	}

	public function test_a_purchasable_upgrade_is_offered_to_a_member_and_still_cannot_be_bought()
	{
		$this->recordInto('test-upgrade-purchasable');
		$member = UserFactory::new()->create();
		$hidden = UserUpgradeFactory::new()->create(['title' => 'Seedertest hidden']);
		$offered = UserUpgradeFactory::new()->purchasable()->create(['title' => 'Seedertest offered']);

		// a guest is shown every upgrade whatever its flag, so ask as a member
		$available = \XF::asVisitor($member, function ()
		{
			return $this->app()->repository('XF:UserUpgrade')->getFilteredUserUpgradesForList()[0];
		});
		$this->assertArrayHasKey($offered->user_upgrade_id, $available);
		$this->assertArrayNotHasKey($hidden->user_upgrade_id, $available);
		$this->assertTrue(UserUpgradeFactory::describe($offered)['can_purchase']);

		$request = new \XF\Http\Request($this->app()->inputFilterer(), [
			'payment_profile_id' => $offered->payment_profile_ids[0], 'user_upgrade_id' => $offered->user_upgrade_id,
		]);
		$purchase = (new \XF\Purchasable\UserUpgrade('user_upgrade'))->getPurchaseFromRequest($request, $member, $error);

		$this->assertFalse($purchase);
		$this->assertSame('please_choose_valid_payment_profile_to_continue_with_your_purchase', $error->getName());
		$this->assertDatabaseCount('xf_purchase_request', 0, ['user_id' => $member->user_id]);
	}

	public function test_a_profile_something_still_names_is_left_and_reported()
	{
		$this->recordInto('test-upgrade-profile');
		$upgrade = UserUpgradeFactory::new()->create();
		$profile = $this->app()->em()->find('XF:PaymentProfile', $upgrade->payment_profile_ids[0]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('still named by a user upgrade');
		PaymentProfileFactory::remove($profile);
	}

	#[DataProvider('ways')]
	public function test_a_held_upgrade_ends_in_the_future_and_puts_the_member_in_its_groups(bool $hooks)
	{
		$this->recordInto('test-upgrade-held');
		$db = $this->app()->db();
		$group = $this->createEntity('XF:UserGroup', ['title' => 'Seedertest upgraded']);
		$member = UserFactory::new()->create(['register_date' => time() - 86400 * 400]);
		$upgrade = UserUpgradeFactory::new()->lasting(1, 'month')->grantingGroups([$group->user_group_id])->create();
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');

		$factory = UserUpgradeActiveFactory::new()->for($member)->for($upgrade);
		$active = ($hooks ? $factory->withHooks() : $factory)->create();

		$row = $db->fetchRow('SELECT * FROM xf_user_upgrade_active WHERE user_upgrade_record_id = ?', $active->user_upgrade_record_id);
		$this->assertSame($member->user_id, (int) $row['user_id']);
		$this->assertGreaterThan(time(), $row['end_date']);
		$this->assertLessThan(time(), $row['start_date']);
		$this->assertSame(strtotime('+1 month', $row['start_date']), (int) $row['end_date']);
		$this->assertEquals(['cost_amount' => $upgrade->cost_amount, 'cost_currency' => 'usd', 'length_amount' => 1, 'length_unit' => 'month'], json_decode($row['extra'], true));
		$this->assertDatabaseHas('xf_user_group_change', ['user_id' => $member->user_id, 'change_key' => 'userUpgrade-' . $upgrade->user_upgrade_id]);
		$this->assertContains((string) $group->user_group_id, explode(',', $db->fetchOne('SELECT secondary_group_ids FROM xf_user WHERE user_id = ?', $member->user_id)));

		UserUpgradeActiveFactory::remove($active);

		$this->assertDatabaseCount('xf_user_upgrade_active', 0, ['user_id' => $member->user_id]);
		$this->assertDatabaseCount('xf_user_group_change', 0, ['user_id' => $member->user_id]);
		$this->assertSame('', $db->fetchOne('SELECT secondary_group_ids FROM xf_user WHERE user_id = ?', $member->user_id));
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'), 'nobody was told it ended');
	}

	#[DataProvider('ways')]
	public function test_a_run_out_upgrade_has_the_dates_given_and_leaves_the_member_in_no_group(bool $hooks)
	{
		$this->recordInto('test-upgrade-expired');
		$db = $this->app()->db();
		$group = $this->createEntity('XF:UserGroup', ['title' => 'Seedertest upgraded']);
		$member = UserFactory::new()->create(['register_date' => 1600000000]);
		$upgrade = UserUpgradeFactory::new()->lasting(1, 'year')->grantingGroups([$group->user_group_id])->create();
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');

		$factory = UserUpgradeExpiredFactory::new()->for($member)->for($upgrade);
		$expired = ($hooks ? $factory->withHooks() : $factory)->create(['start_date' => 1650000000]);

		$this->assertDatabaseHas('xf_user_upgrade_expired', [
			'user_upgrade_record_id' => $expired->user_upgrade_record_id, 'user_id' => $member->user_id,
			'user_upgrade_id' => $upgrade->user_upgrade_id, 'start_date' => 1650000000,
			'original_end_date' => strtotime('+1 year', 1650000000), 'end_date' => strtotime('+1 year', 1650000000),
		]);
		$this->assertDatabaseCount('xf_user_upgrade_active', 0, ['user_id' => $member->user_id]);
		$this->assertDatabaseCount('xf_user_group_change', 0, ['user_id' => $member->user_id]);
		$this->assertSame('', $db->fetchOne('SELECT secondary_group_ids FROM xf_user WHERE user_id = ?', $member->user_id));
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'));

		// one whose due date has not come ended early, before now
		$early = UserUpgradeExpiredFactory::new()->for($member)->for($upgrade)->create(['start_date' => time() - 86400 * 30]);
		$this->assertGreaterThan(time(), $early->original_end_date);
		$this->assertLessThan(time(), $db->fetchOne('SELECT end_date FROM xf_user_upgrade_expired WHERE user_upgrade_record_id = ?', $early->user_upgrade_record_id));
	}

	public static function ways(): array
	{
		return ['through the entities' => [false], 'through XenForo\'s service' => [true]];
	}

	public function test_a_held_upgrade_cannot_have_run_out_or_be_held_twice()
	{
		$this->recordInto('test-upgrade-refused');
		$member = UserFactory::new()->create(['register_date' => 1600000000]);
		$upgrade = UserUpgradeFactory::new()->lasting(1, 'month')->create();
		$factory = UserUpgradeActiveFactory::new()->for($member)->for($upgrade);

		try
		{
			$factory->create(['start_date' => 1650000000]);
			$this->fail('an upgrade that had run out was created as held');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('UserUpgradeExpiredFactory', $e->getMessage());
		}

		$factory->create();

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('only once');
		$factory->create();
	}

	public function test_the_seeders_run_and_clean_back_to_where_they_started()
	{
		$db = $this->app()->db();
		$tables = ['xf_user', 'xf_user_field', 'xf_user_field_value', 'xf_phrase', 'xf_user_upgrade', 'xf_user_upgrade_active',
			'xf_user_upgrade_expired', 'xf_payment_profile', 'xf_user_group_change', 'xf_user_alert'];
		$before = $this->tableCounts($tables);

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'UserField', '-p' => ['fields=11', 'members=6', 'filled=100'], '--batch' => 'test-field-seeder', '--seed' => '3'], ['capture_stderr_separately' => true]);
		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(11, $created['XF:UserField']);
		$this->assertSame(['id', 'field_id', 'title', 'field_type', 'choices'], array_keys($created['XF:UserField'][0]));
		$this->assertGreaterThanOrEqual(11, count($created['XF:User'][0]['fields']));

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'UserUpgrade', '-p' => ['upgrades=2', 'members=4', 'active=5', 'expired=3'], '--batch' => 'test-upgrade-seeder', '--seed' => '3'], ['capture_stderr_separately' => true]);
		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$this->assertStringNotContainsString('hooks=1', $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(1, $created['XF:PaymentProfile']);
		$this->assertCount(5, $created['XF:UserUpgradeActive']);
		$this->assertCount(3, $created['XF:UserUpgradeExpired']);
		$this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_upgrade_active a JOIN xf_user_upgrade_expired e USING (user_id, user_upgrade_id)'));

		foreach (['test-upgrade-seeder', 'test-field-seeder'] AS $batch)
		{
			$this->assertSame([], (new Cleaner())->clean($batch)['failed']);
		}
		$this->assertSame($before, $this->tableCounts($tables));
	}

	public function test_asking_for_hooks_says_what_that_means_and_too_many_holders_is_refused()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'UserUpgrade', '-p' => ['upgrades=1', 'members=2', 'active=3', 'hooks=1'], '--batch' => 'test-upgrade-many'], ['capture_stderr_separately' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('hooks=1: upgrades go through XenForo\'s upgrade service', $tester->getErrorOutput());
		$this->assertStringContainsString('active cannot be more than members x upgrades', $tester->getErrorOutput());

		(new Cleaner())->clean('test-upgrade-many');
	}

	public function test_purchasable_offers_that_many_of_the_upgrades_and_says_so()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'UserUpgrade', '-p' => ['upgrades=3', 'purchasable=2', 'members=2', 'active=1', 'expired=1'], '--batch' => 'test-upgrade-purchasable'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$this->assertStringContainsString('purchasable: upgrades are offered for sale on this forum', $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertSame([true, true, false], array_column($created['XF:UserUpgrade'], 'can_purchase'));
		$this->assertCount(1, $created['XF:PaymentProfile'], 'one profile between them');

		$this->assertSame([], (new Cleaner())->clean('test-upgrade-purchasable')['failed']);

		$code = $tester->execute(['seeder' => 'UserUpgrade', '-p' => ['upgrades=1', 'purchasable=2'], '--batch' => 'test-upgrade-purchasable-many'], ['capture_stderr_separately' => true]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('purchasable cannot be more than upgrades, which is 1', $tester->getErrorOutput());
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
