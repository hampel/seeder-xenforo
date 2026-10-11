<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Config;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Seed\ErrorLogSeeder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Server error log entries, written as data inside a transaction.
 */
class ErrorLogTest extends TestCase
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
		ErrorLogFactory::quietFor(ErrorLogFactory::MINIMUM_QUIET);
		$this->previous = Context::use(null);
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);
		ErrorLogFactory::quietFor(ErrorLogFactory::MINIMUM_QUIET);

		parent::tearDown();
	}

	/**
	 * Things that report new errors look at the last hour or so. A seeded entry inside that would
	 * be posted to wherever they post, once for every entry.
	 */
	public function test_no_entry_is_dated_inside_the_quiet_period_whatever_was_asked_for()
	{
		// a span so short that every date the timeline gives is inside the quiet period
		$this->recordInto('test-errors-quiet', '30h');

		$entries = ErrorLogFactory::new()->count(200)->create();
		$asked = ErrorLogFactory::new()->create(['exception_date' => time() - 60]);
		$future = ErrorLogFactory::new()->create(['exception_date' => time() + 86400]);

		$latest = time() - ErrorLogFactory::MINIMUM_QUIET;
		foreach (array_merge($entries->toArray(), [$asked, $future]) AS $entry)
		{
			$this->assertLessThanOrEqual($latest, $entry->exception_date);
		}

		$mine = $this->app()->db()->quote(array_merge(array_values($entries->pluckNamed('error_id')), [$asked->error_id, $future->error_id]));
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$mine}) AND exception_date > ?", time() - 3600
		), 'none is among the last hour, which is what a poller for new errors reads');
	}

	public function test_the_quiet_period_can_be_lengthened_and_never_shortened()
	{
		ErrorLogFactory::quietFor(60);
		$this->assertSame(ErrorLogFactory::MINIMUM_QUIET, ErrorLogFactory::quiet());

		ErrorLogFactory::quietFor(6 * 3600);
		$this->recordInto('test-errors-longer', '30h');
		$entry = ErrorLogFactory::new()->create(['exception_date' => time() - 3 * 3600]);
		$this->assertLessThanOrEqual(time() - 6 * 3600, $entry->exception_date);

		$this->setConfig('hampelSeeder', ['errorLogQuietHours' => 1]);
		$this->assertSame(2, Config::errorLogQuietHours());
		$this->setConfig('hampelSeeder', ['errorLogQuietHours' => 12]);
		$this->assertSame(12, Config::errorLogQuietHours());
		$this->setConfig('hampelSeeder', ['errorLogQuietHours' => 'soon']);
		$this->assertSame(2, Config::errorLogQuietHours());
	}

	public function test_each_origin_has_the_request_state_and_trace_that_mark_it()
	{
		$this->recordInto('test-errors-origin');
		$raw = function ($entry)
		{
			return $this->app()->db()->fetchRow('SELECT request_state, trace_string, HEX(ip_address) ip FROM xf_error_log WHERE error_id = ?', $entry->error_id);
		};

		$web = $raw(ErrorLogFactory::new()->web()->create());
		$this->assertStringStartsWith('{"url":', $web['request_state']);
		$this->assertSame(['url', 'referrer', '_GET', '_POST'], array_keys(json_decode($web['request_state'], true)));
		$this->assertStringContainsString('src/XF/Mvc/Dispatcher.php(', $web['trace_string']);
		$this->assertStringNotContainsString('src/XF/Job/Manager.php(', $web['trace_string']);
		$this->assertSame(8, strlen($web['ip']));

		$cli = $raw(ErrorLogFactory::new()->cli()->create());
		$this->assertStringStartsWith('{"cli":', $cli['request_state']);
		$this->assertStringContainsString('cmd.php(', $cli['trace_string']);
		$this->assertSame('', $cli['ip']);

		$job = $raw(ErrorLogFactory::new()->job()->create());
		$this->assertStringStartsWith('{"url":', $job['request_state'], 'a job has no request state of its own');
		$this->assertStringContainsString('src/XF/Job/Manager.php(', $job['trace_string']);

		$this->assertSame('[]', $raw(ErrorLogFactory::new()->unknownOrigin()->create())['request_state']);

		$this->assertMatchesRegularExpression('/^#0 \S+\(\d+\): \S+\n#1 /', $web['trace_string']);
		$this->assertStringEndsWith('{main}', $web['trace_string']);
	}

	public function test_the_kinds_of_fault_a_reader_has_to_tell_apart()
	{
		$this->recordInto('test-errors-kinds');
		$member = UserFactory::new()->create();
		$factory = ErrorLogFactory::new();

		$addOn = $factory->inAddOn('Vendor/AddOn')->create();
		$this->assertSame('src/addons/Vendor/AddOn/Service/Example.php', $addOn->filename);
		$this->assertStringStartsWith('#0 src/addons/Vendor/AddOn/Service/Example.php(', $addOn->trace_string);

		$core = $factory->inCore()->create();
		$this->assertStringStartsWith('src/XF/', $core->filename);
		$this->assertStringNotContainsString('src/addons/', $core->trace_string);

		$this->assertStringStartsWith('[E_DEPRECATED] ', $factory->php('E_DEPRECATED')->create()->message);
		$this->assertSame('ErrorException', $factory->php()->make()->exception_type);
		$this->assertNotSame($factory->php('E_WARNING')->make()->line, $factory->php('E_NOTICE')->make()->line, 'two levels are two faults');
		$this->assertStringStartsWith('Fatal Error: ', $factory->fatal()->create()->message);

		$template = $factory->inTemplate('public:thread_view')->create();
		$this->assertSame('internal_data/code_cache/templates/l1/s0/public/thread_view.php', $template->filename);
		$this->assertStringStartsWith('Template error: [E_WARNING] ', $template->message);

		$missing = $factory->missingClass('Vendor/AddOn')->create();
		$this->assertStringStartsWith('src/XF/', $missing->filename);
		$this->assertStringContainsString('Vendor\AddOn\XF\Entity\User', $missing->message);
		$this->assertStringContainsString('src/addons/Vendor/AddOn/vendor/composer/', $factory->missingAutoload('Vendor/AddOn')->create()->message);

		$this->assertStringStartsWith('/', $factory->outside()->create()->filename);

		$drawn = array_unique($factory->count(300)->make()->pluckNamed('filename'));
		$this->assertSame([], preg_grep('/\.(less|css)\.php$/', $drawn), 'template errors are in page templates, not stylesheets');

		$named = $factory->forUser($member)->create();
		$this->assertSame($member->user_id, $named->user_id);
		$this->assertSame(0, $factory->cli()->create()->user_id);

		foreach (['not a template', 'public:', 'public:../../etc'] AS $bad)
		{
			try
			{
				$factory->inTemplate($bad);
				$this->fail("'{$bad}' was accepted as a template");
			}
			catch (\InvalidArgumentException $e)
			{
				$this->addToAssertionCount(1);
			}
		}
	}

	public function test_a_log_has_faults_that_recur_and_a_mix_of_origins_and_cleans_away()
	{
		$this->recordInto('test-errors-log');
		$db = $this->app()->db();
		$before = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_error_log');

		$entries = ErrorLogFactory::new()->count(400)->create();
		$mine = $db->quote(array_values($entries->pluckNamed('error_id')));

		$signatures = array_map('intval', $db->fetchAllColumn(
			"SELECT COUNT(*) c FROM xf_error_log WHERE error_id IN ({$mine}) GROUP BY exception_type, filename, line ORDER BY c DESC"
		));
		$this->assertGreaterThan(8, count($signatures));
		$this->assertGreaterThan(150, array_sum(array_slice($signatures, 0, 3)), 'a few faults account for most entries');

		$origins = $db->fetchPairs("SELECT LEFT(request_state, 7), COUNT(*) FROM xf_error_log WHERE error_id IN ({$mine}) GROUP BY 1");
		$this->assertSame(['[]', '{"cli":', '{"url":'], array_keys($origins));
		$this->assertGreaterThan(20, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$mine}) AND trace_string LIKE '%src/XF/Job/Manager.php(%'"));
		$this->assertGreaterThan(20, (int) $db->fetchOne("SELECT COUNT(DISTINCT FROM_UNIXTIME(exception_date, '%Y-%m-%d')) FROM xf_error_log WHERE error_id IN ({$mine})"));

		$this->assertNoMailSent();

		$result = (new Cleaner())->clean('test-errors-log');

		$this->assertSame([], $result['failed']);
		$this->assertSame(['XF:ErrorLog' => 400], $result['removed']);
		$this->assertSame($before, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_error_log'));
	}

	/**
	 * A job's class fails in jobs and a template fails on a page. With origin drawn apart from
	 * the fault, every fault had the same mix and a breakdown by origin said nothing.
	 */
	public function test_each_fault_comes_mostly_from_where_it_would()
	{
		$this->recordInto('test-errors-usual');
		$db = $this->app()->db();
		$entries = ErrorLogFactory::new()->count(1500)->create();
		$mine = $db->quote(array_values($entries->pluckNamed('error_id')));

		$share = function (string $files, string $origin) use ($db, $mine)
		{
			$is = ['job' => "trace_string LIKE '%src/XF/Job/Manager.php(%'",
				'cli' => "request_state LIKE '{\"cli\":%'",
				'web' => "request_state LIKE '{\"url\":%' AND trace_string NOT LIKE '%src/XF/Job/Manager.php(%'"][$origin];
			$all = (int) $db->fetchOne("SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$mine}) AND filename LIKE ?", $files);
			$this->assertGreaterThan(0, $all, "no entries in {$files}");

			return (int) $db->fetchOne("SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$mine}) AND filename LIKE ? AND {$is}", $files) / $all;
		};

		$this->assertGreaterThan(0.75, $share('src/addons/%/Job/Process.php', 'job'));
		$this->assertLessThan(1.0, $share('src/addons/%/Job/Process.php', 'job'), 'but not every time');
		$this->assertGreaterThan(0.75, $share('src/addons/%/Cli/Command/Import.php', 'cli'));
		$this->assertGreaterThan(0.75, $share('src/addons/%/Service/Sync.php', 'web'));
		$this->assertEquals(1.0, $share('internal_data/code_cache/templates/%', 'web'), 'a template is only ever rendered for a page');

		// asking for an origin still gets it, whatever the fault
		$this->assertStringStartsWith('{"cli":', $db->fetchOne(
			'SELECT request_state FROM xf_error_log WHERE error_id = ?', ErrorLogFactory::new()->inTemplate()->cli()->create()->error_id
		));
		$this->assertStringContainsString('src/XF/Job/Manager.php(', ErrorLogFactory::new()->inAddOn('Vendor/AddOn')->job()->create()->trace_string);
	}

	public function test_the_seeder_names_members_on_some_entries_and_tells_who_will_read_them()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'ErrorLog', '--batch' => 'test-errors-seeder', '--seed' => '3', '--param' => ['errors=120', 'members=3']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$result = json_decode($tester->getDisplay(), true);
		$this->assertSame(['XF:User' => 3, 'XF:ErrorLog' => 120], $result['counts']);
		$this->assertSame(['id', 'type', 'file', 'line'], array_keys($result['created']['XF:ErrorLog'][0]));

		$ids = $this->app()->db()->quote(array_column($result['created']['XF:ErrorLog'], 'id'));
		$named = (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$ids}) AND user_id > 0");
		$this->assertGreaterThan(10, $named);
		$this->assertLessThan(60, $named);
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_error_log WHERE error_id IN ({$ids}) AND user_id > 0 AND request_state NOT LIKE '{\"url\":%'"
		), 'only a page view has a member');

	}

	public function test_whoever_runs_it_is_told_which_add_ons_will_report_on_the_seeded_entries()
	{
		$this->withActiveAddOns();
		$this->assertSame([], ErrorLogSeeder::activeReaders());
		$this->assertSame([], (new ErrorLogSeeder())->notices());

		$this->withActiveAddOns(['Hampel/EventLogging', 'Hampel/Slack']);
		$this->assertSame(['Hampel/EventLogging'], array_keys(ErrorLogSeeder::activeReaders()), 'Slack is kept out by the quiet period, so it is not listed');

		$tester = new CommandTester(new Run());
		$tester->execute(
			['seeder' => 'ErrorLog', '--batch' => 'test-errors-notice', '--param' => ['errors=1', 'members=0']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertStringContainsString('Hampel/EventLogging is active: every seeded error will be forwarded', $tester->getErrorOutput());
		$this->assertStringNotContainsString('LogDigest', $tester->getErrorOutput());
		$this->assertLessThan(
			strpos($tester->getErrorOutput(), 'created  XF:ErrorLog'),
			strpos($tester->getErrorOutput(), 'is active'),
			'said before anything is created'
		);
	}

	private function recordInto(string $batch, string $span = '90d'): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		Context::use(new Context(Context::faker('en_US', 1), $recorder, Timeline::span($span), 'password'));

		return $recorder;
	}
}
