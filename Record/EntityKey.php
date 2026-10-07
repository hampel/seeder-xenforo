<?php namespace Hampel\Seeder\Record;

use XF\Mvc\Entity\Entity;

/**
 * An entity's primary key as one string, and back again. A single-column key is stored as it is;
 * a compound key as JSON, keyed by column, which is the form the entity manager's find() takes.
 */
class EntityKey
{
	public static function of(Entity $entity): string
	{
		$values = $entity->getIdentifierValues();
		if (!$values)
		{
			throw new \LogicException(
				"Cannot record a {$entity->structure()->shortName} with no primary key - it has not been saved"
			);
		}

		return count($values) === 1 ? (string) reset($values) : json_encode($values);
	}

	/**
	 * @return string|array
	 */
	public static function decode(string $key)
	{
		if ($key !== '' && $key[0] === '{')
		{
			$values = json_decode($key, true);
			if (is_array($values))
			{
				return $values;
			}
		}

		return $key;
	}
}
