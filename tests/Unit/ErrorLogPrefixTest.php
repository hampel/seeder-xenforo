<?php namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Other add-ons recognise this add-on's rows in the server error log by how the message begins,
 * so every call that writes one has to carry the prefix.
 */
class ErrorLogPrefixTest extends TestCase
{
	private const PREFIX = 'Seeder: ';

	public function test_every_server_error_log_call_begins_with_the_prefix()
	{
		$calls = 0;
		$bare = [];
		foreach ($this->sources() AS $path => $source)
		{
			foreach (explode("\n", $source) AS $number => $line)
			{
				if (!preg_match('/\\\\XF::log(?:Error|Exception)\(/', $line))
				{
					continue;
				}

				$calls++;
				if (strpos($line, "'" . self::PREFIX) === false && strpos($line, '"{$this->errorLogPrefix()}') === false)
				{
					$bare[] = $path . ':' . ($number + 1);
				}
			}
		}

		$this->assertGreaterThan(0, $calls, 'no error log call was found, so this test is reading the wrong files');
		$this->assertSame([], $bare, 'begin each message with the prefix');
	}

	public function test_every_command_that_names_a_prefix_names_this_one()
	{
		$named = [];
		foreach ($this->sources() AS $path => $source)
		{
			if (preg_match('/function errorLogPrefix\(\): string\s*\{\s*return ([^;]+);/', $source, $match))
			{
				$named[$path] = $match[1];
			}
		}

		$this->assertNotSame([], $named, 'no command names a prefix, so this test is reading the wrong files');
		$this->assertSame([], array_diff($named, ["'" . self::PREFIX . "'"]));
	}

	/**
	 * @return array<string, string> source by path relative to the add-on, without anything that
	 *                               does not ship
	 */
	private function sources(): array
	{
		$root = dirname(__DIR__, 2);

		$sources = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files AS $file)
		{
			$path = substr($file->getPathname(), strlen($root) + 1);
			if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|_build|_releases)/#', $path))
			{
				continue;
			}

			$sources[$path] = file_get_contents($file->getPathname());
		}

		return $sources;
	}
}
