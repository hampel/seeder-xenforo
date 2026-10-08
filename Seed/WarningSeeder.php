<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\PostFactory;
use Hampel\Seeder\Factory\ThreadFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\WarningDefinitionFactory;
use Hampel\Seeder\Factory\WarningFactory;

/**
 * Warnings given by a moderator to a handful of members, most for something they posted and some
 * about the member themselves, with a share of them already expired.
 *
 * Parameters:
 *
 *     warnings      how many (10)
 *     definitions   how many warning definitions to create and draw from; with 0, the forum's own
 *                   definitions are used, or the warnings are written out if it has none (0)
 *     members       how many members to create to be warned (5)
 */
class WarningSeeder extends AbstractSeeder
{
	public function run()
	{
		$definitions = [];
		if ($this->count('definitions', 0))
		{
			$definitions = WarningDefinitionFactory::new()->count($this->count('definitions', 0))->create();
		}

		$warnings = $this->count('warnings', 10);
		if (!$warnings)
		{
			return;
		}

		$members = UserFactory::new()->count(max(1, $this->count('members', 5)))->create();
		$moderator = UserFactory::new()->create(['is_moderator' => true, 'is_staff' => true]);
		$thread = ThreadFactory::new()->recycle($members)->create();

		$factory = WarningFactory::new()->by($moderator)->recycle($definitions);

		for ($i = 0; $i < $warnings; $i++)
		{
			$warning = $factory;
			if ($i % 4 === 3)
			{
				$warning = $warning->expired();
			}

			if ($i % 5 === 4)
			{
				$warning->against($members[mt_rand(0, $members->count() - 1)] ?? $members->first())->create();
			}
			else
			{
				$warning->for(PostFactory::new()->for($thread)->recycle($members)->create())->create();
			}
		}
	}
}
