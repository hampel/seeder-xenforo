<?php namespace Hampel\Seeder\Record;

use Hampel\Seeder\Seed\ThreadSeeder;
use Hampel\Seeder\Timeline;
use XF\Install\Data\MySql;

/**
 * Seeds a small forum, removes it, and compares the row count of every table before and after.
 * This is the proof that cleaning leaves nothing behind: a table nobody thought to list shows up
 * here as a count that did not come back.
 *
 * A difference in one of XenForo's own tables is a leak. A difference in any other table is
 * reported apart, since another add-on that logs job runs or page views will move by itself.
 */
class RoundTrip
{
	/**
	 * Core tables that move while a forum is merely running, whoever is using it.
	 */
	public const VOLATILE = [
		'xf_admin_log', 'xf_attachment_view', 'xf_captcha_log', 'xf_data_registry', 'xf_error_log',
		'xf_flood_check', 'xf_image_proxy', 'xf_job', 'xf_link_proxy', 'xf_login_attempt', 'xf_search',
		'xf_session', 'xf_session_activity', 'xf_session_admin', 'xf_thread_view',
	];

	private const PARAMS = ['threads' => 8, 'replies' => 2, 'authors' => 3, 'prefix' => 1];

	/**
	 * @return array [
	 *     'batch' => string,
	 *     'created' => [shortName => int],
	 *     'seed_failure' => string|null,
	 *     'removed' => int, 'failed' => [...] as Cleaner::clean() reports them,
	 *     'tables' => int how many tables were compared,
	 *     'leaks' => [table => [before, after]] XenForo's own tables that did not come back,
	 *     'other' => [table => [before, after]] other add-ons' tables that differ,
	 *     'errors' => string[] server error log entries written during the round trip,
	 *     'seconds' => float,
	 * ]
	 */
	public function run(): array
	{
		$start = microtime(true);
		$db = \XF::db();
		$batch = 'validate-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

		$lastError = (int) $db->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log');
		$before = $this->counts();

		$seeded = $this->seed($batch);
		$cleaned = $this->clean($batch);

		$after = $this->counts();

		[$leaks, $other] = self::compare($before, $after, array_keys((new MySql())->getTables()));

		$created = [];
		foreach ($seeded['created'] AS $entity => $list)
		{
			$created[$entity] = count($list);
		}

		return [
			'batch' => $batch,
			'created' => $created,
			'seed_failure' => $seeded['failure']
				? get_class($seeded['failure']) . ': ' . strtok($seeded['failure']->getMessage(), "\n") : null,
			'removed' => array_sum($cleaned['removed']),
			'failed' => $cleaned['failed'],
			'tables' => count($after),
			'leaks' => $leaks,
			'other' => $other,
			'errors' => $db->fetchAllColumn(
				'SELECT LEFT(message, 160) FROM xf_error_log WHERE error_id > ? ORDER BY error_id',
				$lastError
			),
			'seconds' => round(microtime(true) - $start, 1),
		];
	}

	/**
	 * Sort the tables whose counts differ into XenForo's own, which are leaks, and everyone
	 * else's, which are reported apart. Tables that move while a forum merely runs are ignored.
	 *
	 * @param array $before [table => rows]
	 * @param array $after [table => rows]
	 * @param string[] $coreTables
	 *
	 * @return array [leaks, other], each [table => [before, after]]
	 */
	public static function compare(array $before, array $after, array $coreTables): array
	{
		$core = array_fill_keys($coreTables, true);
		$leaks = [];
		$other = [];

		foreach ($after + array_fill_keys(array_keys($before), 0) AS $table => $count)
		{
			$was = $before[$table] ?? 0;
			if ($was === $count || in_array($table, self::VOLATILE, true))
			{
				continue;
			}

			if (isset($core[$table]) || $table === TableRecorder::TABLE)
			{
				$leaks[$table] = [$was, $count];
			}
			else
			{
				$other[$table] = [$was, $count];
			}
		}

		return [$leaks, $other];
	}

	protected function seed(string $batch): array
	{
		return (new SeedRunner())->run(ThreadSeeder::class, self::PARAMS, Timeline::span(Timeline::DEFAULT_SPAN), null, $batch);
	}

	protected function clean(string $batch): array
	{
		return (new Cleaner())->clean($batch);
	}

	/**
	 * @return array [table => rows] for every table with XenForo's prefix
	 */
	protected function counts(): array
	{
		$db = \XF::db();
		$counts = [];
		foreach ($db->fetchAllColumn("SHOW TABLES LIKE 'xf\\_%'") AS $table)
		{
			$counts[$table] = (int) $db->fetchOne("SELECT COUNT(*) FROM `{$table}`");
		}

		return $counts;
	}
}
