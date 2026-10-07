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
}
