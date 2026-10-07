<?php namespace Tests\Unit;

use Hampel\Seeder\Config;
use Hampel\Seeder\Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use XF\PrintableException;

class GuardTest extends TestCase
{
	public function test_seeding_is_refused_when_config_has_no_block()
	{
		$this->setConfig('hampelSeeder', null);

		$this->assertFalse(Guard::isEnabled());
	}

	public function test_seeding_is_allowed_by_a_literal_true()
	{
		$this->setConfig('hampelSeeder', ['enabled' => true]);

		$this->assertTrue(Guard::isEnabled());
		Guard::assertEnabled();
	}

	public static function notTrue(): array
	{
		return [
			'false' => [false],
			'the string true' => ['true'],
			'one' => [1],
			'the string yes' => ['yes'],
			'null' => [null],
			'an array' => [['enabled' => true]],
		];
	}

	/**
	 * A truthy value is what a half-edited config file holds, so only `true` itself opens the guard.
	 */
	#[DataProvider('notTrue')]
	public function test_anything_but_a_literal_true_is_refused($value)
	{
		$this->setConfig('hampelSeeder', ['enabled' => $value]);

		$this->assertFalse(Guard::isEnabled());
	}

	public function test_a_block_that_is_not_an_array_is_refused()
	{
		$this->setConfig('hampelSeeder', true);

		$this->assertFalse(Guard::isEnabled());
		$this->assertSame([], Config::all());
	}

	public function test_the_refusal_names_the_line_to_add()
	{
		try
		{
			Guard::assertEnabled();
			$this->fail('assertEnabled() did not throw with seeding switched off');
		}
		catch (PrintableException $e)
		{
			$this->assertStringContainsString("\$config['hampelSeeder']['enabled'] = true;", $e->getMessage());
		}
	}

	public function test_locale_falls_back_to_the_default()
	{
		$this->assertSame(Config::DEFAULT_LOCALE, Config::locale());

		$this->setConfig('hampelSeeder', ['locale' => 'en_GB']);
		$this->assertSame('en_GB', Config::locale());

		$this->setConfig('hampelSeeder', ['locale' => '']);
		$this->assertSame(Config::DEFAULT_LOCALE, Config::locale());
	}
}
