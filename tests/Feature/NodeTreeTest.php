<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\CategoryFactory;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\SearchForumFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Categories, forums inside them and forums inside those, made in one run inside a transaction.
 */
class NodeTreeTest extends TestCase
{
	use UsesDatabaseTransactions;

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

	public function test_a_category_a_forum_in_it_and_a_forum_in_that_are_made_in_one_run()
	{
		$this->recordInto('test-tree');

		$category = CategoryFactory::new()->create(['title' => 'Seeded category']);
		$forum = ForumFactory::new()->in($category)->create();
		$sub = ForumFactory::new()->in($forum)->create();

		$rows = [];
		foreach ([$category, $forum, $sub] AS $node)
		{
			$rows[] = $this->app()->db()->fetchRow('SELECT * FROM xf_node WHERE node_id = ?', $node->node_id);
		}
		[$c, $f, $s] = $rows;

		$this->assertSame(['Category', 'Forum', 'Forum'], array_column($rows, 'node_type_id'));
		$this->assertSame([0, $category->node_id, $forum->node_id], array_map('intval', array_column($rows, 'parent_node_id')));
		$this->assertSame([0, 1, 2], array_map('intval', array_column($rows, 'depth')));
		// nested as the tree is: each inside the one above it
		$this->assertTrue($c['lft'] < $f['lft'] && $f['lft'] < $s['lft'] && $s['rgt'] < $f['rgt'] && $f['rgt'] < $c['rgt']);
		$this->assertDatabaseHas('xf_category', ['node_id' => $category->node_id]);

		// and the entities a run goes on to use know it too
		$this->assertSame((int) $s['lft'], $sub->lft);
		$this->assertSame([$category->node_id, $forum->node_id], array_map('intval', array_keys($sub->breadcrumb_data)));
		$this->assertSame(['title' => 'Seeded category', 'node_type_id' => 'Category'], CategoryFactory::describe($category));
		$this->assertSame($category->node_id, ForumFactory::describe($forum)['parent_node_id']);
	}

	public function test_threads_go_into_a_sub_forum_and_a_search_forum_into_a_category()
	{
		$this->recordInto('test-tree-content');
		$category = CategoryFactory::new()->create();
		$sub = ForumFactory::new()->in(ForumFactory::new()->in($category)->create())->create();

		$thread = ThreadFactory::new()->for($sub)->create();
		$search = SearchForumFactory::new()->in($category)->searching([$sub])->create();

		$this->assertSame($sub->node_id, $thread->node_id);
		// new forums reach a guest's permissions through a queued rebuild, which this test fakes
		$guest = $this->app()->repository('XF:User')->getGuestUser();
		$this->app()->permissionBuilder()->rebuildCombination($this->app()->em()->find('XF:PermissionCombination', $guest->permission_combination_id));
		$this->assertTrue(\XF::asVisitor($guest, function () use ($thread)
		{
			return $thread->canView();
		}), 'a guest sees a thread in a sub-forum');
		$this->assertSame($category->node_id, (int) $this->app()->db()->fetchOne('SELECT parent_node_id FROM xf_node WHERE node_id = ?', $search->node_id));
	}

	public function test_in_takes_a_category_or_forum_row_and_refuses_an_unsaved_node()
	{
		$this->recordInto('test-tree-in');
		$category = CategoryFactory::new()->create();

		$forum = ForumFactory::new()->in($category->getData())->create();
		$this->assertSame($category->node_id, $forum->parent_node_id, 'the category row stands for its node');

		$this->expectExceptionMessage('inside a saved category, forum or other node');
		ForumFactory::new()->in($this->app()->em()->create('XF:Node'));
	}

	public function test_cleaning_removes_the_tree_and_moves_up_a_forum_the_run_did_not_make()
	{
		$db = $this->app()->db();
		$before = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_node');
		$this->recordInto('test-tree-clean');
		$category = CategoryFactory::new()->create();
		ForumFactory::new()->in(ForumFactory::new()->in($category)->create())->count(2)->create();

		// someone else's forum, put in the seeded category while the batch exists
		Context::use(new Context(Context::faker('en_US', 2), new NullRecorder(), Timeline::span('90d'), 'password'));
		$theirs = ForumFactory::new()->in($category)->create();

		$this->assertSame([], (new Cleaner())->clean('test-tree-clean')['failed']);

		$this->assertSame($before + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_node'), 'only their forum is left');
		$this->assertSame(0, (int) $db->fetchOne('SELECT parent_node_id FROM xf_node WHERE node_id = ?', $theirs->node_id), 'moved up to the category\'s parent');
	}

	public function test_the_default_seeder_makes_categories_forums_and_sub_forums_with_threads_in_them()
	{
		[$code, $json, $summary] = $this->run_(['seeder' => 'Default', '--batch' => 'test-tree-default', '--seed' => '3',
			'--param' => ['users=4', 'forums=4', 'categories=2', 'subforums=2', 'threads=16', 'replies=0', 'prefix=0']]);
		$this->assertSame(0, $code, $summary);

		$db = $this->app()->db();
		$nodes = json_decode($json, true)['created']['XF:Node'];
		$byId = array_column($nodes, null, 'id');
		$categories = array_keys(array_filter($byId, function ($node) { return ($node['node_type_id'] ?? '') === 'Category'; }));
		$this->assertCount(2, $categories);

		$top = array_filter($byId, function ($node) use ($categories) { return in_array($node['parent_node_id'] ?? 0, $categories, true); });
		$this->assertCount(4, $top, 'every forum in a category');
		$this->assertCount(2, array_unique(array_column($top, 'parent_node_id')), 'both categories used');

		$subs = array_filter($byId, function ($node) use ($top) { return isset($top[$node['parent_node_id'] ?? 0]); });
		foreach (array_keys($top) AS $forumId)
		{
			$inside = count(array_filter($subs, function ($node) use ($forumId) { return $node['parent_node_id'] === $forumId; }));
			$this->assertTrue($inside >= 1 && $inside <= 2, "forum {$forumId} has {$inside} sub-forums");
		}

		$threadNodes = array_unique(array_map('intval', $db->fetchAllColumn(
			'SELECT node_id FROM xf_thread WHERE thread_id IN (' . $db->quote(array_column(json_decode($json, true)['created']['XF:Thread'], 'id')) . ')'
		)));
		$this->assertNotEmpty(array_intersect($threadNodes, array_keys($subs)), 'threads in the sub-forums');
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
