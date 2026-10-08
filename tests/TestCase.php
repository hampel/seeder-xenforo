<?php namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @var string $rootDir path to the XenForo root directory, relative to the addon path
	 *
	 * No trailing slash!
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad load only this addon, so that another addon's vendor tree and
	 * listeners cannot affect the run
	 */
	protected $addonsToLoad = ['Hampel/Seeder'];

	/**
	 * Every test starts with seeding switched off and nothing else configured, whatever the
	 * install's own config.php holds. A test that needs the guard open says so.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('hampelSeeder', []);
	}

	/** @var string|null */
	private $codeCache;

	/**
	 * Point XenForo's code cache at a directory of this test's own.
	 *
	 * Saving a prefix or a warning definition recompiles a phrase group into the code cache - a
	 * real file, which a rolled-back transaction does not put back, so the forum would be left
	 * with compiled phrases for things that no longer exist. And where the web server wrote that
	 * file last, the command line cannot chmod it, which PHPUnit reports as a warning and fails.
	 */
	protected function useTemporaryCodeCache(): void
	{
		$this->codeCache = sys_get_temp_dir() . '/seeder-test-' . bin2hex(random_bytes(8));
		mkdir($this->codeCache);

		$this->setConfig('codeCachePath', $this->codeCache);

		// the filesystem and every language object were given the old path when they were built
		$container = $this->app()->container();
		$container->decache('fs');
		$container->decache('language.fallback');
		$container->decacheFactory('language');
		\XF::setLanguage($this->app()->language(0));
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		if ($this->codeCache !== null && is_dir($this->codeCache))
		{
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->codeCache, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($files AS $file)
			{
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($this->codeCache);
		}

		$this->codeCache = null;
	}
}
