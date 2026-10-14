<?php namespace Tests\Feature;

use Hampel\Seeder\Context;
use Tests\TestCase;

/**
 * Crawler and browser user agents, matched against this forum's robot list.
 */
class UserAgentTest extends TestCase
{
	public function test_robot_for_agrees_with_xenforo_on_crawlers_and_browsers()
	{
		$faker = Context::faker('en_US', 3);
		$robots = $this->app()->data('XF:Robot');

		$agents = [];
		for ($i = 0; $i < 60; $i++)
		{
			$agents[] = $faker->crawlerUserAgent();
			$agents[] = $faker->userAgent();
		}
		$agents[] = 'curl/8.5.0';

		foreach ($agents AS $agent)
		{
			// with this add-on alone loaded, nothing extends XenForo's check, so asking it writes nothing
			$this->assertSame($robots->userAgentMatchesRobot($agent), $faker->robotFor($agent), $agent);
		}
		$this->assertSame('http-client', $faker->robotFor('curl/8.5.0'));
	}

	public function test_a_crawler_is_one_the_forum_takes_for_a_robot_and_the_commonest_come_first()
	{
		$faker = Context::faker('en_US', 5);

		$seen = [];
		for ($i = 0; $i < 400; $i++)
		{
			$robot = $faker->robotFor($faker->crawlerUserAgent());
			$this->assertNotSame('', $robot);
			$seen[$robot] = ($seen[$robot] ?? 0) + 1;
		}

		arsort($seen);
		$this->assertSame('google', array_key_first($seen));
		$this->assertGreaterThan(8, count($seen), 'more than the first few');
	}

	public function test_a_crawler_can_be_asked_for_by_robot_and_one_the_forum_does_not_know_is_refused()
	{
		$faker = Context::faker('en_US', 5);

		$this->assertStringContainsString('bingbot', $faker->crawlerUserAgent('bing'));
		$this->assertSame('anthropic', $faker->robotFor($faker->crawlerUserAgent('anthropic')));

		$this->expectExceptionMessage("no crawler this forum takes for the robot 'nonesuch'");
		$faker->crawlerUserAgent('nonesuch');
	}

	public function test_a_browser_is_never_taken_for_a_robot()
	{
		$faker = Context::faker('en_US', 7);

		for ($i = 0; $i < 200; $i++)
		{
			$this->assertSame('', $faker->robotFor($faker->browserUserAgent()));
		}
	}
}
