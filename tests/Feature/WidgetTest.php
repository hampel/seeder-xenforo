<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\WidgetFactory;
use Hampel\Seeder\Record\Cleaner;
use Hampel\Seeder\Record\TableRecorder;
use Hampel\Seeder\Timeline;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Widgets, created for real inside a transaction, with the code cache in a directory of the
 * test's own: a widget is compiled to a file there.
 */
class WidgetTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TABLES = ['xf_widget', 'xf_template', 'xf_template_map', 'xf_phrase', 'xf_phrase_map',
		'xf_template_history', 'xf_moderator_log'];

	/** @var Context|null */
	private $previous;

	/** @var \Mockery\MockInterface */
	private $styles;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->useTemporaryCodeCache();
		$this->fakesMail();
		$this->fakesJobs();
		$this->previous = Context::use(null);

		// compiling a template marks every style as changed and empties the cached CSS with a
		// TRUNCATE, which would commit this test's transaction
		$real = $this->app()->repository('XF:Style');
		$this->styles = $this->mockRepository('XF:Style', function ($mock) use ($real)
		{
			$mock->shouldReceive('updateAllStylesLastModifiedDateLater')->andReturnNull();
			// everything else it is asked is answered by the real one
			foreach (array_diff(get_class_methods($real), ['updateAllStylesLastModifiedDateLater', '__construct']) AS $method)
			{
				$mock->shouldReceive($method)->andReturnUsing([$real, $method]);
			}
		});
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	public function test_a_widget_has_its_title_and_position_and_is_compiled()
	{
		$this->recordInto('test-widget');

		$widget = WidgetFactory::new()->create(['title' => 'Seeded notice']);
		\XF::triggerRunOnce();

		$this->assertMatchesRegularExpression('/^seed_[a-z]+\d{3}$/', $widget->widget_key);
		$this->assertSame('forum_statistics', $widget->definition_id);
		$this->assertSame(['forum_list_sidebar'], array_keys($widget->positions));
		$this->assertDatabaseHas('xf_phrase', ['language_id' => 0, 'title' => 'widget.' . $widget->widget_key, 'phrase_text' => 'Seeded notice', 'addon_id' => '']);
		$this->assertDatabaseCount('xf_template', 0, ['title' => '_widget_' . $widget->widget_key]);
		$this->styles->shouldNotHaveReceived('updateAllStylesLastModifiedDateLater');

		$this->assertTrue($this->app()->fs()->has($this->compiled($widget)));
		$this->assertSame(
			['widget_key' => $widget->widget_key, 'definition_id' => 'forum_statistics', 'positions' => ['forum_list_sidebar']],
			WidgetFactory::describe($widget)
		);
	}

	public function test_an_html_widget_has_a_template_of_its_own_holding_its_markup()
	{
		$this->recordInto('test-widget-html');

		$widget = WidgetFactory::new()->html('Some <b>words</b> to show')->create();
		$sentence = WidgetFactory::new()->html()->create();

		$this->assertSame('html', $widget->definition_id);
		$this->assertSame(['advanced_mode' => false, 'template_title' => '_widget_' . $widget->widget_key], $widget->options);
		$template = $this->app()->em()->findOne('XF:Template', ['style_id' => 0, 'type' => 'public', 'title' => '_widget_' . $widget->widget_key]);
		$this->assertNotNull($template);
		$this->assertStringContainsString('Some <b>words</b> to show', $template->template);
		$this->assertSame('', $template->addon_id);
		$this->assertDatabaseCount('xf_template', 1, ['title' => '_widget_' . $sentence->widget_key]);
		$this->styles->shouldHaveReceived('updateAllStylesLastModifiedDateLater');
	}

	public function test_a_widget_can_be_of_another_definition_in_other_positions_or_none()
	{
		$this->recordInto('test-widget-kinds');

		$online = WidgetFactory::new()->of('members_online', ['limit' => 5])->at('thread_view_sidebar', 'forum_view_sidebar')->create();
		$nowhere = WidgetFactory::new()->at()->create();

		$this->assertSame('members_online', $online->definition_id);
		$this->assertSame(['limit' => 5], $online->options);
		$this->assertSame(['thread_view_sidebar', 'forum_view_sidebar'], array_keys($online->positions));
		$this->assertDatabaseCount('xf_template', 0, ['title' => '_widget_' . $online->widget_key]);
		$this->assertSame([], $nowhere->positions);
	}

	public function test_a_definition_or_a_position_the_forum_does_not_have_is_refused()
	{
		$this->recordInto('test-widget-refused');
		$messages = [];

		foreach ([WidgetFactory::new()->of('no_such_definition'), WidgetFactory::new()->at('no_such_position')] AS $factory)
		{
			try
			{
				$factory->create();
				$messages[] = 'created';
			}
			catch (\InvalidArgumentException $e)
			{
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame([
			"'no_such_definition' is not a widget definition this forum has",
			"'no_such_position' is not a widget position this forum has",
		], $messages);
	}

	public function test_cleaning_a_widget_leaves_no_row_phrase_template_or_compiled_file()
	{
		$before = $this->counts();
		$this->recordInto('test-widget-clean');

		$widgets = WidgetFactory::new()->count(2)->sequence([], ['definition_id' => 'html', 'html' => 'Words'])->create();
		\XF::triggerRunOnce();
		$compiled = array_map([$this, 'compiled'], $widgets->toArray());
		$this->assertSame($before['xf_widget'] + 2, $this->counts()['xf_widget']);
		$this->assertSame([true, true], array_values(array_map([$this->app()->fs(), 'has'], $compiled)));

		$result = (new Cleaner())->clean('test-widget-clean');
		\XF::triggerRunOnce();

		$this->assertSame([], $result['failed']);
		$this->assertSame($before, $this->counts());
		$this->assertSame([false, false], array_values(array_map([$this->app()->fs(), 'has'], $compiled)));
	}

	public function test_the_widget_seeder_puts_its_widgets_in_the_position_named()
	{
		$tester = new CommandTester(new Run());
		$code = $tester->execute([
			'seeder' => 'Widget', '--batch' => 'test-widget-seeder', '--seed' => '7',
			'--param' => ['widgets=2', 'html=1', 'position=thread_view_sidebar'],
		], ['decorated' => false, 'capture_stderr_separately' => true]);

		$this->assertSame(0, $code, $tester->getErrorOutput());
		$widgets = json_decode($tester->getDisplay(), true)['created']['XF:Widget'];
		$this->assertSame(['forum_statistics', 'members_online', 'html'], array_column($widgets, 'definition_id'));
		$this->assertSame(array_fill(0, 3, ['thread_view_sidebar']), array_column($widgets, 'positions'));
	}

	private function compiled($widget): string
	{
		return "code-cache://widgets/_{$widget->widget_id}_{$widget->widget_key}.php";
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
