<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ForumFactory;
use Hampel\Seeder\Factory\IpHistory;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\Util\Ip;

/**
 * IP log rows for members, threads and replies, created for real inside a transaction.
 */
class IpLogTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const DOCUMENTATION = '/^(192\.0\.2|198\.51\.100|203\.0\.113)\.\d+$|^2001:db8:/';

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

	public function test_a_member_has_registered_logged_in_and_changed_their_account_from_their_own_addresses()
	{
		$this->recordInto('test-ip-member');

		$member = UserFactory::new()->withIpLog(6)->create();
		$rows = $this->rowsFor($member->user_id);
		$addresses = IpHistory::addressesFor(Context::current(), $member->user_id);
		$end = min($member->last_activity, time());

		$this->assertSame(['register'], array_column(array_filter($rows, function ($row) { return $row['action'] === 'register'; }), 'action'));
		$register = $rows[0];
		$this->assertSame('register', $register['action'], 'the first row is the registration');
		$this->assertSame($member->register_date, (int) $register['log_date']);
		$this->assertSame($addresses[0], $register['address']);

		$logins = array_filter($rows, function ($row) { return in_array($row['action'], ['login', 'cookie_login'], true); });
		$this->assertCount(6, $logins);
		$this->assertSame($end, max(array_map('intval', array_column($logins, 'log_date'))), 'the last login is the last activity');
		$this->assertSame([], array_column(array_filter($rows, function ($row) { return $row['action'] === 'login_admin'; }), 'action'), 'not an administrator');

		$known = ['register', 'login', 'cookie_login', 'password_change', 'email_change', 'preferences_edit', 'account_details_edit', 'privacy_edit', 'signature_edit'];
		$problems = [];
		foreach ($rows AS $row)
		{
			if (!in_array($row['action'], $known, true)) { $problems[] = "action {$row['action']}"; }
			if ($row['content_type'] !== 'user' || (int) $row['content_id'] !== $member->user_id) { $problems[] = 'not the member'; }
			if ((int) $row['log_date'] < $member->register_date || (int) $row['log_date'] > $end) { $problems[] = "dated {$row['log_date']}"; }
			if (!in_array($row['address'], $addresses, true)) { $problems[] = "address {$row['address']}"; }
		}
		$this->assertSame([], $problems);

		foreach ($addresses AS $address)
		{
			$this->assertMatchesRegularExpression(self::DOCUMENTATION, $address);
		}
		$described = UserFactory::describe($member);
		$this->assertContains('ip_log', $described['has']);
		$this->assertSame($addresses[0], $described['addresses'][0], 'the address used most first');
		$this->assertEqualsCanonicalizing(array_values(array_unique(array_column($rows, 'address'))), $described['addresses']);
	}

	public function test_an_administrator_has_logged_in_to_the_control_panel_too()
	{
		$this->recordInto('test-ip-admin');

		// asked for in either order, the log knows the member is an administrator
		$admins = [
			UserFactory::new()->withIpLog(9)->administrator()->create(),
			UserFactory::new()->administrator()->withIpLog(9)->create(),
		];

		foreach ($admins AS $admin)
		{
			$actions = array_count_values(array_column($this->rowsFor($admin->user_id), 'action'));
			$this->assertGreaterThanOrEqual(1, $actions['login_admin'] ?? 0);
			$this->assertLessThanOrEqual(3, $actions['login_admin']);
		}
	}

	public function test_members_of_a_run_share_some_addresses_and_some_have_several()
	{
		// an address source that never repeats itself, so any address two members share was shared
		// on purpose
		$faker = Context::faker('en_US', 1);
		$faker->addProvider(new class {
			private $next = 0;

			public function documentationIp(): string
			{
				$this->next++;

				return ['192.0.2', '198.51.100', '203.0.113'][intdiv($this->next, 254)] . '.' . ($this->next % 254 + 1);
			}
		});
		$context = new Context($faker, new NullRecorder(), Timeline::span('90d'), 'password');

		$byAddress = [];
		$several = 0;
		for ($userId = 1; $userId <= 40; $userId++)
		{
			$addresses = IpHistory::addressesFor($context, $userId);
			$this->assertSame($addresses, IpHistory::addressesFor($context, $userId), 'a member keeps their addresses for the run');
			$several += count($addresses) > 1 ? 1 : 0;
			foreach ($addresses AS $address)
			{
				$byAddress[$address][] = $userId;
			}
		}

		$this->assertNotEmpty(array_filter($byAddress, function ($ids) { return count($ids) > 1; }), 'an address several members share');
		$this->assertGreaterThan(0, $several, 'a member on several addresses');
		$this->assertNotSame(
			IpHistory::addressesFor($context, 1),
			IpHistory::addressesFor(new Context(Context::faker('en_US', 9), new NullRecorder(), Timeline::span('90d'), 'password'), 1),
			'another run, other addresses'
		);
	}

	public function test_addresses_are_from_the_documentation_ranges_some_of_them_ipv6()
	{
		$faker = Context::faker('en_US', 1);

		$kinds = [];
		for ($i = 0; $i < 200; $i++)
		{
			$address = $faker->documentationIp(0.5);
			$this->assertMatchesRegularExpression(self::DOCUMENTATION, $address);
			$this->assertNotFalse(Ip::stringToBinary($address, false), $address);
			$kinds[strpos($address, ':') !== false ? 'ipv6' : 'ipv4'] = true;
		}
		$this->assertSame(['ipv4', 'ipv6'], array_keys(array_filter(['ipv4' => isset($kinds['ipv4']), 'ipv6' => isset($kinds['ipv6'])])));
		$this->assertStringNotContainsString(':', $faker->documentationIp(0.0));
	}

	public function test_a_member_logs_in_at_least_once()
	{
		$this->expectExceptionMessage('logged in at least once');
		UserFactory::new()->withIpLog(0);
	}

	public function test_threads_and_replies_log_their_address_as_xenforo_does()
	{
		$this->recordInto('test-ip-posts');
		$author = UserFactory::new()->withIpLog()->create();
		$node = ForumFactory::new()->create();

		$thread = ThreadFactory::new()->for($node)->withIp()->create(['user_id' => $author->user_id]);
		$replies = PostFactory::new()->for($thread)->withIp()->count(2)->create(['user_id' => $author->user_id]);
		$plain = ThreadFactory::new()->for($node)->create(['user_id' => $author->user_id]);

		$addresses = IpHistory::addressesFor(Context::current(), $author->user_id);
		$postIds = array_merge([$thread->first_post_id], array_map(function ($post) { return $post->post_id; }, $replies->toArray()));
		foreach ($postIds AS $postId)
		{
			$post = $this->app()->em()->find('XF:Post', $postId);
			$row = $this->app()->db()->fetchRow("SELECT * FROM xf_ip WHERE content_type = 'post' AND content_id = ?", $postId);

			$this->assertNotFalse($row, "a row for post {$postId}");
			$this->assertSame($post->ip_id, (int) $row['ip_id'], 'the post points at it');
			$this->assertSame($author->user_id, (int) $row['user_id']);
			$this->assertSame('', $row['action']);
			$this->assertSame($post->post_date, (int) $row['log_date']);
			$this->assertContains(Ip::binaryToString($row['ip']), $addresses);
		}

		$this->assertSame(0, $this->app()->em()->find('XF:Post', $plain->first_post_id)->ip_id);
		$this->assertDatabaseMissing('xf_ip', ['content_type' => 'post', 'content_id' => $plain->first_post_id]);
		$this->assertDatabaseMissing('xf_ip', ['content_type' => 'thread']);
	}

	public function test_cleaning_removes_every_row_even_for_an_author_who_stays()
	{
		$db = $this->app()->db();
		$before = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip');

		// a member who is not part of the batch, as a real one posting in a seeded thread would be
		Context::use(new Context(Context::faker('en_US', 2), new NullRecorder(), Timeline::span('90d'), 'password'));
		$stays = UserFactory::new()->create();

		$this->recordInto('test-ip-clean');
		$node = ForumFactory::new()->create();
		$thread = ThreadFactory::new()->for($node)->withIp()
			->has(PostFactory::new()->withIp()->count(2)->state(['user_id' => $stays->user_id]))
			->create(['user_id' => $stays->user_id]);
		UserFactory::new()->withIpLog()->administrator()->withLogin()->count(2)->create();
		$this->assertGreaterThan($before + 3, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip'));

		$this->assertSame([], (new Cleaner())->clean('test-ip-clean')['failed']);

		$this->assertSame($before, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ip'));
		$this->assertNotNull($this->app()->em()->find('XF:User', $stays->user_id), 'the author was not the batch\'s to remove');
	}

	public function test_the_thread_and_user_seeders_log_addresses_when_asked()
	{
		$db = $this->app()->db();

		[$code, $json, $summary] = $this->run_(['seeder' => 'Thread', '--batch' => 'test-ip-thread-seeder', '--seed' => '3',
			'--param' => ['threads=3', 'replies=2', 'authors=2', 'prefix=0', 'ips=1']]);
		$this->assertSame(0, $code, $summary);
		$created = json_decode($json, true)['created'];
		$postIds = array_merge(array_column($created['XF:Thread'], 'first_post_id'), array_column($created['XF:Post'] ?? [], 'id'));
		$this->assertSame(count($postIds), (int) $db->fetchOne("SELECT COUNT(*) FROM xf_ip WHERE content_type = 'post' AND content_id IN (" . $db->quote($postIds) . ')'));
		foreach ($created['XF:User'] AS $author)
		{
			$this->assertContains('ip_log', $author['has']);
			$this->assertNotEmpty($author['addresses']);
		}

		[$code, $json, $summary] = $this->run_(['seeder' => 'User', '--batch' => 'test-ip-user-seeder', '--seed' => '3',
			'--param' => ['users=3', 'admins=1', 'ips=1']]);
		$this->assertSame(0, $code, $summary);
		$users = json_decode($json, true)['created']['XF:User'];
		$this->assertCount(4, $users);
		foreach ($users AS $user)
		{
			$this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_ip WHERE user_id = ? AND action = 'register'", $user['id']));
		}
		$this->assertSame(1, (int) $db->fetchOne(
			"SELECT COUNT(DISTINCT user_id) FROM xf_ip WHERE action = 'login_admin' AND user_id IN (" . $db->quote(array_column($users, 'id')) . ')'
		));

		[$code] = $this->run_(['seeder' => 'User', '--batch' => 'test-ip-user-plain', '--param' => ['users=2']]);
		$this->assertSame(0, $code);
		$this->assertSame(0, (int) $db->fetchOne(
			'SELECT COUNT(*) FROM xf_ip i JOIN xf_hampel_seeder_record r ON r.entity_id = i.user_id AND r.entity = ? WHERE r.batch = ?',
			['XF:User', 'test-ip-user-plain']
		), 'nothing unless asked');
	}

	private function rowsFor(int $userId): array
	{
		$rows = $this->app()->db()->fetchAll("SELECT * FROM xf_ip WHERE user_id = ? AND content_type = 'user' ORDER BY log_date, ip_id", $userId);
		foreach ($rows AS &$row)
		{
			$row['address'] = Ip::binaryToString($row['ip']);
		}

		return $rows;
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
