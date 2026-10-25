<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\EmailBounceLogFactory;
use Hampel\Seeder\Factory\SpamTriggerLogFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Spam trigger and email bounce log entries, created for real inside a transaction.
 */
class SpamAndBounceLogTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_user', 'xf_user_reject', 'xf_approval_queue', 'xf_thread', 'xf_post', 'xf_spam_trigger_log',
		'xf_email_bounce_log', 'xf_email_bounce_soft', 'xf_user_alert', 'xf_moderator_log'];

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

	public function test_a_spam_trigger_entry_is_about_a_recent_registration_held_for_approval_by_default()
	{
		$this->recordInto('test-spam-default');

		$entry = SpamTriggerLogFactory::new()->create();
		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_spam_trigger_log WHERE trigger_log_id = ?', $entry->trigger_log_id);
		$member = $this->app()->em()->find('XF:User', $row['user_id']);

		$this->assertSame('user', $row['content_type']);
		$this->assertSame($member->user_id, (int) $row['content_id']);
		$this->assertSame('moderated', $row['result']);
		$this->assertSame('moderated', $member->user_state);
		$this->assertSame($member->register_date, (int) $row['log_date']);
		$this->assertGreaterThan(time() - 30 * 86400, (int) $row['log_date'], 'inside what XenForo prunes');

		$state = json_decode($row['request_state'], true);
		$this->assertSame('/register/register', $state['url']);
		$this->assertSame('********', $state['_POST']['password']);
		$this->assertMatchesRegularExpression('/^(192\.0\.2|198\.51\.100|203\.0\.113|2001:db8:)/', \XF\Util\Ip::binaryToString($row['ip_address']));

		// and XenForo's own log page reads the details back in its own words
		$found = $this->app()->em()->find('XF:SpamTriggerLog', $entry->trigger_log_id);
		$this->assertNotSame('', $found->getDetails());
		$this->assertStringNotContainsString('sfs_matched_x', $found->getDetails());
	}

	public function test_an_entry_is_about_the_content_given_and_a_rejected_member_was_denied()
	{
		$this->recordInto('test-spam-content');

		$thread = ThreadFactory::new()->moderated()->create(['post_date' => time() - 86400]);
		$entry = SpamTriggerLogFactory::new()->for($thread)->create();
		$this->assertSame(['thread', $thread->thread_id, $thread->user_id, $thread->post_date, 'moderated'],
			[$entry->content_type, $entry->content_id, $entry->user_id, $entry->log_date, $entry->result]);
		$this->assertStringContainsString('/post-thread', $entry->request_state['url']);

		$rejected = UserFactory::new()->rejected()->create(['register_date' => time() - 86400]);
		$this->assertSame('denied', SpamTriggerLogFactory::new()->for($rejected)->create()->result);

		$this->expectExceptionMessage('one spam trigger log entry for each piece of content');
		SpamTriggerLogFactory::new()->for($thread)->create();
	}

	public function test_a_message_refused_outright_has_no_content()
	{
		$this->recordInto('test-spam-denied');
		$member = UserFactory::new()->create(['register_date' => time() - 60 * 86400]);

		$entries = SpamTriggerLogFactory::new()->denied('post', $member)->count(2)->create();

		foreach ($entries AS $entry)
		{
			$row = $this->app()->db()->fetchRow('SELECT * FROM xf_spam_trigger_log WHERE trigger_log_id = ?', $entry->trigger_log_id);
			$this->assertNull($row['content_id'], 'as XenForo writes it');
			$this->assertSame(['post', 'denied', $member->user_id], [$row['content_type'], $row['result'], (int) $row['user_id']]);
			$this->assertGreaterThan(time() - 30 * 86400, (int) $row['log_date']);
		}

		$this->expectExceptionMessage("not 'user'");
		SpamTriggerLogFactory::new()->denied('user');
	}

	public function test_a_bounce_entry_is_a_hard_bounce_for_a_bouncing_member_by_default()
	{
		$this->recordInto('test-bounce-default');

		$entry = EmailBounceLogFactory::new()->create();
		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_email_bounce_log WHERE bounce_id = ?', $entry->bounce_id);
		$member = $this->app()->em()->find('XF:User', $row['user_id']);

		$this->assertSame(['bounce', 'hard'], [$row['message_type'], $row['action_taken']]);
		$this->assertSame('email_bounce', $member->user_state);
		$this->assertSame($member->email, $row['recipient']);
		$this->assertStringEndsWith('@' . substr(strrchr($member->email, '@'), 1), $row['recipient']);
		$this->assertMatchesRegularExpression('/^5\.\d\.\d$/', $row['status_code']);
		$this->assertSame('hard', (new \XF\EmailBounce\Parser('', ''))->getBounceTypeFromStatus($row['status_code']), 'XenForo reads it as hard');
		$this->assertStringContainsString("Final-Recipient: rfc822; {$member->email}", $row['raw_message']);
		$this->assertStringContainsString($row['status_code'], $row['raw_message']);
		$this->assertGreaterThanOrEqual((int) $row['email_date'], (int) $row['log_date']);
		$this->assertLessThanOrEqual(time(), (int) $row['log_date']);
		$this->assertGreaterThan(time() - 30 * 86400, (int) $row['log_date']);
		$this->assertGreaterThanOrEqual($member->register_date, (int) $row['email_date']);
	}

	public function test_soft_bounces_other_messages_and_unmatched_ones()
	{
		$this->recordInto('test-bounce-kinds');
		$member = UserFactory::new()->create();

		$soft = EmailBounceLogFactory::new()->for($member)->soft()->create();
		$this->assertSame(['soft', 'valid'], [$soft->action_taken, $member->user_state]);
		$this->assertSame('soft', (new \XF\EmailBounce\Parser('', ''))->getBounceTypeFromStatus($soft->status_code));

		$reply = EmailBounceLogFactory::new()->for($member)->messageType('autoreply')->create();
		$this->assertSame(['autoreply', '', null], [$reply->message_type, $reply->action_taken, $reply->status_code]);
		$this->assertStringContainsString('Auto-Submitted: auto-replied', $reply->raw_message);

		$delay = EmailBounceLogFactory::new()->for($member)->messageType('delay')->create();
		$this->assertSame(['delay', '', '4.4.7'], [$delay->message_type, $delay->action_taken, $delay->status_code]);

		$unmatched = EmailBounceLogFactory::new()->unmatched()->create();
		$this->assertSame([null, ''], [$unmatched->user_id, $unmatched->action_taken]);
		$this->assertStringEndsWith('@example.org', $unmatched->recipient);

		$this->expectExceptionMessage("not 'spam'");
		EmailBounceLogFactory::new()->messageType('spam');
	}

	public function test_the_seeders_fill_both_logs_and_cleaning_leaves_nothing()
	{
		$before = $this->counts();
		$created = [];

		foreach ([['SpamTrigger', ['registrations=4', 'messages=6', 'members=2']], ['EmailBounce', ['hard=2', 'soft=2', 'other=3']]] AS [$seeder, $params])
		{
			$tester = new CommandTester(new Run());
			$code = $tester->execute(
				['seeder' => $seeder, '--batch' => 'test-' . strtolower($seeder), '--param' => $params],
				['decorated' => false, 'capture_stderr_separately' => true]
			);
			$this->assertSame(0, $code, $tester->getErrorOutput());
			$created[$seeder] = json_decode($tester->getDisplay(), true)['created'];
		}

		$after = $this->counts();
		$this->assertSame($before['xf_spam_trigger_log'] + 10, $after['xf_spam_trigger_log']);
		// two of the four registrations rejected, and two of the six messages refused outright
		$results = array_count_values(array_column($created['SpamTrigger']['XF:SpamTriggerLog'], 'result'));
		ksort($results);
		$this->assertSame(['denied' => 4, 'moderated' => 6], $results);

		// two hard, one to three each for the two soft, and three others
		$bounces = $created['EmailBounce']['XF:EmailBounceLog'];
		$this->assertSame(count($bounces), $after['xf_email_bounce_log'] - $before['xf_email_bounce_log']);
		$this->assertCount(2, array_filter($bounces, function ($b) { return $b['action_taken'] === 'hard'; }));
		$soft = count(array_filter($bounces, function ($b) { return $b['action_taken'] === 'soft'; }));
		$this->assertGreaterThanOrEqual(2, $soft);
		$this->assertLessThanOrEqual(6, $soft);
		$this->assertCount(2 + $soft + 3, $bounces);
		$this->assertSame($before['xf_email_bounce_soft'], $after['xf_email_bounce_soft'], 'the soft bounce history is not written');
		$this->assertSame($before['xf_user_alert'], $after['xf_user_alert']);
		$this->assertSame($before['xf_moderator_log'], $after['xf_moderator_log']);
		$this->assertNoMailSent();

		foreach (['test-spamtrigger', 'test-emailbounce'] AS $batch)
		{
			$this->assertSame([], (new Cleaner())->clean($batch)['failed']);
		}
		$this->assertSame($before, $this->counts());
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
