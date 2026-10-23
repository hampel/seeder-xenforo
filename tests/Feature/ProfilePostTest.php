<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ProfilePostCommentFactory;
use Hampel\Seeder\Factory\ProfilePostFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Profile posts, status updates and comments, created for real inside a transaction.
 */
class ProfilePostTest extends TestCase
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

	public function test_one_member_posts_on_anothers_profile_and_nobody_is_alerted()
	{
		$this->recordInto('test-profile-post');
		$db = $this->app()->db();
		[$owner, $author] = $this->members(2);
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');
		$ips = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip');

		$post = ProfilePostFactory::new()->on($owner)->create(['user_id' => $author->user_id]);

		$this->assertDatabaseHas('xf_profile_post', [
			'profile_post_id' => $post->profile_post_id, 'profile_user_id' => $owner->user_id,
			'user_id' => $author->user_id, 'username' => $author->username, 'message_state' => 'visible',
		]);
		$this->assertGreaterThanOrEqual(1600000000, $post->post_date);
		$this->assertLessThanOrEqual(time(), $post->post_date);
		$this->assertNotSame('', $post->message);
		$this->assertLessThanOrEqual($this->app()->options()->profilePostMaxLength, mb_strlen($post->message));

		// as a forum leaves it: in the owner's news feed, dated with the post
		$this->assertDatabaseHas('xf_news_feed', [
			'content_type' => 'profile_post', 'content_id' => $post->profile_post_id, 'user_id' => $author->user_id, 'event_date' => $post->post_date,
		]);
		$this->assertFalse($post->getBehavior('XF:Webhook')->getOption('enabled'));
		$this->assertNoMailSent();
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'));
		$this->assertSame($ips, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip'));
	}

	public function test_comments_count_on_the_profile_post_and_nobody_is_alerted()
	{
		$this->recordInto('test-profile-post-comments');
		$db = $this->app()->db();
		[$owner, $author, $commenter] = $this->members(3);
		$post = ProfilePostFactory::new()->on($owner)->create(['user_id' => $author->user_id, 'post_date' => 1700100000]);
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');

		$first = ProfilePostCommentFactory::new()->for($post)->create(['user_id' => $commenter->user_id]);
		$second = ProfilePostCommentFactory::new()->for($post)->create(['user_id' => $owner->user_id]);
		$dated = ProfilePostCommentFactory::new()->for($post)->create(['user_id' => $author->user_id, 'comment_date' => 1700100005]);

		$this->assertDatabaseHas('xf_profile_post_comment', [
			'profile_post_comment_id' => $first->profile_post_comment_id, 'profile_post_id' => $post->profile_post_id,
			'user_id' => $commenter->user_id, 'message_state' => 'visible',
		]);
		$this->assertGreaterThan(1700100000, $first->comment_date);
		$this->assertGreaterThanOrEqual($first->comment_date, $second->comment_date);
		$this->assertDatabaseHas('xf_profile_post_comment', ['profile_post_comment_id' => $dated->profile_post_comment_id, 'comment_date' => 1700100005]);

		$row = $db->fetchRow('SELECT * FROM xf_profile_post WHERE profile_post_id = ?', $post->profile_post_id);
		$this->assertSame(1700100000, (int) $row['post_date'], 'the date asked for, not the moment it was saved');
		$this->assertSame(3, (int) $row['comment_count']);
		$this->assertSame(1700100005, (int) $row['first_comment_date']);
		$this->assertSame($second->comment_date, (int) $row['last_comment_date']);
		$this->assertEqualsCanonicalizing(
			[$first->profile_post_comment_id, $second->profile_post_comment_id, $dated->profile_post_comment_id],
			array_keys(json_decode($row['latest_comment_ids'], true))
		);

		$this->assertNoMailSent();
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'));
	}

	public function test_a_comment_silences_the_profile_post_it_saves()
	{
		$this->recordInto('test-profile-post-silent');
		$id = ProfilePostFactory::new()->create()->profile_post_id;

		// as a profile post this run did not make, or one loaded afresh, would be
		$this->app()->em()->clearEntityCache();
		$post = $this->app()->em()->find('XF:ProfilePost', $id);
		$this->assertTrue($post->getBehavior('XF:Webhook')->getOption('enabled'));

		ProfilePostCommentFactory::new()->for($post)->create();

		$this->assertFalse($post->getBehavior('XF:Webhook')->getOption('enabled'));
		$this->assertFalse($post->getOption('log_moderator'));
	}

	public function test_a_status_update_is_by_its_owner_and_otherwise_never()
	{
		$this->recordInto('test-profile-post-status');
		$members = UserFactory::new()->count(2)->create();

		$status = ProfilePostFactory::new()->on($members->first())->status()->create();
		$this->assertSame($members->first()->user_id, $status->user_id);
		$this->assertSame($members->first()->user_id, $status->profile_user_id);

		$posts = ProfilePostFactory::new()->recycle($members)->count(12)->create();
		foreach ($posts AS $post)
		{
			$this->assertNotSame($post->profile_user_id, $post->user_id, 'a status update nobody asked for');
		}

		$statuses = ProfilePostFactory::new()->recycle($members)->status()->count(4)->create();
		foreach ($statuses AS $post)
		{
			$this->assertSame($post->profile_user_id, $post->user_id);
		}
	}

	public function test_cleaning_removes_posts_comments_and_their_news_feed()
	{
		// the members go in a batch of their own, so that they outlive the clean
		$this->recordInto('test-profile-post-members');
		$members = UserFactory::new()->count(3)->create();
		$recorder = $this->recordInto('test-profile-post-clean');
		$tables = ['xf_profile_post', 'xf_profile_post_comment', 'xf_news_feed', 'xf_user_alert', 'xf_approval_queue', 'xf_deletion_log'];
		$before = $this->tableCounts($tables);

		ProfilePostFactory::new()->recycle($members)->withComments(2)->count(3)->create();
		ProfilePostFactory::new()->recycle($members)->status()->withComments(1)->create();

		$created = $recorder->created();
		$this->assertCount(4, $created['XF:ProfilePost']);
		$this->assertCount(7, $created['XF:ProfilePostComment']);
		$this->assertArrayNotHasKey('XF:User', $created, 'comments by the recycled members only');

		$result = (new Cleaner())->clean('test-profile-post-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->tableCounts($tables));
	}

	public function test_the_seeder_makes_posts_statuses_and_comments_between_the_members_it_creates()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => 'ProfilePost', '-p' => ['posts=5', 'statuses=2', 'comments=2', 'members=3'], '--batch' => 'test-profile-post-seeder', '--seed' => '3'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(3, $created['XF:User']);
		$this->assertCount(7, $created['XF:ProfilePost']);
		$this->assertLessThanOrEqual(14, count($created['XF:ProfilePostComment'] ?? []));

		$members = array_column($created['XF:User'], 'id');
		$statuses = 0;
		foreach ($created['XF:ProfilePost'] AS $post)
		{
			$this->assertContains($post['profile_user_id'], $members);
			$this->assertContains($post['user_id'], $members);
			$statuses += $post['profile_user_id'] === $post['user_id'] ? 1 : 0;
		}
		$this->assertSame(2, $statuses);

		$this->assertSame([], (new Cleaner())->clean('test-profile-post-seeder')['failed']);
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}

	private function members(int $count): array
	{
		return array_values(UserFactory::new()->count($count)->create(['register_date' => 1600000000])->toArray());
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
