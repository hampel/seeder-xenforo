<?php namespace Hampel\Seeder;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$this->schemaManager()->createTable('xf_hampel_seeder_record', function (Create $table)
		{
			$table->addColumn('record_id', 'int')->autoIncrement();
			$table->addColumn('batch', 'varchar', 50);
			$table->addColumn('entity', 'varchar', 100);
			$table->addColumn('entity_id', 'varbinary', 100);
			$table->addColumn('factory', 'varchar', 150)->nullable();
			$table->addColumn('record_date', 'int')->setDefault(0);

			$table->addKey(['batch', 'record_id']);
			$table->addUniqueKey(['entity', 'entity_id']);
		});
	}

	public function uninstallStep1()
	{
		$this->schemaManager()->dropTable('xf_hampel_seeder_record');
	}

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
