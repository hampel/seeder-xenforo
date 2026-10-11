<?php namespace Hampel\Seeder\Factory\Concerns;

/**
 * For a factory whose content belongs to another add-on. The factory names that add-on's classes
 * only as strings, so it loads on a forum without the add-on - and says so plainly when used.
 */
trait NeedsAddOn
{
	protected static function needs(string $addOnId, string $what): void
	{
		if (!\XF::isAddOnActive($addOnId))
		{
			throw new \RuntimeException("{$what} need the {$addOnId} add-on, which is not installed and active on this forum");
		}
	}
}
