<?php namespace Hampel\Seeder\Record;

use Hampel\Seeder\CollectingRecorder;
use Hampel\Seeder\Config;
use Hampel\Seeder\Context;
use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\NullRecorder;
use Hampel\Seeder\Seed\AbstractSeeder;
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
	 * @return array ['created' => [shortName => [...]], 'counts' => [shortName => int],
	 *               'failure' => \Throwable|null, 'ignored' => string[], 'password' => string] -
	 *               counts includes rows written in bulk, which created does not list; ignored is
	 *               the parameters given that no seeder asked for
	 */
	public function run(string $class, array $params, Timeline $timeline, ?int $seed, ?string $batch): array
	{
		$faker = Context::faker(Config::locale(), $seed);
		ErrorLogFactory::quietFor(Config::errorLogQuietHours() * 3600);
		$recorder = new CollectingRecorder($batch === null ? new NullRecorder() : new TableRecorder($batch));
		$password = $faker->regexify('[a-z]{4}-[a-z]{4}-[0-9]{4}');

		$previous = Context::use(new Context($faker, $recorder, $timeline, $password));
		$failure = null;
		$jobs = new JobDrain();
		$jobs->start();
		AbstractSeeder::neverAskedFor([]);
		try
		{
			$seeder = new $class($params);
			// asked for here too: a parameter that only changes what a seeder warns of was still used
			$seeder->notices();
			$seeder->run();
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

		return [
			'created' => $recorder->created(),
			'counts' => $recorder->counts(),
			'failure' => $failure,
			// a run that stopped part-way never reached the rest of its parameters
			'ignored' => $failure === null ? AbstractSeeder::neverAskedFor(array_map('strval', array_keys($params))) : [],
			'password' => $password,
		];
	}
}
