<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\SearchForumFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Forums of each type and search forums, created for real inside a transaction.
 */
class ForumKindsTest extends TestCase
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

	public static function types(): array
	{
		return [
			'article' => ['article', 'article'],
			'question' => ['question', 'question'],
			'suggestion' => ['suggestion', 'suggestion'],
			'discussion' => ['discussion', 'discussion'],
		];
	}

	#[DataProvider('types')]
	public function test_a_typed_forum_has_the_types_settings_and_its_threads_are_of_its_type(string $type, string $threadType)
	{
		$this->recordInto('test-forum-type');

		$node = ForumFactory::new()->type($type)->create();
		$thread = ThreadFactory::new()->for($node)->create();

		$forum = $this->app()->em()->find('XF:Forum', $node->node_id);
		$this->assertSame($type, $forum->forum_type_id);
		$this->assertSame($this->app()->forumType($type)->getDefaultTypeConfig(), $forum->type_config_);
		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'discussion_type' => $threadType, 'discussion_state' => 'visible']);
		$this->assertSame(1, $forum->discussion_count);

		$described = ForumFactory::describe($node);
		$this->assertSame($type === 'discussion' ? null : $type, $described['forum_type_id'] ?? null);
	}

	public function test_the_named_states_are_the_types_and_a_forum_left_alone_is_a_discussion_forum()
	{
		$this->recordInto('test-forum-states');

		$this->assertDatabaseHas('xf_forum', ['node_id' => ForumFactory::new()->create()->node_id, 'forum_type_id' => 'discussion']);
		$this->assertDatabaseHas('xf_forum', ['node_id' => ForumFactory::new()->article()->create()->node_id, 'forum_type_id' => 'article']);
		$this->assertDatabaseHas('xf_forum', ['node_id' => ForumFactory::new()->question()->create()->node_id, 'forum_type_id' => 'question']);
		$this->assertDatabaseHas('xf_forum', ['node_id' => ForumFactory::new()->suggestion()->create()->node_id, 'forum_type_id' => 'suggestion']);
	}

	public function test_a_forum_type_this_forum_does_not_have_is_refused()
	{
		$this->recordInto('test-forum-bad-type');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("'no_such_type' is not a forum type");

		ForumFactory::new()->type('no_such_type')->create();
	}

	public function test_an_attribute_the_node_has_no_column_for_is_set_on_the_forum_row()
	{
		$this->recordInto('test-forum-row');

		$node = ForumFactory::new()->create(['title' => 'Read only', 'allow_posting' => false, 'type_config' => ['allow_downvote' => false], 'forum_type_id' => 'suggestion']);

		$this->assertDatabaseHas('xf_node', ['node_id' => $node->node_id, 'title' => 'Read only']);
		$this->assertDatabaseHas('xf_forum', ['node_id' => $node->node_id, 'allow_posting' => 0]);
		$this->assertSame(['allow_downvote' => false], $this->app()->em()->find('XF:Forum', $node->node_id)->type_config_);
	}

	public function test_a_search_forum_lists_the_visible_threads_of_the_forums_it_searches()
	{
		$this->recordInto('test-search-forum');

		$authors = UserFactory::new()->count(2)->create();
		$node = ForumFactory::new()->create();
		$other = ForumFactory::new()->create();
		$listed = ThreadFactory::new()->count(3)->recycle($authors)->for($node)->create();
		ThreadFactory::new()->recycle($authors)->for($node)->moderated()->create();
		ThreadFactory::new()->recycle($authors)->for($other)->create();

		$started = time();
		$searchNode = SearchForumFactory::new()->searching($node)->create(['title' => 'Seeder search forum', 'sort_order' => 'title', 'sort_direction' => 'asc']);

		$this->assertSame('XF:Node', $searchNode->structure()->shortName);
		$this->assertDatabaseHas('xf_node', ['node_id' => $searchNode->node_id, 'node_type_id' => 'SearchForum', 'title' => 'Seeder search forum']);
		$this->assertDatabaseHas('xf_search_forum', ['node_id' => $searchNode->node_id, 'sort_order' => 'title', 'discussion_count' => 3]);

		$searchForum = $this->app()->em()->find('XF:SearchForum', $searchNode->node_id);
		$this->assertSame(['node_id' => [$node->node_id], 'discussion_state' => 'visible'], $searchForum->search_criteria);

		$expected = $listed->pluckNamed('thread_id');
		sort($expected);
		$cached = $searchForum->Cache->results;
		sort($cached);
		$this->assertSame($expected, $cached);
		$this->assertGreaterThanOrEqual($started, $searchForum->Cache->cache_date, 'results dated at the run\'s start would be stale to a page view');
		$this->assertFalse($searchForum->isCacheRebuildNeeded());

		$this->assertSame(['title' => 'Seeder search forum', 'threads' => 3], SearchForumFactory::describe($searchNode));
	}

	public function test_a_search_forum_takes_node_ids_and_refuses_none()
	{
		$this->recordInto('test-search-forum-ids');
		$one = ForumFactory::new()->create();
		$two = ForumFactory::new()->create();

		$searchNode = SearchForumFactory::new()->searching([$one->node_id, $two])->create();
		$this->assertSame([$one->node_id, $two->node_id], $this->app()->em()->find('XF:SearchForum', $searchNode->node_id)->search_criteria['node_id']);

		$this->expectException(\InvalidArgumentException::class);
		SearchForumFactory::new()->searching([]);
	}

	public function test_cleaning_a_search_forum_leaves_no_node_no_row_and_no_results()
	{
		$tables = ['xf_node', 'xf_forum', 'xf_search_forum', 'xf_search_forum_cache', 'xf_search_forum_cache_user',
			'xf_thread', 'xf_post', 'xf_user', 'xf_moderator_log', 'xf_user_alert', 'xf_permission_cache_content'];
		$before = $this->counts($tables);
		$this->recordInto('test-search-forum-clean');

		$node = ForumFactory::new()->question()->create();
		ThreadFactory::new()->count(2)->for($node)->create();
		$searchNode = SearchForumFactory::new()->searching($node)->create();

		// a member who has looked at it has results of their own
		$searchForum = $this->app()->em()->find('XF:SearchForum', $searchNode->node_id);
		$searchForum->getUserCacheForUser($this->app()->em()->find('XF:User', 1));
		$this->assertDatabaseCount('xf_search_forum_cache_user', 1, ['node_id' => $searchNode->node_id]);
		$this->assertDatabaseCount('xf_search_forum_cache', 1, ['node_id' => $searchNode->node_id]);

		$result = (new Cleaner())->clean('test-search-forum-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame(2, $result['removed']['XF:Node']);
		$this->assertSame($before, $this->counts($tables));
	}

	public function test_the_search_forum_seeder_makes_a_forum_with_threads_and_search_forums_listing_them()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => 'SearchForum', '--batch' => 'test-search-seeder', '--seed' => '5',
			'--param' => ['search_forums=2', 'threads=3', 'replies=1', 'authors=2', 'prefix=0'],
		]);

		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$this->assertCount(3, $created['XF:Node'], 'one forum and two search forums');
		$this->assertCount(3, $created['XF:Thread']);

		$searchForums = array_values(array_filter($created['XF:Node'], function (array $node) { return isset($node['threads']); }));
		$this->assertCount(2, $searchForums);
		$this->assertSame([3, 3], array_column($searchForums, 'threads'));
		$this->assertDatabaseHas('xf_search_forum', ['node_id' => $searchForums[1]['id'], 'sort_order' => 'title', 'sort_direction' => 'asc']);
	}

	public function test_the_search_forum_seeder_refuses_a_forum_that_does_not_exist()
	{
		$this->fakesErrors();

		[$code, , $summary] = $this->run_(['seeder' => 'SearchForum', '--batch' => 'test-search-bad', '--param' => ['forum=99999999']]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('must be the node id of a forum', $summary);
		$this->assertNoExceptionsLogged();
	}

	public function test_the_seeders_make_typed_forums_when_asked()
	{
		[$code, $json, $summary] = $this->run_([
			'seeder' => 'Default', '--batch' => 'test-forum-types', '--seed' => '5',
			'--param' => ['users=2', 'forums=4', 'forum_types=1', 'threads=4', 'replies=0', 'prefix=0'],
		]);

		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$this->assertSame([null, 'article', 'question', 'suggestion'], array_map(function (array $node) { return $node['forum_type_id'] ?? null; }, $created['XF:Node']));
		$perForum = array_count_values(array_column($created['XF:Thread'], 'node_id'));
		$this->assertSame(array_fill_keys(array_column($created['XF:Node'], 'id'), 1), $perForum, 'the threads go to each forum in turn, so none is left empty');

		[$code, $json, $summary] = $this->run_([
			'seeder' => 'Thread', '--batch' => 'test-forum-type', '--seed' => '5',
			'--param' => ['threads=2', 'replies=0', 'authors=1', 'prefix=0', 'forum_type=question'],
		]);

		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$this->assertSame('question', $created['XF:Node'][0]['forum_type_id']);
		$this->assertDatabaseCount('xf_thread', 2, ['node_id' => $created['XF:Node'][0]['id'], 'discussion_type' => 'question']);
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
