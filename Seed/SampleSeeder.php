<?php namespace Hampel\Seeder\Seed;

/**
 * A little of everything this add-on can create, and no more than it takes to have one of each:
 * members, a forum, threads whose first posts cover the range of BB code, replies, a prefix,
 * attachments, warning definitions and warnings.
 *
 * It is what seeder:validate seeds and removes to prove cleaning leaves nothing behind, so
 * anything new the add-on learns to create belongs here too.
 */
class SampleSeeder extends AbstractSeeder
{
	public function run()
	{
		$this->call(ThreadSeeder::class, ['threads' => 8, 'replies' => 2, 'authors' => 3, 'prefix' => 1]);
		$this->call(WarningSeeder::class, ['warnings' => 5, 'definitions' => 2, 'members' => 2]);
	}
}
