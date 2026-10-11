<?php namespace Tests\Feature;

use Hampel\Seeder\Cli\Command\Run;
use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\AlbumFactory;
use Hampel\Seeder\Factory\MediaCategoryFactory;
use Hampel\Seeder\Factory\MediaFactory;
use Hampel\Seeder\Factory\ResourceCategoryFactory;
use Hampel\Seeder\Factory\ResourceFactory;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Seed\SampleSeeder;
use Hampel\Seeder\Timeline;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Resource Manager and Media Gallery content, on a forum that has neither.
 *
 * **That they create and remove content correctly is not tested here.** This suite loads this
 * add-on alone, and those add-ons' entities need their own class extensions and listeners to be
 * loaded. seeder:validate on a forum that has them is the proof: see TESTING.md.
 */
class OtherAddOnsTest extends TestCase
{
	/** @var Context|null */
	private $previous;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', ['enabled' => true]);
		$this->withActiveAddOns();
		$this->previous = Context::use(new Context(Context::faker('en_US', 1), new NullRecorder(), Timeline::span('90d'), 'password'));
	}

	protected function tearDown(): void
	{
		Context::use($this->previous);

		parent::tearDown();
	}

	#[DataProvider('factories')]
	public function test_a_factory_for_an_add_on_the_forum_lacks_says_so(string $factory, string $addOn)
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("need the {$addOn} add-on, which is not installed and active on this forum");

		// attributes given, so that nothing has to be created before the factory is asked
		$factory::new()->make(['user_id' => 1, 'resource_category_id' => 1, 'category_id' => 1, 'album_id' => 0]);
	}

	public static function factories(): array
	{
		return [
			'resource category' => [ResourceCategoryFactory::class, 'XFRM'],
			'resource' => [ResourceFactory::class, 'XFRM'],
			'media category' => [MediaCategoryFactory::class, 'XFMG'],
			'album' => [AlbumFactory::class, 'XFMG'],
			'media item' => [MediaFactory::class, 'XFMG'],
		];
	}

	#[DataProvider('seeders')]
	public function test_a_seeder_for_an_add_on_the_forum_lacks_refuses_before_creating_anything(string $seeder, string $addOn)
	{
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');
		$errors = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_error_log');

		$tester = new CommandTester(new Run());
		$code = $tester->execute(['seeder' => $seeder, '--batch' => 'test-other-' . strtolower($seeder)], ['capture_stderr_separately' => true]);

		$this->assertSame(1, $code);
		$this->assertStringContainsString("({$addOn}), which is not installed and active on this forum", $tester->getErrorOutput());
		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
		$this->assertSame($errors, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_error_log'), 'a refusal is not a server error');
		$this->assertDatabaseCount('xf_hampel_seeder_record', 0, ['batch' => 'test-other-' . strtolower($seeder)]);
	}

	public static function seeders(): array
	{
		return ['resources' => ['Resource', 'XFRM'], 'media' => ['Media', 'XFMG']];
	}

	public function test_the_sample_leaves_both_out_on_a_forum_without_them()
	{
		$sample = new class([]) extends SampleSeeder
		{
			public $called = [];

			public function call($seeders, array $params = []): void
			{
				$this->called[] = $seeders;
			}
		};
		$sample->run();

		$this->assertNotContains(\Hampel\Seeder\Seed\ResourceSeeder::class, $sample->called);
		$this->assertNotContains(\Hampel\Seeder\Seed\MediaSeeder::class, $sample->called);
		$this->assertContains(\Hampel\Seeder\Seed\ThreadSeeder::class, $sample->called);

		$this->withActiveAddOns(['XFRM', 'XFMG']);
		$sample->called = [];
		$sample->run();

		$this->assertContains(\Hampel\Seeder\Seed\ResourceSeeder::class, $sample->called);
		$this->assertContains(\Hampel\Seeder\Seed\MediaSeeder::class, $sample->called);
	}
}
