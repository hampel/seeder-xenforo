<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserFieldFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * A member's history: the change log, username changes and a rejected registration, created for
 * real inside a transaction.
 */
class MemberHistoryTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_user', 'xf_change_log', 'xf_username_change', 'xf_user_reject', 'xf_approval_queue', 'xf_admin'];

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

		parent::tearDown();
	}

	public function test_changes_are_logged_by_the_member_and_an_administrator_and_each_follows_the_last()
	{
		$this->recordInto('test-history-changes');
		$admin = UserFactory::new()->administrator()->create(['register_date' => time() - 200 * 86400]);
		UserFieldFactory::new()->create();

		$members = UserFactory::new()->withChanges(6, $admin)->count(5)->create();

		$editors = [];
		$problems = [];
		foreach ($members AS $member)
		{
			$rows = $this->app()->db()->fetchAll(
				"SELECT * FROM xf_change_log WHERE content_type = 'user' AND content_id = ? ORDER BY edit_date, log_id", $member->user_id
			);
			if (!$rows) { $problems[] = "member {$member->user_id} has no rows"; }

			$last = [];
			foreach ($rows AS $row)
			{
				$editors[(int) $row['edit_user_id']] = true;
				if (!in_array((int) $row['edit_user_id'], [$member->user_id, $admin->user_id], true)) { $problems[] = "edited by {$row['edit_user_id']}"; }
				if ($row['edit_date'] < $member->register_date || $row['edit_date'] > max($member->register_date, min($member->last_activity, time()))) { $problems[] = "dated {$row['edit_date']}"; }
				if (isset($last[$row['field']]) && $last[$row['field']] !== $row['old_value']) { $problems[] = "{$row['field']} does not follow on"; }
				$last[$row['field']] = $row['new_value'];
			}

			// the member ends with what the last change of each field left
			$fresh = $this->app()->em()->find('XF:User', $member->user_id);
			foreach (['email' => $fresh->email, 'timezone' => $fresh->timezone, 'custom_title' => $fresh->custom_title,
				'location' => $fresh->Profile->location, 'signature' => $fresh->Profile->signature] AS $field => $value)
			{
				if (isset($last[$field]) && $last[$field] !== (string) $value) { $problems[] = "{$field} is not its last value"; }
			}
		}

		$this->assertSame([], $problems);
		// each at the moment it was made, not all at the moment the run was
		$dates = $this->app()->db()->fetchAllColumn(
			"SELECT edit_date FROM xf_change_log WHERE content_type = 'user' AND content_id IN (" . $this->app()->db()->quote(array_map(function ($member) { return $member->user_id; }, array_values($members->toArray()))) . ')'
		);
		$this->assertGreaterThan(count($dates) / 2, count(array_unique($dates)), 'the changes are spread over time');
		$this->assertArrayHasKey($admin->user_id, $editors, 'an administrator changed something');
		$this->assertGreaterThan(1, count($editors), 'and members did');
		$this->assertNoMailSent();
	}

	public function test_a_renamed_member_has_a_history_of_names_that_ends_at_their_own()
	{
		$this->recordInto('test-history-renamed');

		$member = UserFactory::new()->renamed(3)->create();

		$rows = $this->app()->db()->fetchAll('SELECT * FROM xf_username_change WHERE user_id = ? ORDER BY change_date, change_id', $member->user_id);
		$this->assertCount(3, $rows);
		$this->assertSame(['approved'], array_values(array_unique(array_column($rows, 'change_state'))));
		$this->assertSame($member->username, end($rows)['new_username']);
		for ($i = 1; $i < 3; $i++)
		{
			$this->assertSame($rows[$i - 1]['new_username'], $rows[$i]['old_username']);
		}
		$this->assertNotSame($member->username, $rows[0]['old_username']);
		$this->assertSame(1, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user WHERE username = ?', $member->username), 'not renamed');

		$fresh = $this->app()->em()->find('XF:User', $member->user_id);
		$this->assertSame((int) end($rows)['change_date'], $fresh->username_date, 'the last change');
		$this->assertSame((int) end($rows)['change_date'], $fresh->username_date_visible, 'the last change members can see');
	}

	public function test_a_username_request_waits_for_approval_or_was_rejected()
	{
		$this->recordInto('test-history-requests');

		$pending = UserFactory::new()->renamed()->withUsernameRequest()->create();
		$rejected = UserFactory::new()->withUsernameRequest('rejected')->create();

		$waiting = $this->app()->db()->fetchRow("SELECT * FROM xf_username_change WHERE user_id = ? AND change_state = 'moderated'", $pending->user_id);
		$this->assertSame($pending->username, $waiting['old_username']);
		$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'username_change', 'content_id' => $waiting['change_id']]);
		$this->assertGreaterThanOrEqual(
			(int) $this->app()->db()->fetchOne("SELECT change_date FROM xf_username_change WHERE user_id = ? AND change_state = 'approved'", $pending->user_id),
			(int) $waiting['change_date'],
			'asked for after the change before it'
		);

		$refused = $this->app()->db()->fetchRow('SELECT * FROM xf_username_change WHERE user_id = ?', $rejected->user_id);
		$this->assertSame('rejected', $refused['change_state']);
		$this->assertNotSame(0, (int) $refused['moderator_user_id']);
		$this->assertNotSame('', $refused['reject_reason']);
		$this->assertDatabaseMissing('xf_approval_queue', ['content_type' => 'username_change', 'content_id' => $refused['change_id']]);

		$this->expectExceptionMessage("'moderated' or 'rejected'");
		UserFactory::new()->withUsernameRequest('approved');
	}

	public function test_a_registration_rejected_by_the_spam_check_or_by_a_moderator()
	{
		$this->recordInto('test-history-rejected');
		$moderator = UserFactory::new()->state(['is_moderator' => true, 'is_staff' => true])->create();

		$bySpamCheck = UserFactory::new()->rejected()->create();
		$byModerator = UserFactory::new()->rejected(function () use ($moderator) { return $moderator; })->create();

		$this->assertDatabaseHas('xf_user', ['user_id' => $bySpamCheck->user_id, 'user_state' => 'rejected']);
		$this->assertDatabaseHas('xf_user_reject', [
			'user_id' => $bySpamCheck->user_id, 'reject_user_id' => 0, 'reject_date' => $bySpamCheck->register_date,
			'reject_reason' => \XF::phrase('spam_prevention_registration_rejected')->render(),
		]);

		$this->assertDatabaseHas('xf_user', ['user_id' => $byModerator->user_id, 'user_state' => 'rejected']);
		$record = $this->app()->db()->fetchRow('SELECT * FROM xf_user_reject WHERE user_id = ?', $byModerator->user_id);
		$this->assertSame($moderator->user_id, (int) $record['reject_user_id']);
		$this->assertNotSame('', $record['reject_reason']);
		$this->assertGreaterThanOrEqual(max($byModerator->register_date, $moderator->register_date), (int) $record['reject_date']);
		$this->assertDatabaseHas('xf_change_log', [
			'content_type' => 'user', 'content_id' => $byModerator->user_id, 'field' => 'user_state',
			'old_value' => 'moderated', 'new_value' => 'rejected', 'edit_user_id' => $moderator->user_id, 'edit_date' => (int) $record['reject_date'],
		]);

		foreach ([$bySpamCheck, $byModerator] AS $member)
		{
			$this->assertDatabaseMissing('xf_approval_queue', ['content_type' => 'user', 'content_id' => $member->user_id]);
		}
		$this->assertNoMailSent();
	}

	public function test_cleaning_removes_every_row_of_the_history()
	{
		$before = $this->counts();
		$this->recordInto('test-history-clean');

		UserFactory::new()->withChanges(4)->renamed(2)->withUsernameRequest()->count(2)->create();
		UserFactory::new()->renamed()->withUsernameRequest('rejected')->create();
		UserFactory::new()->rejected()->create();
		$this->assertNotSame($before, $this->counts());

		$this->assertSame([], (new Cleaner())->clean('test-history-clean')['failed']);

		$this->assertSame($before, $this->counts());
	}

	public function test_the_user_seeder_makes_the_history_asked_for()
	{
		$db = $this->app()->db();
		[$code, $json, $summary] = $this->run_(['seeder' => 'User', '--batch' => 'test-history-seeder', '--seed' => '4',
			'--param' => ['users=0', 'changes=2', 'renamed=3', 'rejected=2']]);
		$this->assertSame(0, $code, $summary);

		$ids = array_column(json_decode($json, true)['created']['XF:User'], 'id');
		$in = $db->quote($ids);
		$states = $db->fetchPairs("SELECT change_state, COUNT(*) FROM xf_username_change WHERE user_id IN ({$in}) GROUP BY change_state");
		ksort($states);
		$this->assertSame(['approved' => 4, 'moderated' => 1, 'rejected' => 1], $states);
		$this->assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user WHERE user_id IN ({$in}) AND user_state = 'rejected'"));
		$this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user_reject WHERE user_id IN ({$in}) AND reject_user_id = 0"));
		$this->assertGreaterThan(0, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_change_log WHERE content_type = 'user' AND content_id IN ({$in})"));

		foreach (['changes=0', 'renamed=0'] AS $refused)
		{
			// a count of none is no members, not an error
			[$code] = $this->run_(['seeder' => 'User', '--batch' => 'test-history-none', '--param' => ['users=0', $refused]]);
			$this->assertSame(0, $code);
		}
	}

	public function test_nonsense_is_refused()
	{
		foreach ([
			function () { UserFactory::new()->withChanges(0); },
			function () { UserFactory::new()->renamed(0); },
		] AS $call)
		{
			try
			{
				$call();
				$this->fail('accepted');
			}
			catch (\InvalidArgumentException $e)
			{
				$this->assertStringContainsString('at least once', str_replace('at least one', 'at least once', $e->getMessage()));
			}
		}
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

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}

	private function run_(array $input): array
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute($input, ['decorated' => false, 'capture_stderr_separately' => true]);

		return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
	}
}
