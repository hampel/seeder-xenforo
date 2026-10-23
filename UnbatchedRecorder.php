<?php namespace Hampel\Seeder;

use XF\Mvc\Entity\Entity;

/**
 * The default context's recorder: it records nothing, and says so once when something is created
 * outside a database transaction.
 *
 * A test that rolls its work back runs inside a transaction, and is what the default context is
 * for. Anywhere else - a one-off script, a shell - nothing created is in a batch, and seeder:clean
 * cannot find it again. Recording it here would not help: there is no batch to put it in, and the
 * caller would never learn that a batch was the thing missing.
 */
class UnbatchedRecorder extends NullRecorder
{
	/** @var bool */
	private $warned = false;

	/** @var resource|null */
	private $out;

	/**
	 * @param resource|null $out where to say it as well as in the server error log; standard
	 *                           error by default when run from the command line
	 */
	public function __construct($out = null)
	{
		$this->out = $out ?? (PHP_SAPI === 'cli' && defined('STDERR') ? STDERR : null);
	}

	public function record(Entity $entity, ?string $factory = null): void
	{
		$this->warn($entity->structure()->shortName . ' ' . implode('-', (array) $entity->getIdentifierValues()) . ' was');
	}

	public function recordRows(string $entity, array $keys, ?string $factory = null): void
	{
		if ($keys)
		{
			$this->warn(count($keys) . " {$entity} rows were");
		}
	}

	private function warn(string $what): void
	{
		if ($this->warned || \XF::db()->inTransaction())
		{
			return;
		}
		$this->warned = true;

		$message = "{$what} created outside seeder:run, so it is in no batch and seeder:clean cannot remove it - "
			. 'nor anything else this process creates. Put the factory calls in a seeder and run it with seeder:run.';

		\XF::logError('Seeder: ' . $message);
		if ($this->out)
		{
			fwrite($this->out, "Seeder: {$message}\n");
		}
	}
}
