<?php namespace Hampel\Seeder\Record;

use XF\Mvc\Entity\Entity;

/**
 * A primary key as one string, and back again. A single-column key is stored as it is; a compound
 * key as JSON, keyed by column, which is the form the entity manager's find() takes.
 *
 * A value that is not text - a binary address used as a key, say - cannot go into JSON as it is,
 * so it is stored as {"b64": "..."} and turned back on the way out.
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

		return self::encode($values);
	}

	/**
	 * @param array $values [column => value], in the order of the primary key
	 */
	public static function encode(array $values): string
	{
		if (count($values) === 1 && self::isText(reset($values)) && substr((string) reset($values), 0, 1) !== '{')
		{
			return (string) reset($values);
		}

		foreach ($values AS $column => $value)
		{
			if (!self::isText($value))
			{
				$values[$column] = ['b64' => base64_encode($value)];
			}
		}

		return json_encode($values);
	}

	/**
	 * @return string|array a single value, or [column => value] for a compound key
	 */
	public static function decode(string $key)
	{
		if ($key === '' || $key[0] !== '{')
		{
			return $key;
		}

		$values = json_decode($key, true);
		if (!is_array($values))
		{
			return $key;
		}

		foreach ($values AS $column => $value)
		{
			if (is_array($value) && isset($value['b64']))
			{
				$values[$column] = base64_decode($value['b64']);
			}
		}

		return count($values) === 1 ? reset($values) : $values;
	}

	/**
	 * @param mixed $value
	 */
	private static function isText($value): bool
	{
		return !is_string($value) || preg_match('//u', $value) === 1;
	}
}
