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

		// a phrase, since an administrator who uninstalls from the control panel is shown it there
		throw new PrintableException(\XF::phrase('hampel_seeder_not_uninstalled_x_records_in_y_batches', [
			'records' => $records,
			'batches' => count($batches),
		]));
	}

	/**
	 * Tells the Admin API add-on, where a forum has it, which of this add-on's settings are
	 * secrets: none. It has no options, and the one config.php block it reads holds a switch, a
	 * locale and a number of hours - so that block can be shown in full.
	 *
	 * The event is that add-on's, and is never fired on a forum without it.
	 */
	public static function adminApiRedaction(array &$declarations): void
	{
		$declarations['Hampel/Seeder'] = [
			'options' => [],
			'config' => [Config::KEY => []],
		];
	}
}
