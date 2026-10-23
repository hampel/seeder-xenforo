<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Threads with a poll and sticky threads, created for real inside a transaction.
 */
class PollsAndStickyTest extends TestCase
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

		parent::tearDown();
	}

	public function test_a_thread_with_a_poll_is_a_poll_thread_with_its_choices_and_no_votes()
	{
		$this->recordInto('test-poll');

		$thread = ThreadFactory::new()->withPoll(3)->create();

		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'discussion_type' => 'poll']);
		$poll = $this->app()->em()->findOne('XF:Poll', ['content_type' => 'thread', 'content_id' => $thread->thread_id]);
		$this->assertNotNull($poll);
		$this->assertNotSame('', $poll->question);
		$this->assertSame(1, $poll->max_votes);
		$this->assertSame(0, $poll->voter_count);
		$this->assertDatabaseCount('xf_poll_response', 3, ['poll_id' => $poll->poll_id]);
		$this->assertCount(3, $poll->Responses);
		$this->assertDatabaseCount('xf_poll_vote', 0, ['poll_id' => $poll->poll_id]);

		$described = ThreadFactory::describe($thread);
		$this->assertSame($poll->poll_id, $described['poll_id']);
		$this->assertSame(0, $described['poll_voters']);
	}

	public function test_votes_come_from_the_recycled_members_one_each_and_the_totals_agree()
	{
		$this->recordInto('test-poll-votes');

		$members = UserFactory::new()->count(4)->create();
		$thread = ThreadFactory::new()->recycle($members)->withPoll(4, 4)->create();

		$poll = $this->app()->em()->findOne('XF:Poll', ['content_type' => 'thread', 'content_id' => $thread->thread_id]);
		$votes = $this->app()->db()->fetchAll('SELECT * FROM xf_poll_vote WHERE poll_id = ?', $poll->poll_id);

		$this->assertGreaterThanOrEqual(1, count($votes));
		$this->assertLessThanOrEqual(4, count($votes));
		$this->assertSame(count($votes), count(array_unique(array_column($votes, 'user_id'))), 'one vote a member');
		$this->assertSame([], array_diff(array_column($votes, 'user_id'), $members->pluckNamed('user_id')));
		$this->assertSame(count($votes), $poll->voter_count);
		$this->assertSame(count($votes), (int) $this->app()->db()->fetchOne(
			'SELECT SUM(response_vote_count) FROM xf_poll_response WHERE poll_id = ?', $poll->poll_id
		));
		$this->assertSame(count($votes), array_sum(array_column($poll->responses, 'response_vote_count')), 'the cached results');

		foreach ($votes AS $vote)
		{
			$this->assertGreaterThanOrEqual($thread->post_date, $vote['vote_date']);
			$this->assertLessThanOrEqual(time(), $vote['vote_date']);
		}
	}

	public function test_voted_by_all_has_every_recycled_member_vote_once()
	{
		$this->recordInto('test-poll-every');

		$members = UserFactory::new()->count(5)->create();
		$thread = ThreadFactory::new()->recycle($members)->withPoll(3)->votedByAll()->create(['user_id' => $members->first()->user_id]);

		$poll = $this->app()->em()->findOne('XF:Poll', ['content_type' => 'thread', 'content_id' => $thread->thread_id]);
		$voters = array_map('intval', $this->app()->db()->fetchAllColumn('SELECT user_id FROM xf_poll_vote WHERE poll_id = ?', $poll->poll_id));

		$this->assertEqualsCanonicalizing($members->pluckNamed('user_id'), $voters);
		$this->assertSame(5, $poll->voter_count);
	}

	public function test_a_poll_is_refused_in_a_forum_that_takes_no_poll_threads_and_with_one_choice()
	{
		$this->recordInto('test-poll-refused');
		$article = ForumFactory::new()->article()->create();

		try
		{
			ThreadFactory::new()->for($article)->withPoll()->create();
			$this->fail('a poll in an article forum was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('does not take poll threads', $e->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		ThreadFactory::new()->withPoll(1);
	}

	public function test_a_forum_takes_the_thread_types_it_is_told_to_and_only_a_discussion_forum_can()
	{
		$this->recordInto('test-forum-allowing');

		$plain = $this->app()->em()->find('XF:Forum', ForumFactory::new()->create()->node_id);
		$this->assertFalse($plain->TypeHandler->isThreadTypeAllowed('poll', $plain), 'as a new forum in XenForo');

		$node = ForumFactory::new()->allowing('poll')->allowing('question')->create();
		$forum = $this->app()->em()->find('XF:Forum', $node->node_id);
		$this->assertSame('discussion', $forum->forum_type_id);
		$this->assertSame(['poll', 'question'], $forum->type_config['allowed_thread_types']);
		$this->assertTrue($forum->TypeHandler->isThreadTypeAllowed('poll', $forum));
		$this->assertSame(['poll', 'question'], ForumFactory::describe($node)['allowed_thread_types']);

		// a poll thread in a forum given to the factory, which takes them
		$thread = ThreadFactory::new()->for($node)->withPoll()->create();
		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'node_id' => $node->node_id, 'discussion_type' => 'poll']);

		try
		{
			ForumFactory::new()->allowing('nonsense')->create();
			$this->fail('a thread type that does not exist was accepted');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertStringContainsString('is not a thread type', $e->getMessage());
		}

		$this->expectExceptionMessage('Only a discussion forum');
		ForumFactory::new()->article()->allowing('poll')->create();
	}

	public function test_a_poll_is_refused_in_a_forum_given_to_it_that_takes_none()
	{
		$this->recordInto('test-poll-plain');
		$node = ForumFactory::new()->create();

		$this->expectExceptionMessage('does not take poll threads');
		ThreadFactory::new()->for($node)->withPoll()->create();
	}

	public function test_cleaning_a_poll_thread_leaves_no_poll_choice_or_vote()
	{
		$tables = ['xf_thread', 'xf_post', 'xf_poll', 'xf_poll_response', 'xf_poll_vote', 'xf_user', 'xf_node',
			'xf_moderator_log', 'xf_user_alert'];
		$before = $this->counts($tables);
		$this->recordInto('test-poll-clean');

		$members = UserFactory::new()->count(3)->create();
		ThreadFactory::new()->recycle($members)->withPoll(3, 3)->create();
		$this->assertSame($before['xf_poll'] + 1, $this->counts(['xf_poll'])['xf_poll']);

		$result = (new Cleaner())->clean('test-poll-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->counts($tables));
	}

	public function test_the_thread_seeder_makes_sticky_threads_and_polls_when_asked()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => 'Thread', '--batch' => 'test-sticky-polls', '--seed' => '7',
			'--param' => ['threads=6', 'replies=1', 'authors=3', 'prefix=0', 'sticky=2', 'polls=2'],
		]);

		$this->assertSame(0, $code, $summary);
		$threads = json_decode($json, true)['created']['XF:Thread'];
		$this->assertCount(6, $threads);
		$this->assertCount(2, array_filter(array_column($threads, 'sticky')));
		$this->assertCount(2, array_filter(array_column($threads, 'poll_id')));

		$nodeId = $threads[0]['node_id'];
		$this->assertDatabaseCount('xf_thread', 2, ['node_id' => $nodeId, 'sticky' => 1]);
		$this->assertDatabaseCount('xf_thread', 2, ['node_id' => $nodeId, 'discussion_type' => 'poll']);
	}

	public function test_the_seeders_give_polls_only_to_forums_that_take_them()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => 'Thread', '--batch' => 'test-polls-article', '--seed' => '7',
			'--param' => ['threads=3', 'replies=0', 'authors=2', 'prefix=0', 'polls=2', 'forum_type=article'],
		]);
		$this->assertSame(0, $code, $summary);
		$this->assertSame([], array_column(json_decode($json, true)['created']['XF:Thread'], 'poll_id'));

		[$code, $json, $summary] = $this->run_([
			'seeder' => 'Default', '--batch' => 'test-polls-types', '--seed' => '7',
			'--param' => ['users=3', 'forums=4', 'forum_types=1', 'threads=8', 'replies=0', 'prefix=0', 'polls=2'],
		]);
		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$discussion = array_values(array_filter($created['XF:Node'], function (array $node) { return !isset($node['forum_type_id']); }));
		$withPolls = array_values(array_filter($created['XF:Thread'], function (array $thread) { return isset($thread['poll_id']); }));

		$this->assertCount(2, $withPolls);
		$this->assertSame([$discussion[0]['id']], array_values(array_unique(array_column($withPolls, 'node_id'))));
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

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

	private function run_(array $input): array
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute($input, ['decorated' => false, 'capture_stderr_separately' => true]);

		return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
	}
}
