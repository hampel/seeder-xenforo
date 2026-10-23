<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\FailedJobFactory;
use Hampel\Seeder\Factory\JobFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\JobDrain;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Failed jobs and queued jobs, written as rows inside a transaction.
 */
class JobTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @var Context|null */
	private $previous;

	/** @var int[] the highest id in each table before the test wrote any */
	private $before = ['xf_failed_job' => 0, 'xf_job' => 0];

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

	public function test_failed_jobs_throw_from_everywhere_a_reader_has_to_tell_apart()
	{
		$recorder = $this->recordInto('test-failed-jobs');

		FailedJobFactory::new()->count(200)->within(20)->secrets(0.3)->create();

		$rows = $this->rows('xf_failed_job');
		$this->assertCount(200, $rows);
		$this->assertSame(200, $recorder->counts()['xf_failed_job'], 'every row recorded');

		$root = realpath(\XF::getRootDirectory());
		$kinds = [];
		$signatures = [];
		$problems = [];
		foreach ($rows AS $row)
		{
			if ($row['fail_date'] > time() - FailedJobFactory::RECENT || $row['fail_date'] < time() - 20 * 86400)
			{
				$problems[] = "dated {$row['fail_date']}";
			}
			if (unserialize($row['execute_data']) !== [])
			{
				$problems[] = 'execute_data';
			}

			if (!preg_match('/^([\w\\\\]+): (.*?)(?: in (\/.+):(\d+)\nStack trace:\n#0 .*\{main\})?$/s', $row['exception'], $match))
			{
				$problems[] = "unreadable: {$row['exception']}";
				continue;
			}
			$file = $match[3] ?? '';
			if ($file === '')
			{
				$kinds['none'] = true;
			}
			else if (strpos($file, "{$root}/src/addons/") === 0)
			{
				$kinds[strpos($file, '/vendor/') !== false ? 'add-on vendor' : 'add-on'] = true;
				if (preg_match('#/(tests|phpunit|sebastian|mockery|hampel/xenforo-test-framework)/#', $file))
				{
					$problems[] = "a job failed in a test or a test library: {$file}";
				}
				if (strpos($file, '/Acme/') === false && is_file($file))
				{
					$kinds[strpos($file, '/vendor/') !== false ? 'real add-on vendor' : 'real add-on'] = true;
				}
			}
			else if (strpos($file, "{$root}/src/XF/Job/") === 0 && !is_file($file))
			{
				$problems[] = "XenForo's job in a file it does not have: {$file}";
			}
			else if (strpos($file, "{$root}/src/") === 0)
			{
				$kinds['core'] = true;
			}
			else if (strpos($file, $root) !== 0)
			{
				$kinds['outside'] = true;
			}
			$signatures[] = "{$row['execute_class']} {$match[1]} {$file}:" . ($match[4] ?? '');

			if (preg_match('/SECRET|hooks\.|api_key=|xox|eyJ|signing key/', $match[2]))
			{
				$kinds['secret'] = true;
				if (!preg_match('/SECRET|example\.com|signing key [0-9a-f]{40}$/', $match[2]))
				{
					$problems[] = "a credential that does not say it is made up: {$match[2]}";
				}
			}
		}

		$this->assertSame([], $problems);
		$this->assertEqualsCanonicalizing(['none', 'add-on', 'add-on vendor', 'real add-on', 'real add-on vendor', 'core', 'outside', 'secret'], array_keys($kinds));
		$counts = array_count_values($signatures);
		arsort($counts);
		$this->assertGreaterThanOrEqual(10, reset($counts), 'a fault that recurs');
		$this->assertGreaterThanOrEqual(30, count(array_filter($counts, function ($n) { return $n === 1; })), 'many that do not');
	}

	public function test_no_failure_is_from_the_last_day_and_the_last_day_cannot_be_asked_for()
	{
		$this->recordInto('test-failed-jobs-recent');
		FailedJobFactory::new()->count(50)->within(2)->create();

		$this->assertLessThanOrEqual(time() - FailedJobFactory::RECENT, max(array_column($this->rows('xf_failed_job'), 'fail_date')));

		$this->expectExceptionMessage('at least two days');
		FailedJobFactory::new()->within(1);
	}

	public function test_queued_jobs_wait_years_for_classes_that_do_not_exist()
	{
		$recorder = $this->recordInto('test-queued-jobs');

		JobFactory::new()->count(10)->manual(1)->retrying(0)->create();
		JobFactory::new()->count(10)->manual(0)->retrying(1)->create();

		$rows = $this->rows('xf_job');
		$this->assertCount(20, $rows);
		$this->assertSame(20, $recorder->counts()['xf_job']);

		$problems = [];
		foreach ($rows AS $row)
		{
			if ($row['trigger_date'] < time() + 4 * 365 * 86400) { $problems[] = 'due too soon'; }
			if (class_exists($row['execute_class'])) { $problems[] = "{$row['execute_class']} exists"; }
			if (strpos($row['unique_key'], JobFactory::KEY_PREFIX) !== 0) { $problems[] = 'unmarked'; }
		}
		$this->assertSame([], $problems);

		$manual = array_filter($rows, function ($row) { return $row['manual_execute']; });
		$this->assertCount(10, $manual);
		foreach ($manual AS $row)
		{
			$this->assertSame(0, (int) $row['attempts']);
			$this->assertNull($row['last_run_date']);
		}
		foreach (array_diff_key($rows, $manual) AS $row)
		{
			$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
			$this->assertLessThan(time(), (int) $row['last_run_date']);
		}

		// what XenForo asks before running a job or offering to: is it due
		$this->assertSame([], array_filter($rows, function ($row) { return $row['trigger_date'] <= time(); }), 'nothing due');
	}

	public function test_finishing_a_run_never_runs_a_seeded_job()
	{
		$this->recordInto('test-queued-drain');
		$drain = new JobDrain();
		$drain->start();

		JobFactory::new()->count(3)->create();

		$manager = \Mockery::mock($this->app()->jobManager())->makePartial();
		$manager->shouldNotReceive('runById');
		$this->app()->container()->set('job.manager', $manager);

		$drain->finish();
		$this->assertCount(3, $this->rows('xf_job'));
	}

	public function test_cleaning_removes_its_rows_and_never_anyone_elses()
	{
		$db = $this->app()->db();
		$this->recordInto('test-jobs-clean');

		FailedJobFactory::new()->count(5)->create();
		JobFactory::new()->count(5)->create();
		// a real failure and a real job arriving while the batch exists
		$db->insert('xf_failed_job', ['execute_class' => 'XF\Job\MailSend', 'execute_data' => serialize([]), 'exception' => 'RuntimeException: real', 'fail_date' => time()]);
		$failed = (int) $db->lastInsertId();
		$db->insert('xf_job', ['execute_class' => 'XF\Job\MailSend', 'execute_data' => serialize([]), 'manual_execute' => 0, 'trigger_date' => time() + 86400 * 400]);
		$job = (int) $db->lastInsertId();

		$result = (new Cleaner())->clean('test-jobs-clean');
		$this->assertSame([], $result['failed']);
		$this->assertSame(['xf_job' => 5, 'xf_failed_job' => 5], $result['removed']);

		$this->assertSame([$failed], array_map('intval', array_column($this->rows('xf_failed_job'), 'failed_job_id')));
		$this->assertSame([$job], array_map('intval', array_column($this->rows('xf_job'), 'job_id')));
	}

	public function test_the_seeder_writes_the_history_and_the_queue()
	{
		$this->before = ['xf_failed_job' => $this->highest('xf_failed_job'), 'xf_job' => $this->highest('xf_job')];
		[$code, $json, $summary] = $this->run_(['seeder' => 'Job', '--batch' => 'test-jobs-seeder', '--seed' => '3',
			'--param' => ['failed=25', 'days=10', 'secrets=50', 'queued=2', 'manual=1', 'retrying=1']]);
		$this->assertSame(0, $code, $summary);

		$result = json_decode($json, true);
		$this->assertSame(25, $result['counts']['xf_failed_job']);
		$this->assertSame(4, $result['counts']['xf_job']);
		$this->assertStringContainsString('none will run', $summary);
	}

	public function test_secrets_is_a_percentage()
	{
		[$code, , $summary] = $this->run_(['seeder' => 'Job', '--batch' => 'test-jobs-secrets', '--param' => ['secrets=150']]);

		$this->assertNotSame(0, $code);
		$this->assertStringContainsString('at most 100', $summary);
	}

	private function rows(string $table): array
	{
		$key = $table === 'xf_job' ? 'job_id' : 'failed_job_id';

		return $this->app()->db()->fetchAllKeyed("SELECT * FROM `{$table}` WHERE `{$key}` > ?", $key, $this->before[$table]);
	}

	private function highest(string $table): int
	{
		$key = $table === 'xf_job' ? 'job_id' : 'failed_job_id';

		return (int) $this->app()->db()->fetchOne("SELECT COALESCE(MAX(`{$key}`), 0) FROM `{$table}`");
	}

	private function recordInto(string $batch): CollectingRecorder
	{
		$recorder = new CollectingRecorder(new TableRecorder($batch));
		$this->before = ['xf_failed_job' => $this->highest('xf_failed_job'), 'xf_job' => $this->highest('xf_job')];
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
