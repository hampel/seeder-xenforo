<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\WarningDefinitionFactory;
use Hampel\Seeder\Factory\WarningFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Warning definitions and warnings, created for real inside a transaction.
 */
class WarningTest extends TestCase
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

	public function test_a_definition_has_its_three_phrases_and_cleaning_removes_them()
	{
		$this->recordInto('test-definition');
		$phrases = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_phrase');

		$definition = WarningDefinitionFactory::new()->points(4)->create(['title' => 'Seedertest reason']);
		$id = $definition->warning_definition_id;

		$this->assertDatabaseHas('xf_warning_definition', ['warning_definition_id' => $id, 'points_default' => 4, 'expiry_type' => 'months']);
		$this->assertDatabaseHas('xf_phrase', ['title' => "warning_title.{$id}", 'phrase_text' => 'Seedertest reason', 'language_id' => 0]);
		$this->assertDatabaseHas('xf_phrase', ['title' => "warning_conv_title.{$id}", 'phrase_text' => 'Warning: {title}']);
		$this->assertDatabaseHas('xf_phrase', ['title' => "warning_conv_text.{$id}"]);
		$this->assertSame('never', WarningDefinitionFactory::new()->permanent()->make()->expiry_type);

		(new Cleaner())->clean('test-definition');

		$this->assertDatabaseMissing('xf_warning_definition', ['warning_definition_id' => $id]);
		$this->assertSame($phrases, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_phrase'));
	}

	public function test_a_warning_on_a_post_is_applied_as_xenforo_applies_one_and_tells_nobody()
	{
		$this->recordInto('test-warning');
		$post = PostFactory::new()->create(['post_date' => time() - 86400 * 20]);
		$moderator = UserFactory::new()->create(['is_moderator' => true]);
		$definition = WarningDefinitionFactory::new()->create(['title' => 'Seedertest reason', 'points_default' => 3, 'expiry_default' => 2]);
		$quiet = $this->counts(['xf_moderator_log', 'xf_conversation_master', 'xf_user_alert', 'xf_ip']);

		$warning = WarningFactory::new()->for($post)->by($moderator)->recycle($definition)->create();

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_warning WHERE warning_id = ?', $warning->warning_id);
		$this->assertSame(
			[
				'content_type' => 'post', 'content_id' => $post->post_id, 'user_id' => $post->user_id,
				'warning_user_id' => $moderator->user_id, 'warning_definition_id' => $definition->warning_definition_id,
				'title' => 'Seedertest reason', 'points' => 3, 'is_expired' => 0,
			],
			array_intersect_key($row, array_flip(['content_type', 'content_id', 'user_id', 'warning_user_id',
				'warning_definition_id', 'title', 'points', 'is_expired']))
		);
		$this->assertSame($this->app()->em()->find('XF:Thread', $post->thread_id)->title, $warning->content_title);
		$this->assertGreaterThanOrEqual($post->post_date, $warning->warning_date);
		$this->assertLessThanOrEqual(time(), $warning->warning_date);
		$this->assertSame($warning->warning_date + 2 * 2592000, $warning->expiry_date);

		$this->assertDatabaseHas('xf_post', ['post_id' => $post->post_id, 'warning_id' => $warning->warning_id]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $post->user_id, 'warning_points' => 3]);

		$this->assertSame($quiet, $this->counts(['xf_moderator_log', 'xf_conversation_master', 'xf_user_alert', 'xf_ip']));
		$this->assertNoMailSent();
		$this->assertDatabaseHas('xf_hampel_seeder_record', ['batch' => 'test-warning', 'entity' => 'XF:Warning', 'factory' => WarningFactory::class]);
	}

	public function test_a_warning_that_has_run_out_is_expired_and_its_points_taken_back()
	{
		$this->recordInto('test-expired');
		$post = PostFactory::new()->create(['post_date' => time() - 86400 * 30]);
		$definition = WarningDefinitionFactory::new()->points(2)->create();

		$live = WarningFactory::new()->for($post)->recycle($definition)->permanent()->create();
		$this->assertDatabaseHas('xf_user', ['user_id' => $post->user_id, 'warning_points' => 2]);

		$old = WarningFactory::new()->for($post)->recycle($definition)->expired()->create();

		$this->assertDatabaseHas('xf_warning', ['warning_id' => $old->warning_id, 'is_expired' => 1, 'points' => 2]);
		$this->assertLessThanOrEqual(time(), $old->expiry_date);
		$this->assertGreaterThan($old->warning_date, $old->expiry_date);
		$this->assertDatabaseHas('xf_warning', ['warning_id' => $live->warning_id, 'is_expired' => 0, 'expiry_date' => 0]);
		$this->assertDatabaseHas('xf_user', ['user_id' => $post->user_id, 'warning_points' => 2], 'only the live warning still counts');
	}

	public function test_expired_is_in_the_past_even_for_a_warning_given_minutes_ago()
	{
		$this->recordInto('test-expired-recent');
		$post = PostFactory::new()->create(['post_date' => time() - 900]);

		$warning = WarningFactory::new()->for($post)->custom('Seedertest', 1)->expired()->create(['warning_date' => time() - 600]);

		$this->assertTrue((bool) $warning->is_expired);
		$this->assertLessThanOrEqual(time(), $warning->expiry_date);
	}

	public function test_a_warning_can_be_about_the_member_and_can_be_written_out()
	{
		$this->recordInto('test-against');
		$member = UserFactory::new()->create(['register_date' => time() - 86400 * 40]);

		$warning = WarningFactory::new()->against($member)->custom('Seedertest custom', 5)->create();

		$this->assertDatabaseHas('xf_warning', [
			'warning_id' => $warning->warning_id, 'content_type' => 'user', 'content_id' => $member->user_id,
			'user_id' => $member->user_id, 'warning_definition_id' => 0, 'title' => 'Seedertest custom',
			'points' => 5, 'expiry_date' => 0, 'content_title' => $member->username,
		]);
		$this->assertGreaterThanOrEqual($member->register_date, $warning->warning_date);
		$this->assertDatabaseHas('xf_user', ['user_id' => $member->user_id, 'warning_points' => 5]);
	}

	public function test_with_no_definitions_given_one_of_the_forums_own_is_used()
	{
		$this->recordInto('test-own-definitions');
		$mine = WarningDefinitionFactory::new()->count(2)->create();
		$post = PostFactory::new()->create();

		$all = array_map('intval', $this->app()->db()->fetchAllColumn('SELECT warning_definition_id FROM xf_warning_definition'));
		$used = [];
		foreach (range(1, 6) AS $i)
		{
			$used[] = WarningFactory::new()->for($post)->create()->warning_definition_id;
		}

		$this->assertSame([], array_diff($used, $all));
		$this->assertNotContains(0, $used);

		$recycled = WarningFactory::new()->for($post)->recycle($mine)->create()->warning_definition_id;
		$this->assertContains($recycled, array_values($mine->pluckNamed('warning_definition_id')));
	}

	public function test_cleaning_warnings_takes_the_points_back_and_unmarks_the_post()
	{
		$this->recordInto('test-setup-kept');
		$post = PostFactory::new()->create();
		$moderator = UserFactory::new()->create(['is_moderator' => true]);
		$tables = ['xf_warning', 'xf_warning_definition', 'xf_phrase', 'xf_moderator_log', 'xf_user_alert',
			'xf_conversation_master', 'xf_user_group_change', 'xf_warning_action_trigger', 'xf_user_ban'];
		$before = $this->counts($tables);

		$this->recordInto('test-warning-clean');
		$definitions = WarningDefinitionFactory::new()->count(2)->create();
		WarningFactory::new()->count(3)->for($post)->by($moderator)->recycle($definitions)->create();
		$this->assertGreaterThan(0, (int) $this->app()->db()->fetchOne('SELECT warning_points FROM xf_user WHERE user_id = ?', $post->user_id));

		$result = (new Cleaner())->clean('test-warning-clean');

		$this->assertSame([], $result['failed']);
		$this->assertSame(['XF:Warning' => 3, 'XF:WarningDefinition' => 2], $result['removed']);
		$this->assertSame($before, $this->counts($tables));
		$this->assertDatabaseHas('xf_user', ['user_id' => $post->user_id, 'warning_points' => 0]);
		$this->assertDatabaseHas('xf_post', ['post_id' => $post->post_id, 'warning_id' => 0]);
	}

	public function test_the_warning_seeder_makes_definitions_and_a_mix_of_warnings()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'Warning', '--batch' => 'test-warning-seeder', '--seed' => '4', '--param' => ['warnings=10', 'definitions=3', 'members=4']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$created = json_decode($tester->getDisplay(), true)['created'];
		$this->assertCount(3, $created['XF:WarningDefinition']);
		$this->assertCount(10, $created['XF:Warning']);
		$this->assertSame(['id', 'title', 'user_id', 'content_type', 'content_id', 'points', 'expired'], array_keys($created['XF:Warning'][0]));

		$types = array_count_values(array_column($created['XF:Warning'], 'content_type'));
		$this->assertSame(['post' => 8, 'user' => 2], $types);
		$this->assertSame(2, count(array_filter(array_column($created['XF:Warning'], 'expired'))));
		$definitionIds = array_column($created['XF:WarningDefinition'], 'id');
		$this->assertSame(10, (int) $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_warning WHERE warning_definition_id IN (' . $this->app()->db()->quote($definitionIds) . ')'
		));
	}

	public function test_definitions_alone_can_be_seeded()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute(
			['seeder' => 'Warning', '--batch' => 'test-definitions-only', '--param' => ['warnings=0', 'definitions=5']],
			['decorated' => false, 'capture_stderr_separately' => true]
		);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$this->assertSame(['XF:WarningDefinition'], array_keys(json_decode($tester->getDisplay(), true)['created']));
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
}
