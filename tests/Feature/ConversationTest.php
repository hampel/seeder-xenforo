<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ConversationFactory;
use Hampel\Seeder\Factory\ConversationMessageFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Conversations and their replies, created for real inside a transaction.
 */
class ConversationTest extends TestCase
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

	public function test_a_conversation_is_its_first_message_and_a_row_for_each_participant_dated_as_asked()
	{
		$this->recordInto('test-conversation');
		$db = $this->app()->db();
		[$starter, $other] = $this->members(2);

		$conversation = ConversationFactory::new()->from($starter)->to([$other])
			->create(['title' => 'Seedertest hello', 'start_date' => 1700100000]);
		$id = $conversation->conversation_id;

		$this->assertDatabaseHas('xf_conversation_master', [
			'conversation_id' => $id, 'title' => 'Seedertest hello', 'user_id' => $starter->user_id,
			'start_date' => 1700100000, 'last_message_date' => 1700100000, 'reply_count' => 0, 'recipient_count' => 2,
		]);
		$this->assertDatabaseHas('xf_conversation_message', ['conversation_id' => $id, 'user_id' => $starter->user_id, 'message_date' => 1700100000]);
		$this->assertDatabaseCount('xf_conversation_message', 1, ['conversation_id' => $id]);
		$this->assertDatabaseHas('xf_conversation_user', ['conversation_id' => $id, 'owner_user_id' => $starter->user_id, 'is_unread' => 0, 'last_message_date' => 1700100000]);
		$this->assertDatabaseHas('xf_conversation_user', ['conversation_id' => $id, 'owner_user_id' => $other->user_id, 'is_unread' => 1, 'last_message_date' => 1700100000]);
		$this->assertDatabaseHas('xf_conversation_recipient', ['conversation_id' => $id, 'user_id' => $starter->user_id, 'last_read_date' => 1700100000]);
		$this->assertDatabaseHas('xf_conversation_recipient', ['conversation_id' => $id, 'user_id' => $other->user_id, 'last_read_date' => 0]);
		$this->assertSame(1, (int) $db->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $other->user_id));
	}

	public function test_replies_alternate_authors_and_each_has_its_own_later_date()
	{
		$this->recordInto('test-conversation-replies');
		$db = $this->app()->db();
		$members = $this->members(3);

		$conversation = ConversationFactory::new()->recycle($members)->to(2)
			->has(ConversationMessageFactory::new()->count(9))
			->create(['start_date' => time() - 86400 * 20]);
		$id = $conversation->conversation_id;

		$messages = $db->fetchAll('SELECT user_id, message_date FROM xf_conversation_message WHERE conversation_id = ? ORDER BY message_id', $id);
		$this->assertCount(10, $messages);
		$this->assertCount(10, array_unique(array_column($messages, 'message_date')), 'no two messages share a second');
		$this->assertGreaterThanOrEqual(2, count(array_unique(array_column($messages, 'user_id'))));

		$memberIds = array_map(function ($member) { return $member->user_id; }, $members);
		for ($i = 1; $i < 10; $i++)
		{
			$this->assertGreaterThan($messages[$i - 1]['message_date'], $messages[$i]['message_date'], 'each is later than the last');
			$this->assertNotSame($messages[$i - 1]['user_id'], $messages[$i]['user_id'], 'and from somebody else');
			$this->assertContains((int) $messages[$i]['user_id'], $memberIds, 'nobody was created to reply');
			$this->assertLessThanOrEqual(time(), $messages[$i]['message_date']);
		}

		$last = end($messages);
		$this->assertDatabaseHas('xf_conversation_master', [
			'conversation_id' => $id, 'reply_count' => 9, 'recipient_count' => 3,
			'last_message_date' => $last['message_date'], 'last_message_user_id' => $last['user_id'],
		]);
		$this->assertDatabaseCount('xf_conversation_user', 3, ['conversation_id' => $id, 'last_message_date' => $last['message_date'], 'reply_count' => 9]);
		// whoever wrote last has read it all; each of the others has read up to their own last message
		$this->assertDatabaseHas('xf_conversation_user', ['conversation_id' => $id, 'owner_user_id' => $last['user_id'], 'is_unread' => 0]);
		$this->assertSame(0, (int) $db->fetchOne(
			'SELECT COUNT(*) FROM xf_conversation_recipient r WHERE r.conversation_id = ? AND r.last_read_date <> COALESCE((
				SELECT MAX(message_date) FROM xf_conversation_message m WHERE m.conversation_id = r.conversation_id AND m.user_id = r.user_id
			), 0)', $id
		));
	}

	public function test_a_reply_can_be_given_its_date_and_author()
	{
		$this->recordInto('test-conversation-dated');
		[$starter, $other] = $this->members(2);
		$conversation = ConversationFactory::new()->from($starter)->to([$other])->create(['start_date' => 1700100000]);

		$reply = ConversationMessageFactory::new()->for($conversation)
			->create(['user_id' => $starter->user_id, 'message_date' => 1700100777, 'message' => 'Seedertest reply']);

		$this->assertDatabaseHas('xf_conversation_message', [
			'message_id' => $reply->message_id, 'user_id' => $starter->user_id, 'message_date' => 1700100777, 'message' => 'Seedertest reply',
		]);
		$this->assertDatabaseHas('xf_conversation_master', ['conversation_id' => $conversation->conversation_id, 'last_message_date' => 1700100777, 'last_message_id' => $reply->message_id]);
	}

	public function test_nobody_is_told_and_members_have_an_address()
	{
		$this->recordInto('test-conversation-quiet');
		$db = $this->app()->db();
		$alerts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert');
		$ips = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip');

		$conversation = ConversationFactory::new()->to(2)->has(ConversationMessageFactory::new()->count(3))->create();

		$this->assertNoMailSent();
		$this->assertSame($alerts, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user_alert'));
		$this->assertSame($ips, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip'));
		$participants = $db->fetchAllColumn('SELECT user_id FROM xf_conversation_recipient WHERE conversation_id = ?', $conversation->conversation_id);
		$this->assertCount(3, $participants);
		foreach ($participants AS $userId)
		{
			$this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $db->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $userId));
		}
	}

	public function test_it_needs_someone_other_than_the_starter()
	{
		$this->recordInto('test-conversation-alone');
		[$starter] = $this->members(1);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one recipient');

		ConversationFactory::new()->from($starter)->to([$starter])->create();
	}

	public function test_cleaning_removes_everything_and_puts_unread_counts_back()
	{
		// the members go in a batch of their own, so that they outlive the clean
		$this->recordInto('test-conversation-members');
		$members = $this->members(3);
		$recorder = $this->recordInto('test-conversation-clean');
		$db = $this->app()->db();
		$tables = ['xf_conversation_master', 'xf_conversation_message', 'xf_conversation_user', 'xf_conversation_recipient', 'xf_user', 'xf_user_alert'];
		$before = $this->tableCounts($tables);

		$batch = 'test-conversation-clean';
		ConversationFactory::new()->recycle($members)->to(2)->has(ConversationMessageFactory::new()->count(4))->count(2)->create();

		$created = $recorder->created();
		$this->assertCount(2, $created['XF:ConversationMaster']);
		$this->assertCount(8, $created['XF:ConversationMessage']);
		$this->assertSame(['id', 'title', 'user_id', 'participants', 'first_message_id'], array_keys($created['XF:ConversationMaster'][0]));
		$this->assertSame(['id', 'conversation_id', 'user_id', 'message_date'], array_keys($created['XF:ConversationMessage'][0]));

		$result = (new Cleaner())->clean($batch);

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->tableCounts($tables));
		foreach ($members AS $member)
		{
			$this->assertSame(0, (int) $db->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $member->user_id));
		}
	}

	public function test_removing_one_reply_leaves_the_conversation_pointing_at_the_one_before()
	{
		$this->recordInto('test-conversation-one');
		[$starter, $other] = $this->members(2);
		$conversation = ConversationFactory::new()->from($starter)->to([$other])->create(['start_date' => 1700100000]);
		$first = ConversationMessageFactory::new()->for($conversation)->create(['message_date' => 1700100100]);
		$second = ConversationMessageFactory::new()->for($conversation)->create(['message_date' => 1700100200]);

		ConversationMessageFactory::remove($second);

		$expected = ['conversation_id' => $conversation->conversation_id, 'reply_count' => 1, 'last_message_id' => $first->message_id, 'last_message_date' => 1700100100];
		$this->assertDatabaseHas('xf_conversation_master', $expected);
		$this->assertDatabaseCount('xf_conversation_user', 2, $expected);
	}

	public function test_the_seeder_makes_conversations_between_the_members_it_creates()
	{
		$db = $this->app()->db();
		$users = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user');
		$tester = new CommandTester(new Run());

		$code = $tester->execute(['seeder' => 'Conversation', '-p' => ['conversations=3', 'messages=4', 'members=4', 'participants=3'], '--batch' => 'test-conversation-seeder', '--seed' => '3'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getDisplay() . $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(4, $created['XF:User']);
		$this->assertCount(3, $created['XF:ConversationMaster']);
		$this->assertCount(9, $created['XF:ConversationMessage']);
		$this->assertSame($users + 4, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'));
		foreach ($created['XF:ConversationMaster'] AS $conversation)
		{
			$this->assertGreaterThanOrEqual(2, count($conversation['participants']));
			$this->assertLessThanOrEqual(3, count($conversation['participants']));
		}

		(new Cleaner())->clean('test-conversation-seeder');
		$this->assertSame($users, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_user'));
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
