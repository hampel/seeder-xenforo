<?php namespace Hampel\Seeder\Factory;

use XF\Mvc\Entity\Entity;

/**
 * A payment profile, which XenForo insists every user upgrade names at least one of. **Switched
 * off, and pointing at an account that does not exist**: it is there to satisfy that rule, and
 * nothing can be bought through it.
 */
class PaymentProfileFactory extends AbstractFactory
{
	protected $entity = 'XF:PaymentProfile';

	public function definition()
	{
		return [
			'provider_id' => 'paypal',
			'title' => 'Seeded profile (takes no payments)',
			'display_title' => 'Seeded profile',
			'options' => ['primary_account' => 'payments@example.com'],
			'active' => false,
		];
	}

	public static function describe(Entity $entity): array
	{
		return ['title' => $entity->title, 'provider_id' => $entity->provider_id, 'active' => $entity->active];
	}

	/**
	 * XenForo never deletes a payment profile, since a purchase made through one refers to it. A
	 * seeded one is switched off and has no purchases, so its row is removed directly - and where
	 * something does refer to it, it is left and reported.
	 */
	public static function remove(Entity $entity): void
	{
		$db = \XF::db();
		$id = $entity->payment_profile_id;

		if ($db->fetchOne('SELECT 1 FROM xf_purchase_request WHERE payment_profile_id = ? LIMIT 1', $id))
		{
			throw new \RuntimeException("Payment profile {$id} has purchase requests against it and was left in place");
		}
		if ($db->fetchOne('SELECT 1 FROM xf_user_upgrade WHERE FIND_IN_SET(?, payment_profile_ids) LIMIT 1', $id))
		{
			throw new \RuntimeException("Payment profile {$id} is still named by a user upgrade and was left in place");
		}

		$db->delete('xf_payment_profile', 'payment_profile_id = ?', $id);
		\XF::em()->detachEntity($entity);
	}
}
