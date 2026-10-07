<?php namespace Hampel\Seeder;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function checkRequirements(&$errors = [], &$warnings = [])
	{
		// a release built without its Composer tree fatals on first use, so decline to install it
		$vendorDirectory = sprintf("%s/vendor", $this->addOn->getAddOnDirectory());
		if (!file_exists($vendorDirectory))
		{
			$errors[] = "vendor folder does not exist - cannot proceed with addon install";
		}
	}
}
