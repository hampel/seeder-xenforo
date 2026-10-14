<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\AbstractBulkFactory;
use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\ModeratorLogFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\Util\Ip;

/**
 * Moderator log entries, written as rows inside a transaction.
 */
class ModeratorLogTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var Context|null */
	private $previous;

	/** @var int the highest id in the log before the test wrote any */
	private $before = 0;

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

	public function test_entries_are_in_xenforos_words_about_the_content_as_its_handlers_record_it()
	{
		$this->recordInto('test-modlog');
		[$moderator, $content] = $this->scene();

		$recorder = Context::current()->recorder;
		ModeratorLogFactory::new()->for(...$content)->by($moderator)->count(40)->create();

		$rows = $this->rows();
		$this->assertGreaterThanOrEqual(40, count($rows));
		$this->assertLessThanOrEqual(41, count($rows), 'an action and its undoing may take it one over');

		$problems = [];
		$latest = time() - ErrorLogFactory::quiet();
		foreach ($rows AS $row)
		{
			$key = "mod_log.{$row['content_type']}_{$row['action']}";
			$phrase = \XF::phrase($key)->render('raw');
			if ($phrase === $key) { $problems[] = "{$key} is not one of XenForo's"; }
			preg_match_all('/\{(\w+)\}/', $phrase, $placeholders);
			$params = json_decode($row['action_params'], true);
			if (array_diff($placeholders[1], array_keys($params))) { $problems[] = "{$key} lacks a parameter"; }

			$entity = $this->app()->em()->find(['thread' => 'XF:Thread', 'post' => 'XF:Post', 'user' => 'XF:User'][$row['content_type']], $row['content_id']);
			$since = $row['content_type'] === 'user' ? $entity->register_date : $entity->post_date;
			if ($row['log_date'] < $since || $row['log_date'] > $latest) { $problems[] = "{$key} dated {$row['log_date']}"; }
			if ((int) $row['content_user_id'] !== $entity->user_id || $row['content_username'] !== $entity->username) { $problems[] = "{$key} names the wrong author"; }
			switch ($row['content_type'])
			{
				case 'thread': $title = $entity->title; break;
				case 'post': $title = $entity->Thread ? $entity->Thread->title : ''; break;
				default: $title = $entity->username;
			}
			if ($row['content_title'] !== $title) { $problems[] = "{$key} has the wrong title"; }
			if ($row['discussion_content_type'] !== ($row['content_type'] === 'user' ? 'user' : 'thread')) { $problems[] = "{$key} in the wrong discussion"; }
			if (!preg_match('/^(192\.0\.2|198\.51\.100|203\.0\.113)\.|^2001:db8:/', Ip::binaryToString($row['ip_address']))) { $problems[] = 'address'; }
		}
		$this->assertSame([], $problems);
		$this->assertSame(count($rows), $recorder->counts()['XF:ModeratorLog'], 'every row recorded');
		$this->assertEqualsCanonicalizing(['thread', 'post', 'user'], array_values(array_unique(array_column($rows, 'content_type'))));
	}

	public function test_the_history_agrees_with_the_content_as_it_is_now()
	{
		$this->recordInto('test-modlog-history');
		$moderator = ModeratorLogFactory::moderator()->create();
		$author = UserFactory::new()->create(['register_date' => time() - 80 * 86400]);
		$forum = ForumFactory::new()->create();
		$open = ThreadFactory::new()->for($forum)->create(['user_id' => $author->user_id, 'post_date' => time() - 60 * 86400]);
		$locked = ThreadFactory::new()->for($forum)->locked()->sticky()->create(['user_id' => $author->user_id, 'post_date' => time() - 60 * 86400]);

		ModeratorLogFactory::new()->for($open, $locked)->by($moderator)->count(120)->create();

		foreach ([[$open, 'unlock', 'unstick', 'undelete'], [$locked, 'lock', 'stick', 'undelete']] AS [$thread, $lock, $stick, $delete])
		{
			$actions = $this->app()->db()->fetchAllColumn(
				"SELECT action FROM xf_moderator_log WHERE content_type = 'thread' AND content_id = ? ORDER BY log_date, moderator_log_id", $thread->thread_id
			);
			$last = function (array $pair) use ($actions)
			{
				$matching = array_values(array_intersect($actions, $pair));

				return $matching ? end($matching) : null;
			};
			$this->assertSame($lock, $last(['lock', 'unlock']), 'locked or not as it is now');
			$this->assertSame($stick, $last(['stick', 'unstick']), 'stuck or not as it is now');
			$this->assertSame($delete, $last(['delete_soft', 'undelete']), 'visible, so any removal was undone');
		}
	}

	public function test_nothing_that_reacts_to_a_moderator_action_sees_one()
	{
		$this->recordInto('test-modlog-quiet');
		[$moderator, $content] = $this->scene();

		$saved = 0;
		$this->app()->extension()->addListener('entity_post_save', function () use (&$saved) { $saved++; }, 'XF\Entity\ModeratorLog');
		$logger = \Mockery::mock($this->app()->logger())->makePartial();
		$logger->shouldNotReceive('logModeratorAction', 'logModeratorChange', 'logModeratorChanges');
		$this->app()->offsetSet('logger', $logger);

		ModeratorLogFactory::new()->for(...$content)->by($moderator)->count(20)->create();

		$this->assertGreaterThanOrEqual(20, count($this->rows()));
		$this->assertSame(0, $saved, 'no entity was saved');
	}

	public function test_cleaning_removes_its_rows_and_never_anyone_elses()
	{
		$db = $this->app()->db();
		$before = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_moderator_log');
		$this->recordInto('test-modlog-clean');
		[$moderator, $content] = $this->scene();

		ModeratorLogFactory::new()->for(...$content)->by($moderator)->count(10)->create();
		// a real moderator acting while the batch exists
		$db->insert('xf_moderator_log', ['log_date' => time(), 'user_id' => 1, 'ip_address' => '', 'content_type' => 'thread', 'content_id' => 1,
			'content_user_id' => 1, 'content_username' => 'Someone', 'content_title' => 'A real thread', 'content_url' => 'threads/1/',
			'discussion_content_type' => 'thread', 'discussion_content_id' => 1, 'action' => 'edit', 'action_params' => '[]']);
		$real = (int) $db->lastInsertId();

		$this->assertSame([], (new Cleaner())->clean('test-modlog-clean')['failed']);

		$this->assertSame($before + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_moderator_log'));
		$this->assertDatabaseHas('xf_moderator_log', ['moderator_log_id' => $real]);
	}

	public function test_a_table_with_a_column_no_row_could_fill_is_refused()
	{
		$method = new \ReflectionMethod(AbstractBulkFactory::class, 'assertWritable');
		$columns = array_diff(array_column($this->app()->db()->fetchAll('SHOW COLUMNS FROM xf_moderator_log'), 'Field'), ['content_type']);

		$method->invoke(null, 'xf_moderator_log', array_merge($columns, ['content_type']));

		$checked = new \ReflectionProperty(AbstractBulkFactory::class, 'checked');
		$checked->setValue(null, []);
		$this->expectExceptionMessage("the column 'content_type', which has no default");
		$method->invoke(null, 'xf_moderator_log', $columns);
	}

	public function test_it_is_about_a_thread_a_post_or_a_member_and_needs_one()
	{
		$this->recordInto('test-modlog-refused');
		try
		{
			ModeratorLogFactory::new()->for(ForumFactory::new()->create());
			$this->fail('a forum accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('a thread, a post or a member', $e->getMessage());
		}

		$this->expectExceptionMessage('name it with for()');
		ModeratorLogFactory::new()->create();
	}

	public function test_the_seeder_makes_moderators_members_content_and_the_log()
	{
		$this->before = (int) $this->app()->db()->fetchOne('SELECT COALESCE(MAX(moderator_log_id), 0) FROM xf_moderator_log');
		[$code, $json, $summary] = $this->run_(['seeder' => 'ModeratorLog', '--batch' => 'test-modlog-seeder', '--seed' => '2',
			'--param' => ['entries=25', 'moderators=2', 'members=3', 'threads=3', 'replies=2']]);
		$this->assertSame(0, $code, $summary);

		$result = json_decode($json, true);
		$this->assertGreaterThanOrEqual(25, $result['counts']['XF:ModeratorLog']);
		$this->assertCount(5, $result['created']['XF:User']);
		$this->assertCount(3, $result['created']['XF:Thread']);
		$moderators = $this->app()->db()->fetchAllColumn('SELECT DISTINCT user_id FROM xf_moderator_log WHERE moderator_log_id > ?', $this->before);
		$this->assertCount(2, $moderators);
		foreach ($moderators AS $userId)
		{
			$this->assertDatabaseHas('xf_user', ['user_id' => $userId, 'is_moderator' => 1]);
		}
	}

	/**
	 * A moderator, and members with threads and replies old enough to have a history.
	 */
	private function scene(): array
	{
		$moderator = ModeratorLogFactory::moderator()->create();
		$members = UserFactory::new()->count(3)->create(['register_date' => time() - 80 * 86400]);
		$threads = ThreadFactory::new()->for(ForumFactory::new()->create())->recycle($members)->count(3)
			->state(function () { return ['post_date' => time() - mt_rand(20, 60) * 86400]; })
			->create();

		$content = array_merge(array_values($members->toArray()), array_values($threads->toArray()));
		foreach ($threads AS $thread)
		{
			$content[] = PostFactory::new()->for($thread)->create(['user_id' => $thread->user_id, 'post_date' => $thread->post_date + 3600]);
		}

		return [$moderator, $content];
	}

	private function rows(): array
	{
		return $this->app()->db()->fetchAll('SELECT * FROM xf_moderator_log WHERE moderator_log_id > ?', $this->before);
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		$this->before = (int) $this->app()->db()->fetchOne('SELECT COALESCE(MAX(moderator_log_id), 0) FROM xf_moderator_log');
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
