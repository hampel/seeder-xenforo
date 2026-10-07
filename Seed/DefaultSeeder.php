<?php namespace Hampel\Seeder\Seed;

/**
 * What seeder:run builds when it is not told otherwise.
 *
 * Parameters: users (20).
 */
class DefaultSeeder extends AbstractSeeder
{
	public function run()
	{
		$this->call(UserSeeder::class);
	}
}
