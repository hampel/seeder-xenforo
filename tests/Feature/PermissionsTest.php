<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserGroupFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * User groups that grant permissions, and members who hold them, created for real inside a
 * transaction.
 */
class PermissionsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_user', 'xf_user_group', 'xf_user_group_relation', 'xf_permission_entry',
		'xf_permission_combination', 'xf_permission_combination_user_group', 'xf_permission_cache_content'];

	/** @var Context|null */
	private $previous;

	/** @var \Mockery\MockInterface */
	private $styles;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->fakesMail();
		$this->fakesJobs();
		$this->previous = Context::use(null);

		// deleting a group marks every style as changed and empties the cached CSS with
		// a TRUNCATE, which would commit this test's transaction
		$real = $this->app()->repository('XF:Style');
		$this->styles = $this->mockRepository('XF:Style', function ($mock) use ($real)
		{
			$mock->shouldReceive('updateAllStylesLastModifiedDateLater')->andReturnNull();
			foreach (array_diff(get_class_methods($real), ['updateAllStylesLastModifiedDateLater', '__construct']) AS $method)
			{
				$mock->shouldReceive($method)->andReturnUsing([$real, $method]);
			}
		});
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_a_group_grants_flags_and_limits_as_the_control_panel_writes_them()
	{
		$this->recordInto('test-group');

		$group = UserGroupFactory::new()
			->granting(['general' => ['bypassFloodCheck' => 'allow']])
			->granting(['conversation' => ['maxRecipients' => 50], 'general' => ['viewMemberList' => 'deny']])
			->create();

		$this->assertDatabaseHas('xf_permission_entry', ['user_group_id' => $group->user_group_id, 'user_id' => 0,
			'permission_group_id' => 'general', 'permission_id' => 'bypassFloodCheck', 'permission_value' => 'allow']);
		$this->assertDatabaseHas('xf_permission_entry', ['user_group_id' => $group->user_group_id,
			'permission_group_id' => 'conversation', 'permission_id' => 'maxRecipients', 'permission_value' => 'use_int', 'permission_value_int' => 50]);
		$this->assertDatabaseCount('xf_permission_entry', 3, ['user_group_id' => $group->user_group_id]);

		$this->assertSame([
			'conversation.maxRecipients' => 50,
			'general.bypassFloodCheck' => 'allow',
			'general.viewMemberList' => 'deny',
		], UserGroupFactory::describe($group)['permissions']);
		$this->styles->shouldNotHaveReceived('updateAllStylesLastModifiedDateLater');
	}

	public function test_a_permission_the_forum_lacks_or_a_value_it_would_not_take_is_refused()
	{
		foreach ([
			[['general' => ['noSuchPermission' => 'allow']], "no permission 'general.noSuchPermission'"],
			[['nowhere' => ['bypassFloodCheck' => 'allow']], "no permission 'nowhere.bypassFloodCheck'"],
			[['general' => ['bypassFloodCheck' => true]], "takes 'allow' or 'deny'"],
			[['general' => ['bypassFloodCheck' => 'content_allow']], "takes 'allow' or 'deny'"],
			[['conversation' => ['maxRecipients' => 'allow']], 'takes a number'],
			[['general' => 'bypassFloodCheck'], 'is not a list'],
		] AS [$permissions, $message])
		{
			try
			{
				UserGroupFactory::checkPermissions($permissions);
				$this->fail('accepted: ' . json_encode($permissions));
			}
			catch (\InvalidArgumentException $e)
			{
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}

		$this->expectExceptionMessage("no permission 'general.noSuchPermission'");
		UserFactory::new()->withPermissions(['general' => ['noSuchPermission' => 'allow']]);
	}

	public function test_permissions_are_parsed_from_one_line()
	{
		$this->assertSame(
			['general' => ['bypassFloodCheck' => 'allow', 'viewMemberList' => 'deny'], 'conversation' => ['maxRecipients' => -1]],
			UserGroupFactory::parsePermissions(' general.bypassFloodCheck, conversation.maxRecipients:-1,general.viewMemberList:deny,')
		);

		$this->expectExceptionMessage('written as group.permission');
		UserGroupFactory::parsePermissions('bypassFloodCheck');
	}

	public function test_a_member_holds_the_permissions_and_stays_registered()
	{
		$this->recordInto('test-permitted');

		$member = UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'allow'], 'conversation' => ['maxRecipients' => 50]])->create();
		$other = UserFactory::new()->create();

		$fresh = $this->app()->em()->find('XF:User', $member->user_id);
		$this->assertSame(2, $fresh->user_group_id);
		$this->assertCount(1, $fresh->secondary_group_ids);
		$this->assertTrue($fresh->hasPermission('general', 'bypassFloodCheck'));
		$this->assertSame(50, $fresh->hasPermission('conversation', 'maxRecipients'));
		$this->assertNotSame($this->app()->em()->find('XF:User', $other->user_id)->permission_combination_id, $fresh->permission_combination_id);

		$this->assertFalse($this->app()->em()->find('XF:User', $other->user_id)->hasPermission('general', 'bypassFloodCheck'));
		$this->assertContains('permissions', UserFactory::describe($member)['has']);
	}

	public function test_members_given_the_same_permissions_share_one_group_and_make_creates_none()
	{
		$recorder = $this->recordInto('test-permitted-shared');
		$groups = $this->counts()['xf_user_group'];

		UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'allow']])->make();
		$this->assertSame($groups, $this->counts()['xf_user_group'], 'make() saves nothing');

		$same = UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'allow']])->count(3)->create();
		UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'allow']])->create();
		$different = UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'deny']])->create();

		$this->assertSame($groups + 2, $this->counts()['xf_user_group']);
		$this->assertSame([$same->first()->secondary_group_ids], array_values(array_unique(array_map(function ($user)
		{
			return $user->secondary_group_ids;
		}, $same->toArray()), SORT_REGULAR)));
		$this->assertNotSame($same->first()->secondary_group_ids, $different->secondary_group_ids);
		$this->assertCount(2, $recorder->created()['XF:UserGroup']);
	}

	public function test_cleaning_removes_the_members_the_group_its_entries_and_after_xenforo_s_job_the_combinations()
	{
		$before = $this->counts();
		$recorder = $this->recordInto('test-permitted-clean');

		UserFactory::new()->withPermissions(['general' => ['bypassFloodCheck' => 'allow']])->count(2)->create();
		$this->assertSame($before['xf_user_group'] + 1, $this->counts()['xf_user_group']);
		$this->assertGreaterThan($before['xf_permission_combination'], $this->counts()['xf_permission_combination']);

		$this->styles->shouldNotHaveReceived('updateAllStylesLastModifiedDateLater');
		$result = (new Cleaner())->clean('test-permitted-clean');
		$this->assertSame([], $result['failed']);
		$this->styles->shouldHaveReceived('updateAllStylesLastModifiedDateLater');

		// deleting a group queues XenForo's own job, which removes combinations no member has
		$after = $this->counts();
		$this->assertSame($before['xf_user_group'], $after['xf_user_group']);
		$this->assertSame($before['xf_permission_entry'], $after['xf_permission_entry']);
		$this->assertSame($before['xf_user'], $after['xf_user']);

		$this->runJobToCompletion('XF:UserGroupDelete', ['user_group_id' => $recorder->created()['XF:UserGroup'][0]['id']]);
		$this->assertSame($before, $this->counts());
	}

	public function test_the_user_seeder_makes_members_who_hold_the_permissions_named()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => 'User', '--batch' => 'test-seeder-permitted', '--seed' => '7',
			'--param' => ['users=1', 'permitted=2', 'permissions=general.bypassFloodCheck,conversation.maxRecipients:25'],
		]);

		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$this->assertCount(3, $created['XF:User']);
		$this->assertCount(1, $created['XF:UserGroup']);
		$this->assertSame(['conversation.maxRecipients' => 25, 'general.bypassFloodCheck' => 'allow'], $created['XF:UserGroup'][0]['permissions']);

		$holders = array_filter($created['XF:User'], function (array $user)
		{
			return in_array('permissions', $user['has'] ?? [], true);
		});
		$this->assertCount(2, $holders);
		foreach ($holders AS $holder)
		{
			$this->assertTrue($this->app()->em()->find('XF:User', $holder['id'])->hasPermission('general', 'bypassFloodCheck'));
		}
	}

	public function test_the_user_seeder_refuses_permitted_without_permissions_and_names_permissions_without_permitted()
	{
		[$code, , $summary] = $this->run_(['seeder' => 'User', '--batch' => 'test-seeder-permitted-none', '--param' => ['users=0', 'permitted=1']]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString("needs 'permissions'", $summary);

		[$code, , $summary] = $this->run_(['seeder' => 'User', '--batch' => 'test-seeder-permitted-unasked', '--param' => ['users=0', 'permissions=general.bypassFloodCheck']]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('permissions', $summary);
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

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

	private function run_(array $input): array
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute($input, ['decorated' => false, 'capture_stderr_separately' => true]);

		return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
	}
}
