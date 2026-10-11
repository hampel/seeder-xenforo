<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\PrefixFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\Mvc\Entity\Entity;

/**
 * Forums, threads, replies, prefixes and attachments, created for real inside a transaction.
 */
class ContentTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const RECORDS = 'xf_hampel_seeder_record';

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

	public function test_a_forum_is_a_node_and_its_forum_row()
	{
		$this->recordInto('test-forum');

		$node = ForumFactory::new()->create(['title' => 'Seeder test forum']);

		$this->assertSame('XF:Node', $node->structure()->shortName);
		$this->assertDatabaseHas('xf_node', ['node_id' => $node->node_id, 'node_type_id' => 'Forum', 'title' => 'Seeder test forum']);
		$this->assertDatabaseHas('xf_forum', ['node_id' => $node->node_id]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-forum', 'entity' => 'XF:Node', 'factory' => ForumFactory::class]);
	}

	public function test_a_thread_is_a_thread_and_its_first_post_dated_as_asked()
	{
		$this->recordInto('test-thread');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create(['register_date' => 1700000000]);

		$thread = ThreadFactory::new()->for($node)->create([
			'user_id' => $author->user_id, 'title' => 'Seeder test thread', 'message' => 'The first post.', 'post_date' => 1700005000,
		]);

		$this->assertDatabaseHas('xf_thread', [
			'thread_id' => $thread->thread_id, 'node_id' => $node->node_id, 'user_id' => $author->user_id,
			'title' => 'Seeder test thread', 'post_date' => 1700005000, 'last_post_date' => 1700005000,
			'reply_count' => 0, 'discussion_state' => 'visible',
		]);
		$this->assertDatabaseHas('xf_post', [
			'post_id' => $thread->first_post_id, 'thread_id' => $thread->thread_id, 'position' => 0,
			'message' => 'The first post.', 'post_date' => 1700005000, 'user_id' => $author->user_id,
		]);
		$this->assertDatabaseHas('xf_forum', ['node_id' => $node->node_id, 'discussion_count' => 1, 'message_count' => 1, 'last_post_date' => 1700005000]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $author->user_id, 'message_count' => 1]);
		$this->assertDatabaseHas('xf_news_feed', ['content_type' => 'thread', 'content_id' => $thread->thread_id, 'event_date' => 1700005000]);
		$this->assertDatabaseHas(self::RECORDS, ['batch' => 'test-thread', 'entity' => 'XF:Thread', 'factory' => ThreadFactory::class]);
	}

	public function test_a_thread_made_with_nothing_given_makes_its_own_forum_and_author()
	{
		$this->recordInto('test-thread-alone');

		$thread = ThreadFactory::new()->create();

		$this->assertDatabaseHas('xf_forum', ['node_id' => $thread->node_id]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $thread->user_id]);
		$author = $this->app()->em()->find('XF:User', $thread->user_id);
		$this->assertGreaterThanOrEqual($author->register_date, $thread->post_date);
		$this->assertDatabaseCount(self::RECORDS, 3, ['batch' => 'test-thread-alone']);
	}

	public function test_make_builds_a_thread_without_touching_the_database()
	{
		$this->recordInto('test-thread-make');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();
		$before = $this->counts(['xf_thread', 'xf_post', 'xf_attachment']);

		$thread = ThreadFactory::new()->for($node)->recycle($author)->withAttachment()->make(['title' => 'Not saved']);

		$this->assertSame('Not saved', $thread->title);
		$this->assertFalse($thread->exists());
		$this->assertSame($before, $this->counts(['xf_thread', 'xf_post', 'xf_attachment']));
	}

	public function test_replies_follow_the_thread_in_order_and_never_precede_their_author()
	{
		$this->recordInto('test-replies');
		$thread = ThreadFactory::new()->create();
		$late = UserFactory::new()->create(['register_date' => time() - 3600]);

		$replies = PostFactory::new()->for($thread)->count(5)->create();
		$lateReply = PostFactory::new()->for($thread)->create(['user_id' => $late->user_id]);

		$dates = $this->app()->db()->fetchAllColumn('SELECT post_date FROM xf_post WHERE thread_id = ? ORDER BY position', $thread->thread_id);
		$sorted = $dates;
		sort($sorted);
		$this->assertCount(7, $dates);
		$this->assertSame($sorted, $dates, 'position order is date order');
		$this->assertGreaterThanOrEqual($late->register_date, $lateReply->post_date);
		$this->assertLessThanOrEqual(time(), max($dates));

		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'reply_count' => 6, 'last_post_date' => max($dates), 'last_post_id' => $lateReply->post_id]);
		$this->assertCount(5, $replies);
	}

	public function test_recycle_for_and_has_relate_threads_to_a_forum_a_pool_of_authors_and_their_replies()
	{
		$this->recordInto('test-relations');
		$node = ForumFactory::new()->create();
		$other = ForumFactory::new()->create();
		$authors = UserFactory::new()->count(3)->create();
		$users = $this->counts(['xf_user', 'xf_node']);

		$threads = ThreadFactory::new()->count(4)->recycle($authors)->for($node)
			->has(PostFactory::new()->count(2))
			->create();

		$this->assertSame($users, $this->counts(['xf_user', 'xf_node']), 'no member or forum was created beyond the ones given');
		$this->assertCount(4, $threads);
		$this->assertDatabaseCount('xf_thread', 4, ['node_id' => $node->node_id]);
		$this->assertDatabaseCount('xf_thread', 0, ['node_id' => $other->node_id]);

		$authorIds = array_values($authors->pluckNamed('user_id'));
		$threadIds = array_values($threads->pluckNamed('thread_id'));
		$posts = $this->app()->db()->fetchAll('SELECT user_id, thread_id FROM xf_post WHERE thread_id IN (' . $this->app()->db()->quote($threadIds) . ')');
		$this->assertCount(12, $posts, 'four first posts and two replies each');
		foreach ($posts AS $post)
		{
			$this->assertContains((int) $post['user_id'], $authorIds);
		}
	}

	public function test_a_count_range_gives_each_parent_its_own_number_of_children()
	{
		$this->recordInto('test-range');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();

		$threads = ThreadFactory::new()->count(12)->recycle($author)->for($node)
			->has(PostFactory::new()->count([0, 3]))
			->create();

		$replies = array_values($this->app()->db()->fetchAllColumn(
			'SELECT reply_count FROM xf_thread WHERE thread_id IN (' . $this->app()->db()->quote(array_values($threads->pluckNamed('thread_id'))) . ')'
		));
		$this->assertGreaterThanOrEqual(0, min($replies));
		$this->assertLessThanOrEqual(3, max($replies));
		$this->assertGreaterThan(1, count(array_unique($replies)), 'twelve threads should not all draw the same number');

		$this->expectException(\InvalidArgumentException::class);
		PostFactory::new()->count([3, 1]);
	}

	public function test_the_with_states_add_up_and_show_in_the_first_post()
	{
		$this->recordInto('test-parts');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();
		$factory = ThreadFactory::new()->for($node)->recycle($author);

		$message = function (Entity $thread) { return $thread->FirstPost->message; };

		$plain = $message($factory->create());
		$this->assertStringNotContainsString('[', $plain, 'a default first post is plain prose');

		$rich = $message($factory->withQuote()->withList()->withImage()->withLinks()->withFormatting()->create());
		foreach (['[QUOTE=', '[LIST]', '[*]', '[IMG]https://example.com/', "[URL='https://example.", '[HEADING=2]', '[B]'] AS $tag)
		{
			$this->assertStringContainsString($tag, $rich);
		}

		$this->assertGreaterThan(strlen($plain) * 2, strlen($message($factory->long()->create())));
		$this->assertDoesNotMatchRegularExpression('#https?://(?!example\.(com|org|net)/)#', $rich, 'links stay on reserved domains');
		$this->assertDoesNotMatchRegularExpression('#(^|\s)https?://#', $rich, 'no bare address, which XenForo would fetch a preview for');

		$this->assertFalse($factory->locked()->create()->discussion_open);
		$this->assertTrue($factory->sticky()->create()->sticky);
	}

	public function test_a_created_thread_no_longer_saves_its_first_post_along_with_itself()
	{
		// XenForo 2.3.0 keeps the link for the life of the entity, and a reaction to the first
		// post then goes round in a circle; later releases drop it themselves
		$this->recordInto('test-cascade');
		$thread = ThreadFactory::new()->create();
		$member = UserFactory::new()->create();

		$cascade = new \ReflectionProperty(\XF\Mvc\Entity\Entity::class, '_cascadeSave');
		$cascade->setAccessible(true);

		$this->assertSame([], $cascade->getValue($thread));
		$this->assertSame([], $cascade->getValue($member), 'nor a member their option and profile rows');
	}

	public function test_tags_are_made_when_new_used_when_they_exist_and_tidied_as_xenforo_tidies_them()
	{
		$recorder = $this->recordInto('test-tags');
		$db = $this->app()->db();
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();
		// permanent, as a tag an administrator keeps: XenForo deletes any other tag once nothing uses it
		$existing = $this->app()->em()->create('XF:Tag');
		$existing->bulkSet(['tag' => 'seedertest existing', 'permanent' => true]);
		$existing->save();
		$tags = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_tag');

		$thread = ThreadFactory::new()->for($node)->recycle($author)
			->withTags(['Seedertest  Alpha', 'seedertest existing'])->withTags(['seedertest two words'])
			->create(['post_date' => 1700005000]);

		$this->assertSame($tags + 2, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_tag'), 'two were new; the third was already there');
		$this->assertDatabaseHas('xf_tag', ['tag' => 'seedertest alpha', 'tag_url' => 'seedertest-alpha', 'use_count' => 1, 'last_use_date' => 1700005000]);
		$this->assertDatabaseHas('xf_tag', ['tag' => 'seedertest two words', 'tag_url' => 'seedertest-two-words']);
		$this->assertDatabaseCount('xf_tag_content', 3, ['content_type' => 'thread', 'content_id' => $thread->thread_id]);
		$this->assertDatabaseHas('xf_tag_content', [
			'content_type' => 'thread', 'content_id' => $thread->thread_id, 'tag_id' => $existing->tag_id,
			'add_user_id' => $author->user_id, 'add_date' => 1700005000, 'content_date' => 1700005000, 'visible' => 1,
		]);

		$cache = json_decode($db->fetchOne('SELECT tags FROM xf_thread WHERE thread_id = ?', $thread->thread_id), true);
		$names = array_column($cache, 'tag');
		sort($names);
		$this->assertSame(['seedertest alpha', 'seedertest existing', 'seedertest two words'], $names);

		$created = $recorder->created();
		$this->assertSame(['id', 'tag', 'tag_url'], array_keys($created['XF:Tag'][0]));
		$this->assertCount(2, $created['XF:Tag'], 'only the tags it made are recorded');
		$described = end($created['XF:Thread']);
		sort($described['tags']);
		$this->assertSame($names, $described['tags']);

		$result = (new Cleaner())->clean('test-tags');

		$this->assertSame([], $result['failed']);
		$this->assertDatabaseHas('xf_tag', ['tag_id' => $existing->tag_id], 'a tag that was there before is left');
		$this->assertDatabaseMissing('xf_tag', ['tag' => 'seedertest alpha']);
		$this->assertDatabaseCount('xf_tag_content', 0, ['content_type' => 'thread', 'content_id' => $thread->thread_id]);
		$existing->delete();
	}

	public function test_a_name_the_forum_would_not_take_as_a_tag_is_refused()
	{
		$this->recordInto('test-tags-bad');
		$this->setOption('tagLength', ['min' => 4, 'max' => 20]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("'ab' is not a tag this forum would accept");

		ThreadFactory::new()->withTags(['ab'])->create();
	}

	public function test_a_prefix_has_a_title_phrase_and_is_offered_in_the_forums_named()
	{
		$this->recordInto('test-prefix');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();

		$prefix = PrefixFactory::new()->create(['title' => 'Seedertest', 'node_ids' => [$node->node_id]]);
		$thread = ThreadFactory::new()->for($node)->recycle($author)->withPrefix($prefix)->create();

		$this->assertDatabaseHas('xf_thread_prefix', ['prefix_id' => $prefix->prefix_id]);
		$this->assertDatabaseHas('xf_phrase', ['title' => 'thread_prefix.' . $prefix->prefix_id, 'phrase_text' => 'Seedertest']);
		$this->assertDatabaseHas('xf_forum_prefix', ['node_id' => $node->node_id, 'prefix_id' => $prefix->prefix_id]);
		$this->assertDatabaseHas('xf_thread', ['thread_id' => $thread->thread_id, 'prefix_id' => $prefix->prefix_id]);
	}

	public function test_an_attachment_is_a_real_image_shown_in_the_post_and_cleaning_removes_its_file()
	{
		$this->recordInto('test-attach');
		$node = ForumFactory::new()->create();
		$author = UserFactory::new()->create();

		try
		{
			$thread = ThreadFactory::new()->for($node)->recycle($author)->withAttachment(2)->create();

			$attachments = $this->app()->finder('XF:Attachment')
				->where('content_type', 'post')->where('content_id', $thread->first_post_id)->fetch();
			$this->assertCount(2, $attachments);
			$this->assertDatabaseHas('xf_post', ['post_id' => $thread->first_post_id, 'attach_count' => 2]);
			$this->assertStringContainsString('[ATTACH type="full"]' . $attachments->first()->attachment_id . '[/ATTACH]', $thread->FirstPost->message);

			$paths = [];
			foreach ($attachments AS $attachment)
			{
				$data = $attachment->Data;
				$this->assertSame(480, $data->width);
				$this->assertStringEndsWith('.png', $data->filename);
				$this->assertSame(0, (int) $attachment->unassociated);
				$paths[] = $data->getAbstractedDataPath();
				$this->assertSame("\x89PNG", substr($this->app()->fs()->read($data->getAbstractedDataPath()), 0, 4));
			}
		}
		finally
		{
			// the files are on the real filesystem, which a rolled-back transaction does not undo
			$result = (new Cleaner())->clean('test-attach');
		}

		$this->assertSame([], $result['failed']);
		foreach ($paths AS $path)
		{
			$this->assertFalse($this->app()->fs()->has($path), 'the attachment file was left behind');
		}
		$this->assertDatabaseCount('xf_attachment', 0, ['content_type' => 'post', 'content_id' => $thread->first_post_id]);
	}

	public function test_cleaning_a_seeded_forum_leaves_no_rows_no_alerts_and_no_moderator_log()
	{
		$tables = ['xf_user', 'xf_node', 'xf_forum', 'xf_thread', 'xf_post', 'xf_thread_prefix', 'xf_forum_prefix',
			'xf_phrase', 'xf_news_feed', 'xf_thread_user_post', 'xf_search_index', 'xf_content_activity_log',
			'xf_user_alert', 'xf_moderator_log', 'xf_thread_watch', 'xf_ip', 'xf_attachment', 'xf_attachment_data'];
		$before = $this->counts($tables);
		$this->recordInto('test-no-trace');

		$authors = UserFactory::new()->count(3)->create();
		$node = ForumFactory::new()->create();
		$prefix = PrefixFactory::new()->create(['node_ids' => [$node->node_id]]);
		ThreadFactory::new()->count(4)->recycle($authors)->for($node)->withPrefix($prefix)->withQuote()
			->has(PostFactory::new()->count(3))
			->create();

		$during = $this->counts($tables);
		$this->assertSame($before['xf_post'] + 16, $during['xf_post']);
		$this->assertSame($before['xf_user_alert'], $during['xf_user_alert'], 'seeding alerted nobody');
		$this->assertSame($before['xf_moderator_log'], $during['xf_moderator_log']);
		$this->assertSame($before['xf_thread_watch'], $during['xf_thread_watch']);
		$this->assertSame($before['xf_ip'], $during['xf_ip']);
		$this->assertNoMailSent();

		$result = (new Cleaner())->clean('test-no-trace');

		$this->assertSame([], $result['failed']);
		$this->assertSame(['XF:Post' => 12, 'XF:Thread' => 4, 'XF:ThreadPrefix' => 1, 'XF:Node' => 1, 'XF:User' => 3], $result['removed']);
		$this->assertSame($before, $this->counts($tables));
		$this->assertNoMailSent();
	}

	/**
	 * An entity that throws part-way through its delete leaves its transaction open. Left open,
	 * every later removal in the run would be reported as done and then discarded.
	 */
	public function test_a_removal_that_fails_mid_transaction_is_undone_and_the_rest_still_happen()
	{
		$this->recordInto('test-midway');
		$first = $this->question();
		$stubborn = $this->question();
		$last = $this->question();
		foreach ([$first, $stubborn, $last] AS $question)
		{
			Context::current()->recorder->record($question);
		}

		$cleaner = new class($stubborn->captcha_question_id) extends Cleaner
		{
			private $refuses;

			public function __construct(int $refuses)
			{
				$this->refuses = $refuses;
			}

			protected function remove(Entity $entity, ?string $factory): void
			{
				if ($entity->captcha_question_id === $this->refuses)
				{
					// what a failing _postDelete() looks like: a transaction begun, a row gone, then a throw
					\XF::db()->beginTransaction();
					\XF::db()->delete('xf_captcha_question', 'captcha_question_id = ?', $this->refuses);

					throw new \RuntimeException('failed part-way');
				}

				parent::remove($entity, $factory);
			}
		};

		$result = $cleaner->clean('test-midway');

		$this->assertCount(1, $result['failed']);
		$this->assertSame(['XF:CaptchaQuestion' => 2], $result['removed']);
		$this->assertDatabaseHas('xf_captcha_question', ['captcha_question_id' => $stubborn->captcha_question_id]);
		$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $first->captcha_question_id]);
		$this->assertDatabaseMissing('xf_captcha_question', ['captcha_question_id' => $last->captcha_question_id]);
		$this->assertDatabaseCount(self::RECORDS, 1, ['batch' => 'test-midway']);
	}

	public function test_the_thread_seeder_varies_its_first_posts_and_can_post_in_an_existing_forum()
	{
		$this->recordInto('test-setup');
		$node = ForumFactory::new()->create();

		try
		{
			[$code, $json, $summary] = $this->run_([
				'seeder' => 'Thread', '--batch' => 'test-thread-seeder', '--seed' => '3',
				'--param' => ['threads=8', 'replies=2', 'authors=3', 'forum=' . $node->node_id],
			]);

			$this->assertSame(0, $code, $summary);
			$created = json_decode($json, true)['created'];
			$this->assertCount(3, $created['XF:User']);
			$this->assertCount(8, $created['XF:Thread']);
			$this->assertCount(1, $created['XF:ThreadPrefix']);
			$this->assertArrayNotHasKey('XF:Node', $created, 'the forum given was used; none was made');
			$this->assertArrayNotHasKey('XF:ReactionContent', $created, 'reactions are only made when asked for');
			$this->assertArrayNotHasKey('XF:Tag', $created, 'and so are tags');
			$this->assertSame([$node->node_id], array_values(array_unique(array_column($created['XF:Thread'], 'node_id'))));
			$this->assertSame(['id', 'title', 'node_id', 'user_id', 'first_post_id'], array_keys($created['XF:Thread'][0]));

			$messages = $this->app()->db()->fetchAllColumn(
				'SELECT message FROM xf_post WHERE post_id IN (' . $this->app()->db()->quote(array_column($created['XF:Thread'], 'first_post_id')) . ')'
			);
			$all = implode("\n", $messages);
			foreach (['[QUOTE=', '[LIST]', '[IMG]', '[URL=', '[ATTACH', '[HEADING=2]'] AS $tag)
			{
				$this->assertStringContainsString($tag, $all);
			}
			$this->assertDatabaseCount('xf_thread', 2, ['node_id' => $node->node_id, 'prefix_id' => $created['XF:ThreadPrefix'][0]['id']]);
		}
		finally
		{
			// it attached images, whose files a rollback does not remove
			(new Cleaner())->clean('test-thread-seeder');
		}
	}

	public function test_the_thread_seeder_refuses_a_forum_that_does_not_exist()
	{
		$this->fakesErrors();

		[$code, , $summary] = $this->run_(['seeder' => 'Thread', '--batch' => 'test-bad-forum', '--param' => ['forum=99999999', 'authors=0']]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString("must be the node id of a forum", $summary);
		$this->assertNoExceptionsLogged();
	}

	private function question(): Entity
	{
		return $this->createEntity('XF:CaptchaQuestion', ['question' => 'Seeder test ' . bin2hex(random_bytes(6)), 'answers' => ['yes']]);
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
