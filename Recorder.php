<?php namespace Hampel\Seeder;

use XF\Mvc\Entity\Entity;

/**
 * Told about every entity a factory saves, as it is saved - not at the end of a run, so that a run
 * which dies halfway has still accounted for everything it made.
 *
 * This is the one thing that differs between a forum that must be cleaned afterwards and one that
 * will be thrown away: the first records to a table, the second records nothing.
 */
interface Recorder
{
	/**
	 * @param Entity $entity an entity that has just been saved, so it has its primary key
	 * @param string|null $factory the class of the factory that made it, if one did
	 */
	public function record(Entity $entity, ?string $factory = null): void;

	/**
	 * Told about rows that were written in bulk, without an entity each.
	 *
	 * @param string $entity the short name of the entity the rows belong to, such as XF:SessionActivity,
	 *                       or the table's own name where XenForo gives it no entity
	 * @param array $keys one [column => value] primary key per row
	 * @param string|null $factory the class of the bulk factory that wrote them
	 */
	public function recordRows(string $entity, array $keys, ?string $factory = null): void;
}
