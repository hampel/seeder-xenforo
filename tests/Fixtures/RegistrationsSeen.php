<?php namespace Tests\Fixtures;

/**
 * What RegistrationProbe saw.
 */
class RegistrationsSeen
{
	/** @var array[] */
	public static $seen = [];

	/** @var bool whether the probe throws once the member is saved, as a failing add-on would */
	public static $fail = false;
}
