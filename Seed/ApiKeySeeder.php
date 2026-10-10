<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\ApiKeyFactory;

/**
 * API keys of each kind. A member key is made for a new member each time.
 *
 * Parameters:
 *
 *     user_keys    keys that act as a member (2)
 *     guest_keys   keys that act as a guest (1)
 *     super_keys   super user keys, which can do anything on the forum (0)
 *     inactive     1 to make one more member key that is switched off and limited to two read
 *                  scopes, 0 for none (1)
 *
 * The run prints each key's value. They are working credentials.
 */
class ApiKeySeeder extends AbstractSeeder
{
	public function run()
	{
		ApiKeyFactory::new()->count($this->count('user_keys', 2))->used()->create();
		ApiKeyFactory::new()->count($this->count('guest_keys', 1))->guest()->create();
		ApiKeyFactory::new()->count($this->count('super_keys', 0))->superUser()->create();

		if ($this->count('inactive', 1))
		{
			ApiKeyFactory::new()->inactive()->scopes(['thread:read', 'user:read'])->create();
		}
	}
}
