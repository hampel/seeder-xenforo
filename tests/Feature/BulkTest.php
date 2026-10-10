<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\SessionActivityFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\EntityKey;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use XF\Util\Ip;

/**
 * Rows written in bulk - who is online - created for real inside a transaction.
 *
 * Kept small on purpose. Every page view on the forum writes to this table, so a test holding
 * locks on thousands of its rows is one a real visitor can deadlock with - and MySQL answers a
 * deadlock by ending the test's transaction, after which what it writes is permanent.
 */
class BulkTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLE = 'xf_session_activity';

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

	public function test_a_key_that_is_not_text_survives_being_recorded()
	{
		$binary = Ip::stringToBinary('10.200.3.4');
		$v6 = Ip::stringToBinary('2001:db8::1');

		foreach ([
			['user_id' => 0, 'unique_key' => $binary],
			['user_id' => 0, 'unique_key' => $v6],
			['user_id' => 12, 'unique_key' => '12'],
			['only' => $binary],
			['only' => '{looks like json'],
		] AS $key)
		{
			$encoded = EntityKey::encode($key);

			$this->assertSame(1, preg_match('//u', $encoded), 'what is stored must be text');
			$this->assertLessThanOrEqual(100, strlen($encoded), 'and must fit the column');
			$this->assertSame(count($key) === 1 ? reset($key) : $key, EntityKey::decode($encoded));
		}

		$this->assertSame('42', EntityKey::encode(['thread_id' => 42]), 'a plain single key is stored as it is');
	}

	public function test_rows_are_written_as_asked_for_and_each_is_recorded()
	{
		$recorder = $this->recordInto('test-online');
		$members = UserFactory::new()->count(3)->create();
		$before = $this->rows();

		$written = SessionActivityFactory::new()
			->members($members)
			->guests(200, 5)
			->robots(300, 4)
			->create();

		$this->assertSame(503, $written);
		$this->assertSame($before + 503, $this->rows());
		$this->assertDatabaseCount('xf_hampel_seeder_record', 503, ['batch' => 'test-online', 'entity' => 'XF:SessionActivity']);
		$this->assertSame(503, $recorder->counts()['XF:SessionActivity']);
		$this->assertArrayNotHasKey('XF:SessionActivity', $recorder->created(), 'bulk rows are counted, not listed');

		$db = $this->app()->db();
		$ids = $db->quote(array_values($members->pluckNamed('user_id')));
		$this->assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE user_id IN ({$ids}) AND unique_key = CAST(user_id AS CHAR) AND robot_key = ''"));

		$guests = "user_id = 0 AND robot_key = '' AND ORD(SUBSTRING(ip, 1, 1)) = 10 AND ORD(SUBSTRING(ip, 2, 1)) < 128";
		$robots = "user_id = 0 AND robot_key <> '' AND ORD(SUBSTRING(ip, 1, 1)) = 10 AND ORD(SUBSTRING(ip, 2, 1)) >= 128";
		$this->assertSame(200, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE {$guests} AND unique_key = ip"));
		$this->assertSame(300, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE {$robots} AND unique_key = ip"));
		$this->assertSame(5, (int) $db->fetchOne('SELECT COUNT(DISTINCT SUBSTRING(ip, 1, 2)) FROM ' . self::TABLE . " WHERE {$guests}"));
		$this->assertSame(4, (int) $db->fetchOne('SELECT COUNT(DISTINCT SUBSTRING(ip, 1, 2)) FROM ' . self::TABLE . " WHERE {$robots}"));

		$known = array_keys($this->app()->data('XF:Robot')->getRobotList());
		$this->assertSame([], array_diff($db->fetchAllColumn('SELECT DISTINCT robot_key FROM ' . self::TABLE . " WHERE {$robots}"), $known));

		$this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE ({$guests} OR {$robots}) AND view_date > ?", time() + 2));
		$this->assertSame(0, (int) $db->fetchOne(
			'SELECT COUNT(*) FROM ' . self::TABLE . " WHERE ({$guests} OR {$robots}) AND view_date < ?",
			time() - $this->app()->options()->onlineStatusTimeout * 60 - 5
		), 'with no stale share, everyone is inside the online window');
	}

	public function test_a_share_can_be_put_in_one_network_given_ipv6_addresses_or_made_stale()
	{
		$this->recordInto('test-online-shape');

		SessionActivityFactory::new()->robots(1000, 6, 0.8)->ipv6(0.1)->stale(0.25)->create();

		$db = $this->app()->db();
		$mine = "robot_key <> '' AND user_id = 0";
		$this->assertSame(100, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE {$mine} AND LENGTH(ip) = 16"));

		$networks = array_map('intval', $db->fetchAllColumn(
			'SELECT COUNT(*) c FROM ' . self::TABLE . " WHERE {$mine} AND LENGTH(ip) = 4 AND ORD(SUBSTRING(ip, 2, 1)) >= 128 GROUP BY SUBSTRING(ip, 1, 2) ORDER BY c DESC"
		));
		$this->assertCount(6, $networks);
		$this->assertSame(720, $networks[0], '80% of the 900 IPv4 addresses');
		$this->assertSame(180, array_sum(array_slice($networks, 1)));
		$this->assertSame(36, min($networks), 'and the rest spread evenly');

		$stale = (int) $db->fetchOne(
			'SELECT COUNT(*) FROM ' . self::TABLE . " WHERE {$mine} AND view_date < ?",
			time() - $this->app()->options()->onlineStatusTimeout * 60
		);
		$this->assertGreaterThan(180, $stale);
		$this->assertLessThan(320, $stale);
		$this->assertSame(0, (int) $db->fetchOne(
			'SELECT COUNT(*) FROM ' . self::TABLE . " WHERE {$mine} AND view_date < ?",
			time() - $this->app()->config()['sessionActivityExpiration']
		), 'stale, but not so old that XenForo would already have deleted it');
	}

	/**
	 * A guest's row is keyed by its address, so a seeded row at a real visitor's address would
	 * replace that visitor's - and then be deleted with the batch.
	 */
	public function test_a_row_already_in_the_table_is_left_alone_and_not_recorded()
	{
		$this->recordInto('test-online-collide');
		$db = $this->app()->db();

		// fifty visitors who were there first, at addresses the factory is about to be given
		$real = [];
		foreach (range(0, 49) AS $host)
		{
			$ip = pack('C4', 10, 0, 0, $host);
			$real[] = ['user_id' => 0, 'unique_key' => $ip, 'ip' => $ip, 'view_date' => 1700000000,
				'controller_name' => 'Real', 'controller_action' => 'Visitor', 'view_state' => 'valid', 'params' => '', 'robot_key' => ''];
		}
		$db->insertBulk(self::TABLE, $real);

		$factory = new class extends SessionActivityFactory
		{
			protected function addresses(array $spread, int $base): iterable
			{
				foreach (range(0, 49) AS $host)
				{
					yield pack('C4', 10, 0, 0, $host);
				}
				yield pack('C4', 10, 1, 0, 1);
			}
		};

		$written = $factory->guests(51, 1)->create();

		$this->assertSame(1, $written, 'only the address nobody had');
		$this->assertDatabaseCount('xf_hampel_seeder_record', 1, ['batch' => 'test-online-collide']);
		$this->assertSame(50, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE controller_name = 'Real' AND view_date = 1700000000"));

		$result = (new Cleaner())->clean('test-online-collide');

		$this->assertSame([], $result['failed']);
		$this->assertSame(50, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE controller_name = 'Real'"), 'cleaning took none of them');
	}

	public function test_cleaning_removes_exactly_the_rows_written_and_reports_those_already_gone()
	{
		$this->recordInto('test-online-clean');
		$members = UserFactory::new()->count(2)->create();
		$before = $this->rows();
		SessionActivityFactory::new()->members($members)->guests(1200, 3)->robots(900, 3)->create();
		$this->assertSame($before + 2102, $this->rows());

		// what XenForo's own hourly clean-up does to some of them in the meantime
		$pruned = $this->app()->db()->delete(self::TABLE, "robot_key <> '' AND user_id = 0 AND ORD(SUBSTRING(ip, 1, 1)) = 10 LIMIT 300");

		$result = (new Cleaner())->clean('test-online-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame(2102 - $pruned, $result['removed']['XF:SessionActivity']);
		$this->assertSame($pruned, $result['missing']['XF:SessionActivity']);
		$this->assertSame(2, $result['removed']['XF:User']);
		$this->assertSame($before, $this->rows());
		$this->assertDatabaseCount('xf_hampel_seeder_record', 0, ['batch' => 'test-online-clean']);
	}

	/**
	 * On a real forum a handful of crawlers account for nearly every robot online. The first
	 * version drew from all 1,355 XenForo knows with a gentle skew, and at 70,000 rows used
	 * nearly every one of them.
	 */
	public function test_robots_are_few_kinds_with_a_handful_taking_most_and_the_number_can_be_set()
	{
		$this->recordInto('test-online-robots');
		$db = $this->app()->db();
		$mine = "robot_key <> '' AND user_id = 0 AND ORD(SUBSTRING(ip, 1, 1)) = 10";

		SessionActivityFactory::new()->robots(4000, 8)->create();

		$counts = array_map('intval', $db->fetchPairs('SELECT robot_key, COUNT(*) c FROM ' . self::TABLE . " WHERE {$mine} GROUP BY 1 ORDER BY c DESC"));
		$this->assertLessThanOrEqual(40, count($counts));
		$this->assertGreaterThan(25, count($counts), 'at this size nearly every kind should turn up');
		$this->assertGreaterThan(2400, array_sum(array_slice($counts, 0, 5)), 'the first five take about two in three');
		$this->assertSame('google', array_key_first($counts), 'the commonest crawler XenForo itself knows comes first');

	}

	public function test_the_number_of_kinds_of_robot_can_be_set()
	{
		$this->recordInto('test-online-robot-kinds');
		$db = $this->app()->db();
		$mine = "robot_key <> '' AND user_id = 0 AND ORD(SUBSTRING(ip, 1, 1)) = 10";
		$known = count($this->app()->data('XF:Robot')->getRobotList());

		SessionActivityFactory::new()->robots(4000, 8)->robotKinds(3)->create();
		$this->assertSame(3, (int) $db->fetchOne('SELECT COUNT(DISTINCT robot_key) FROM ' . self::TABLE . " WHERE {$mine}"));

		// more than the forum knows is every one it knows
		SessionActivityFactory::new()->robots(4000, 8)->robotKinds($known + 500)->create();
		$kinds = (int) $db->fetchOne('SELECT COUNT(DISTINCT robot_key) FROM ' . self::TABLE . " WHERE {$mine}");
		$this->assertGreaterThan(40, $kinds);
		$this->assertLessThanOrEqual($known, $kinds);

		$this->expectException(\InvalidArgumentException::class);
		SessionActivityFactory::new()->robotKinds(0);
	}

	public function test_bad_shapes_are_refused()
	{
		foreach ([
			function () { SessionActivityFactory::new()->guests(10, 0); },
			function () { SessionActivityFactory::new()->robots(10, 129); },
			function () { SessionActivityFactory::new()->guests(-1); },
			function () { SessionActivityFactory::new()->guests(10, 5, 1.5); },
			function () { SessionActivityFactory::new()->ipv6(2); },
			function () { $this->recordInto('test-online-bad'); SessionActivityFactory::new()->robots(70000, 1)->create(); },
		] AS $index => $bad)
		{
			try
			{
				$bad();
				$this->fail("shape {$index} was accepted");
			}
			catch (\InvalidArgumentException $e)
			{
				$this->addToAssertionCount(1);
			}
		}
	}

	public function test_the_online_seeder_reports_counts_and_lists_only_the_members()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'Online', '--batch' => 'test-online-seeder', '--param' => ['members=2', 'guests=40', 'robots=60', 'networks=4', 'top=50', 'ipv6=10', 'stale=0']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$result = json_decode($tester->getDisplay(), true);
		$this->assertSame(['XF:User' => 2, 'XF:SessionActivity' => 102], $result['counts']);
		$this->assertSame(['XF:User'], array_keys($result['created']));
		$this->assertStringContainsString('created  XF:SessionActivity x 102', $tester->getErrorOutput());
	}

	private function rows(): int
	{
		return (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span('90d'), 'password'));

		return $recorder;
	}
}
