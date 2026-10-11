<?php namespace Tests\Unit;

use Hampel\Seeder\Config;
use Hampel\Seeder\Listener;
use Tests\TestCase;

/**
 * What this add-on tells the Admin API about its secrets has to stay true as settings are added.
 */
class SecretsDeclarationTest extends TestCase
{
	private const CREDENTIAL = '/key|token|secret|password|webhook/i';

	/** config.php keys this add-on reads that read like a credential and are not one, with why */
	private const NOT_SECRETS = [];

	public function test_it_declares_this_add_on_only()
	{
		$this->assertSame(['Hampel/Seeder'], array_keys($this->declarations()));
	}

	public function test_it_has_no_options_so_declares_none()
	{
		$options = glob(dirname(__DIR__, 2) . '/_output/options/*.json') ?: [];

		$this->assertSame([], $options, 'an option has been added: decide whether it is a secret and test the declaration against it');
		$this->assertSame([], $this->declared()['options']);
	}

	public function test_it_claims_the_one_config_block_it_reads_and_nothing_under_it_is_secret()
	{
		$this->assertSame([Config::KEY => []], $this->declared()['config']);
	}

	/**
	 * Every key the add-on reads from its config.php block is read through a Config::get() or
	 * Config::has() call with a literal name, so the source is the list of them.
	 */
	public function test_no_setting_it_reads_from_config_reads_like_a_credential()
	{
		$read = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2), \FilesystemIterator::SKIP_DOTS));
		foreach ($files AS $file)
		{
			$path = $file->getPathname();
			if ($file->getExtension() !== 'php' || preg_match('#/(vendor|tests|_build|_releases)/#', $path))
			{
				continue;
			}

			preg_match_all('/(?:Config::|self::)(?:get|has)\(\s*\'([^\']+)\'/', file_get_contents($path), $matches);
			$read = array_merge($read, $matches[1]);
		}
		$read = array_values(array_unique($read));
		sort($read);

		$this->assertSame(['enabled', 'errorLogQuietHours', 'locale'], $read, 'a setting was added or removed: update this list and the README');

		$undecided = array_values(array_filter($read, function (string $key)
		{
			return preg_match(self::CREDENTIAL, $key) && !in_array($key, self::NOT_SECRETS, true);
		}));
		$this->assertSame([], $undecided, 'declare each as a secret, or add it to NOT_SECRETS');
	}

	private function declarations(): array
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	private function declared(): array
	{
		return $this->declarations()['Hampel/Seeder'] + ['options' => [], 'config' => []];
	}
}
