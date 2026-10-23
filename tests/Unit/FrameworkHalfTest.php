<?php namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The seeders, factories and recorder interface must not know about the add-on around them - the
 * guard, config.php, the record table, the commands. Kept apart, that half can become a Composer
 * package by moving it; one reference the other way and it cannot.
 */
class FrameworkHalfTest extends TestCase
{
	private const FRAMEWORK = ['Recorder.php', 'NullRecorder.php', 'UnbatchedRecorder.php', 'CollectingRecorder.php', 'Context.php', 'Clock.php', 'Timeline.php', 'Seed', 'Factory', 'Faker'];

	private const FORBIDDEN = '/\b(Guard|Hampel\\\\Seeder\\\\(Config|Record|Entity|Repository|Cli)\b|XF::config\()/';

	public function test_the_framework_half_does_not_reference_the_add_on_half()
	{
		$root = dirname(__DIR__, 2);
		$checked = 0;
		$offenders = [];

		foreach (self::FRAMEWORK AS $path)
		{
			foreach ($this->phpFiles($root . '/' . $path) AS $file)
			{
				$checked++;
				foreach (file($file) AS $number => $line)
				{
					if (preg_match(self::FORBIDDEN, $line))
					{
						$offenders[] = substr($file, strlen($root) + 1) . ':' . ($number + 1) . ' ' . trim($line);
					}
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'no framework files were found to check');
		$this->assertSame([], $offenders);
	}

	public function test_the_check_can_fail()
	{
		$this->assertMatchesRegularExpression(self::FORBIDDEN, 'Guard::assertEnabled();');
		$this->assertMatchesRegularExpression(self::FORBIDDEN, 'use Hampel\Seeder\Record\TableRecorder;');
		$this->assertMatchesRegularExpression(self::FORBIDDEN, '$x = \XF::config(\'hampelSeeder\');');
		$this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, 'class NullRecorder implements Recorder');
	}

	private function phpFiles(string $path): array
	{
		if (is_file($path))
		{
			return [$path];
		}
		if (!is_dir($path))
		{
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator AS $file)
		{
			if ($file->getExtension() === 'php')
			{
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
