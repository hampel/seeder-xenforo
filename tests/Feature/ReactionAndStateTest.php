<?php namespace Tests\Feature;

use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ReactionFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Seed\ThreadSeeder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Reactions, and content that is awaiting approval or soft-deleted, created for real inside a
 * transaction.
 */
class ReactionAndStateTest extends TestCase
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

	public function test_a_reaction_updates_the_post_and_its_authors_score_and_alerts_nobody()
	{
		$this->recordInto('test-reaction');
		$post = PostFactory::new()->create(['post_date' => time() - 86400 * 10]);
		$reactor = UserFactory::new()->create(['register_date' => time() - 86400 * 5]);
		$alerts = $this->rows('xf_user_alert');

		$reaction = ReactionFactory::new()->for($post)->recycle($reactor)->reaction(1)->create();

		$this->assertDatabaseHas('xf_reaction_content', [
			'reaction_content_id' => $reaction->reaction_content_id, 'content_type' => 'post', 'content_id' => $post->post_id,
			'reaction_id' => 1, 'reaction_user_id' => $reactor->user_id, 'content_user_id' => $post->user_id, 'is_counted' => 1,
		]);
		$this->assertGreaterThanOrEqual($reactor->register_date, $reaction->reaction_date, 'not before the member reacting had joined');
		$this->assertLessThanOrEqual(time(), $reaction->reaction_date);

		$fresh = $this->app()->db()->fetchRow('SELECT reaction_score, reactions FROM xf_post WHERE post_id = ?', $post->post_id);
		$this->assertSame(1, (int) $fresh['reaction_score']);
		$this->assertSame(['1' => 1], json_decode($fresh['reactions'], true));
		$this->assertDatabaseHas('xf_user', ['user_id' => $post->user_id, 'reaction_score' => 1]);

		$this->assertDatabaseHas('xf_news_feed', [
			'content_type' => 'post', 'content_id' => $post->post_id, 'action' => 'reaction',
			'user_id' => $reactor->user_id, 'event_date' => $reaction->reaction_date,
		]);
		$this->assertSame($alerts, $this->rows('xf_user_alert'));
		$this->assertNoMailSent();
	}

	/**
	 * A member cannot react to their own post, or twice; when the pool runs out a member is made.
	 */
	public function test_reactors_are_never_the_author_and_never_repeat()
	{
		$this->recordInto('test-reactors');
		$author = UserFactory::new()->create();
		$others = UserFactory::new()->count(3)->create();
		$thread = ThreadFactory::new()->recycle($author)->create();
		$post = $thread->FirstPost;
		$users = $this->rows('xf_user');

		$pool = array_merge([$author], $others->toArray());
		ReactionFactory::new()->count(3)->for($post)->recycle($pool)->create();

		$reactors = array_map('intval', $this->app()->db()->fetchAllColumn(
			"SELECT reaction_user_id FROM xf_reaction_content WHERE content_type = 'post' AND content_id = ?", $post->post_id
		));
		sort($reactors);
		$expected = array_values($others->pluckNamed('user_id'));
		sort($expected);
		$this->assertSame($expected, $reactors);
		$this->assertSame($users, $this->rows('xf_user'), 'the pool was enough, so nobody was made');

		ReactionFactory::new()->for($post)->recycle($pool)->create();

		$this->assertSame($users + 1, $this->rows('xf_user'), 'with the pool used up, a new member reacts');
		$this->assertDatabaseCount('xf_reaction_content', 4, ['content_type' => 'post', 'content_id' => $post->post_id]);
	}

	public function test_a_reaction_to_a_thread_goes_to_its_first_post_and_has_gives_each_post_its_own()
	{
		$this->recordInto('test-thread-reactions');
		$members = UserFactory::new()->count(5)->create();
		$node = ForumFactory::new()->create();

		$thread = ThreadFactory::new()->for($node)->recycle($members)
			->has(ReactionFactory::new()->count(2))
			->has(PostFactory::new()->count(2)->has(ReactionFactory::new()->count(1)))
			->create();

		$perPost = $this->app()->db()->fetchPairs(
			"SELECT p.position, COUNT(r.reaction_content_id) FROM xf_post p
				LEFT JOIN xf_reaction_content r ON r.content_type = 'post' AND r.content_id = p.post_id
				WHERE p.thread_id = ? GROUP BY p.position ORDER BY p.position", $thread->thread_id
		);
		$this->assertSame([0 => 2, 1 => 1, 2 => 1], array_map('intval', $perPost));
	}

	public function test_a_moderated_thread_and_reply_are_in_the_approval_queue_and_not_counted()
	{
		$this->recordInto('test-moderated');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();

		$visible = ThreadFactory::new()->for($node)->recycle($author)->create();
		$held = ThreadFactory::new()->for($node)->recycle($author)->moderated()->create();
		$reply = PostFactory::new()->for($visible)->recycle($author)->moderated()->create();

		$this->assertDatabaseHas('xf_thread', ['thread_id' => $held->thread_id, 'discussion_state' => 'moderated']);
		$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'thread', 'content_id' => $held->thread_id, 'content_date' => $held->post_date]);
		$this->assertDatabaseHas('xf_post', ['post_id' => $reply->post_id, 'message_state' => 'moderated']);
		$this->assertDatabaseHas('xf_approval_queue', ['content_type' => 'post', 'content_id' => $reply->post_id, 'content_date' => $reply->post_date]);

		$this->assertDatabaseHas('xf_forum', ['node_id' => $node->node_id, 'discussion_count' => 1, 'message_count' => 1]);
		$this->assertDatabaseHas('xf_thread', ['thread_id' => $visible->thread_id, 'reply_count' => 0]);
	}

	/**
	 * Deleted last: after the replies and reactions it was given, which is the order it happens in.
	 */
	public function test_a_deleted_thread_was_posted_replied_to_reacted_to_and_then_deleted()
	{
		$this->recordInto('test-deleted');
		$node = ForumFactory::new()->create();
		$members = UserFactory::new()->count(4)->create();
		$moderator = UserFactory::new()->create(['is_moderator' => true]);
		$log = $this->rows('xf_moderator_log');

		$thread = ThreadFactory::new()->for($node)->recycle($members)->deleted('Seedertest reason', $moderator)
			->has(PostFactory::new()->count(2)->has(ReactionFactory::new()->count(1)))
			->create(['post_date' => time() - 86400 * 30]);

		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'discussion_state' => 'deleted', 'reply_count' => 2]);
		$row = $this->app()->db()->fetchRow("SELECT * FROM xf_deletion_log WHERE content_type = 'thread' AND content_id = ?", $thread->thread_id);
		$this->assertSame('Seedertest reason', $row['delete_reason']);
		$this->assertSame($moderator->user_id, (int) $row['delete_user_id']);
		$lastPost = (int) $this->app()->db()->fetchOne('SELECT MAX(post_date) FROM xf_post WHERE thread_id = ?', $thread->thread_id);
		$this->assertGreaterThanOrEqual($lastPost, (int) $row['delete_date'], 'deleted after its last reply');
		$this->assertLessThanOrEqual(time(), (int) $row['delete_date']);

		$this->assertDatabaseHas('xf_forum', ['node_id' => $node->node_id, 'discussion_count' => 0, 'message_count' => 0]);
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_reaction_content r JOIN xf_post p ON r.content_type = 'post' AND p.post_id = r.content_id
				WHERE p.thread_id = ? AND r.is_counted = 1", $thread->thread_id
		), 'reactions to hidden content stop counting');
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			'SELECT COALESCE(SUM(reaction_score), 0) FROM xf_user WHERE user_id IN (' . $this->app()->db()->quote(array_values($members->pluckNamed('user_id'))) . ')'
		));
		$this->assertSame($log, $this->rows('xf_moderator_log'), 'deleting seeded content is not a moderator action');
	}

	public function test_a_deleted_reply_is_deleted_by_its_author_unless_someone_is_named()
	{
		$this->recordInto('test-deleted-reply');
		$thread = ThreadFactory::new()->create();

		$reply = PostFactory::new()->for($thread)->deleted()->create();

		$this->assertDatabaseHas('xf_post', ['post_id' => $reply->post_id, 'message_state' => 'deleted']);
		$this->assertDatabaseHas('xf_deletion_log', ['content_type' => 'post', 'content_id' => $reply->post_id, 'delete_user_id' => $reply->user_id]);
		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'reply_count' => 0, 'discussion_state' => 'visible']);
	}

	public function test_attachments_carry_their_posts_date()
	{
		$this->recordInto('test-attach-date');

		try
		{
			$thread = ThreadFactory::new()->withAttachment()->create(['post_date' => 1700005000]);

			$row = $this->app()->db()->fetchRow(
				"SELECT a.attach_date, d.upload_date FROM xf_attachment a JOIN xf_attachment_data d USING (data_id)
					WHERE a.content_type = 'post' AND a.content_id = ?", $thread->first_post_id
			);
			$this->assertSame(['attach_date' => 1700005000, 'upload_date' => 1700005000], array_map('intval', $row));
		}
		finally
		{
			(new Cleaner())->clean('test-attach-date');
		}
	}

	public function test_cleaning_reactions_and_hidden_content_leaves_no_rows()
	{
		$tables = ['xf_user', 'xf_node', 'xf_thread', 'xf_post', 'xf_reaction_content', 'xf_approval_queue',
			'xf_deletion_log', 'xf_news_feed', 'xf_content_activity_log', 'xf_user_alert', 'xf_moderator_log',
			'xf_thread_prefix', 'xf_phrase', 'xf_attachment', 'xf_attachment_data'];
		$before = [];
		foreach ($tables AS $table)
		{
			$before[$table] = $this->rows($table);
		}

		$this->recordInto('test-states-clean');
		(new ThreadSeeder(['threads' => 12, 'replies' => 3, 'authors' => 5, 'reactions' => 3, 'states' => 1]))->run();

		$states = $this->app()->db()->fetchPairs(
			"SELECT discussion_state, COUNT(*) FROM xf_thread t JOIN xf_hampel_seeder_record r
				ON r.entity = 'XF:Thread' AND r.entity_id = t.thread_id WHERE r.batch = 'test-states-clean' GROUP BY 1"
		);
		$this->assertEquals(['deleted' => 2, 'moderated' => 2, 'visible' => 8], array_map('intval', $states));
		$this->assertGreaterThan($before['xf_reaction_content'], $this->rows('xf_reaction_content'));

		$result = (new Cleaner())->clean('test-states-clean');

		$this->assertSame([], $result['failed']);
		$after = [];
		foreach ($tables AS $table)
		{
			$after[$table] = $this->rows($table);
		}
		$this->assertSame($before, $after);
	}

	private function rows(string $table): int
	{
		return (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}
}
