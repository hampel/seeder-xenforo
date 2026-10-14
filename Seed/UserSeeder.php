<?php namespace Hampel\Seeder\Seed;

use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Factory\UserGroupFactory;

/**
 * Members, registered across the run's window.
 *
 * Parameters:
 *
 *     users      how many ordinary members (20)
 *     access     how many more, each with another way into their account, cycling through a
 *                connected account, two-step verification, a passkey, a "stay logged in" key and a
 *                pending email confirmation (0)
 *     active     how many more who have used the forum without posting: logged in, alerted,
 *                following and followed (0)
 *     moderated  how many more who are awaiting approval, each in the approval queue at the date
 *                they registered (0)
 *     passwordless  how many more who registered through a connected account and so have no
 *                password (0). Without a provider on the forum they have no password and no
 *                connected account
 *     no_auth    how many more with no authentication record at all, as a part-finished import
 *                can leave (0)
 *     admins     how many more who are administrators, with an administrator record and no
 *                administrator permissions (0). Each can sign in to the admin control panel
 *     permitted  how many more who hold the permissions in `permissions`, through a user group
 *                made to grant them (0)
 *     permissions  what those members hold, as `general.bypassFloodCheck` or, where the value is
 *                not allow, `conversation.maxRecipients:50`; several separated by commas. Needed
 *                when `permitted` is set
 */
class UserSeeder extends AbstractSeeder
{
	public function run()
	{
		UserFactory::new()->count($this->count('users', 20))->create();

		$provider = \XF::db()->fetchOne('SELECT provider_id FROM xf_connected_account_provider ORDER BY display_order, provider_id LIMIT 1');
		for ($i = 0, $access = $this->count('access', 0); $i < $access; $i++)
		{
			$member = UserFactory::new();
			switch ($i % 5)
			{
				// a forum can have had its providers removed; then the next kind stands in
				case 0: $member = $provider ? $member->withConnectedAccount($provider) : $member->withTwoStep(); break;
				case 1: $member = $member->withTwoStep(); break;
				case 2: $member = $member->withPasskey(); break;
				case 3: $member = $member->withRememberKey(); break;
				case 4: $member = $member->withConfirmationPending(); break;
			}
			$member->create();
		}

		$active = $this->count('active', 0);
		if ($active)
		{
			UserFactory::new()->activeWithoutContent()->count($active)->create();
		}

		$moderated = $this->count('moderated', 0);
		if ($moderated)
		{
			UserFactory::new()->moderated()->count($moderated)->create();
		}

		$passwordless = $this->count('passwordless', 0);
		if ($passwordless)
		{
			$member = UserFactory::new()->withoutPassword();
			($provider ? $member->withConnectedAccount($provider) : $member)->count($passwordless)->create();
		}

		$noAuth = $this->count('no_auth', 0);
		if ($noAuth)
		{
			UserFactory::new()->withoutAuthentication()->count($noAuth)->create();
		}

		$admins = $this->count('admins', 0);
		if ($admins)
		{
			UserFactory::new()->administrator()->count($admins)->create();
		}

		$permitted = $this->count('permitted', 0);
		if ($permitted)
		{
			$permissions = UserGroupFactory::parsePermissions((string) $this->param('permissions', ''));
			if (!$permissions)
			{
				throw new \InvalidArgumentException("The 'permitted' parameter needs 'permissions': which permissions they hold");
			}
			UserFactory::new()->withPermissions($permissions)->count($permitted)->create();
		}

		$banned = $this->count('banned', 0);
		if ($banned)
		{
			$moderator = UserFactory::new()->state(['is_moderator' => true, 'is_staff' => true])->create();
			UserFactory::new()->banned(null, $moderator)->count(intdiv($banned + 1, 2))->create();
			UserFactory::new()->banned(time() + 30 * 86400, $moderator)->count(intdiv($banned, 2))->create();
		}

		$bouncing = $this->count('bouncing', 0);
		if ($bouncing)
		{
			UserFactory::new()->bouncing()->count($bouncing)->create();
		}
	}
}
