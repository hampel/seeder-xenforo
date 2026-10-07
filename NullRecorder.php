<?php namespace Hampel\Seeder;

use XF\Mvc\Entity\Entity;

/**
 * Records nothing, for a forum that will be discarded rather than cleaned. What it creates cannot
 * be removed again by this add-on.
 */
class NullRecorder implements Recorder
{
	public function record(Entity $entity, ?string $factory = null): void
	{
	}
}
