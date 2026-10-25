<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Moderators, with the record behind the flag, created for real inside a transaction.
 */
class ModeratorTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_user', 'xf_moderator', 'xf_moderator_content', 'xf_permission_entry', 'xf_permission_entry_content',
		'xf_moderator_log', 'xf_user_alert', 'xf_change_log'];

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

	public function test_a_moderator_is_a_super_moderator_with_the_record_and_no_permissions()
	{
		$this->recordInto('test-moderator');
		$before = $this->counts();

		$member = UserFactory::new()->moderator()->create();

		$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'is_moderator' => 1, 'is_staff' => 1]);
		$this->assertDatabaseHas('xf_moderator', ['user_id' => $member->user_id, 'is_super_moderator' => 1, 'extra_user_group_ids' => '']);
		$this->assertDatabaseCount('xf_moderator_content', 0, ['user_id' => $member->user_id]);
		$this->assertDatabaseCount('xf_permission_entry', 0, ['user_id' => $member->user_id]);
		$this->assertSame($before['xf_moderator_log'], $this->counts()['xf_moderator_log']);
		$this->assertContains('moderator', UserFactory::describe($member)['has']);

		// as the control panel's moderator list finds them
		$found = $this->app()->finder('XF:Moderator')->where('user_id', $member->user_id)->with('User')->fetchOne();
		$this->assertSame($member->username, $found->User->username);
	}

	public function test_a_moderator_of_a_node_moderates_that_node_alone()
	{
		$this->recordInto('test-moderator-node');
		$forum = ForumFactory::new()->create();

		$member = UserFactory::new()->moderator($forum)->create();

		$this->assertDatabaseHas('xf_moderator', ['user_id' => $member->user_id, 'is_super_moderator' => 0]);
		$this->assertDatabaseHas('xf_moderator_content', ['user_id' => $member->user_id, 'content_type' => 'node', 'content_id' => $forum->node_id]);
		$this->assertDatabaseCount('xf_moderator_content', 1, ['user_id' => $member->user_id]);
	}

	public function test_a_moderator_cannot_be_banned_or_bouncing()
	{
		$this->recordInto('test-moderator-refused');

		try
		{
			UserFactory::new()->moderator()->bouncing()->create();
			$this->fail('a bouncing moderator was made');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('cannot be bouncing', $e->getMessage());
		}

		$this->expectExceptionMessage('cannot be banned');
		UserFactory::new()->moderator()->banned()->create();
	}

	public function test_cleaning_a_moderator_leaves_no_record_or_member()
	{
		$this->recordInto('test-moderator-clean');
		$forum = ForumFactory::new()->create();
		$before = $this->counts();

		UserFactory::new()->moderator()->create();
		UserFactory::new()->moderator($forum)->create();
		$this->assertSame($before['xf_moderator'] + 2, $this->counts()['xf_moderator']);

		$result = (new Cleaner())->clean('test-moderator-clean');

		$this->assertSame([], $result['failed']);
		$after = $this->counts();
		$this->assertSame($before['xf_moderator'], $after['xf_moderator']);
		$this->assertSame($before['xf_moderator_content'], $after['xf_moderator_content']);
		$this->assertSame($before['xf_permission_entry'], $after['xf_permission_entry']);
		$this->assertSame($before['xf_user'], $after['xf_user']);
	}

	public function test_the_moderators_seeder_makes_are_listed_as_moderators()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'User', '--batch' => 'test-user-moderators', '--param' => ['users=1', 'banned=2', 'moderators=2']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$members = json_decode($tester->getDisplay(), true)['created']['XF:User'];
		$ids = implode(',', array_column($members, 'id'));
		$db = $this->app()->db();

		// two asked for and the one who banned the others, each with the record behind the flag
		$this->assertSame(3, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_user WHERE is_moderator = 1 AND user_id IN ({$ids})"));
		$this->assertSame(3, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_moderator WHERE user_id IN ({$ids})"));
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
}
