<?php namespace Hampel\Seeder\Record;

use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Config;
use Hampel\Seeder\Context;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Timeline;

/**
 * Runs one seeder as one batch: its own context for the length of the run, everything it creates
 * recorded, and the jobs it queued run before it returns. A seeder that throws is not rethrown -
 * what it made before it did is in the result, and still recorded.
 */
class SeedRunner
{
	/**
	 * @param string $class a seeder's class name
	 * @param string|null $batch the batch to record under, or null to record nothing
	 *
	 * @return array ['created' => [shortName => [...]], 'failure' => \Throwable|null, 'password' => string]
	 */
	public function run(string $class, array $params, Timeline $timeline, ?int $seed, ?string $batch): array
	{
		$faker = Context::faker(Config::locale(), $seed);
		$recorder = new CollectingRecorder($batch === null ? new NullRecorder() : new TableRecorder($batch));
		$password = $faker->regexify('[a-z]{4}-[a-z]{4}-[0-9]{4}');

		$previous = Context::use(new Context($faker, $recorder, $timeline, $password));
		$failure = null;
		$jobs = new JobDrain();
		$jobs->start();
		try
		{
			(new $class($params))->run();
		}
		catch (\Throwable $e)
		{
			$failure = $e;
		}
		finally
		{
			Context::use($previous);
			$jobs->finish();
		}

		return ['created' => $recorder->created(), 'failure' => $failure, 'password' => $password];
	}
}
