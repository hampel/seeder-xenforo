<?php namespace Tests\Fixtures;

use Hampel\Seeder\Seed\AbstractSeeder;

class CallingSeeder extends AbstractSeeder
{
	public function run()
	{
		$this->call(QuestionSeeder::class, ['questions' => 1]);
		$this->call([QuestionSeeder::class]);
	}
}
