<?php namespace Hampel\Seeder;

/**
 * Runs something with XenForo's clock moved, and puts the clock back.
 *
 * XenForo stamps most of what it writes from \XF::$time, set once when the request starts. Moving
 * it while an entity is saved dates every row the save writes - the entity's own defaults, and the
 * side-effect rows around it - to the moment the content is meant to be from.
 */
class Clock
{
	/**
	 * @return mixed whatever the callback returned
	 */
	public static function at(int $time, callable $callback)
	{
		$real = \XF::$time;
		\XF::$time = $time;

		try
		{
			return $callback();
		}
		finally
		{
			\XF::$time = $real;
		}
	}
}
