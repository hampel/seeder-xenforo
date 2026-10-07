<?php namespace Tests\Fixtures;

use Hampel\Seeder\Seed\AbstractSeeder;

/**
 * Parameters: questions (2), fail - throw after making them.
 */
class QuestionSeeder extends AbstractSeeder
{
	public function run()
	{
		QuestionFactory::new()->count($this->count('questions', 2))->create();

		if ($this->param('fail'))
		{
			throw new \RuntimeException('the seeder broke');
		}
	}
}
