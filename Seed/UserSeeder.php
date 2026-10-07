<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\UserFactory;

/**
 * Members, registered across the run's window.
 *
 * Parameters: users (20).
 */
class UserSeeder extends AbstractSeeder
{
	public function run()
	{
		UserFactory::new()->count($this->count('users', 20))->create();
	}
}
