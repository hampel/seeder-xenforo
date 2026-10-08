<?php namespace Hampel\Seeder;

use Hampel\Seeder\Repository\RecordRepository;
use XF\PrintableException;

class Listener
{
	/**
	 * Refuses to uninstall while any batch is recorded. Uninstalling drops the record table, and
	 * the seeded content would then stay on the forum with nothing left that knows which it is.
	 *
	 * XenForo marks the add-on as mid-uninstall before it fires this event, so that mark is taken
	 * back first - otherwise the refusal would leave the add-on stuck as "processing".
	 */
	public static function addonPreUninstall(\XF\AddOn\AddOn $addOn, \XF\Entity\AddOn $installedAddOn, array $json): void
	{
		$repo = \XF::repository(RecordRepository::class);
		if (!$repo->tableExists())
		{
			return;
		}

		$batches = $repo->getBatches();
		if (!$batches)
		{
			return;
		}

		$installedAddOn->is_processing = false;
		$installedAddOn->last_pending_action = null;
		$installedAddOn->saveIfChanged();

		$records = array_sum(array_column($batches, 'records'));

		throw new PrintableException(
			'Seeder was not uninstalled: this forum still holds ' . $records . ' seeded record(s) in '
			. count($batches) . ' batch(es), and uninstalling would leave that content with no way to remove it. '
			. 'First run "php cmd.php seeder:clean" to delete the seeded content, '
			. 'or "php cmd.php seeder:forget" to keep the content and discard the records. Then uninstall again.'
		);
	}
}
