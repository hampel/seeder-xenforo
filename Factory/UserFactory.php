<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Repository\UserRepository;
use XF\Service\User\DeleteCleanUpService;

/**
 * A member: the user row with its option, profile, privacy and authentication rows.
 *
 * Written through the entity, not XenForo's registration service, which sends confirmation and
 * welcome mail. Every member gets the run's one password and an address at a reserved domain.
 */
class UserFactory extends AbstractFactory
{
	protected $entity = 'XF:User';

	/**
	 * What a deleted member leaves that XenForo's own clean-up does not remove.
	 */
	private const SWEEP = [
		'xf_ip' => 'user_id = ?',
		'xf_news_feed' => 'user_id = ?',
		'xf_session_activity' => 'user_id = ?',
		'xf_user_alert' => 'user_id = ?',
		'xf_change_log' => 'edit_user_id = ?',
	];

	/** @var array [password => [scheme class, data]] - hashing is slow, and every member shares one */
	private static $hashes = [];

	/** @var array names handed out in this process and not yet saved */
	private static $taken = [];

	public function definition()
	{
		$timeline = $this->context()->timeline;

		return [
			'username' => $this->uniqueUsername(),
			'email' => function (array $attributes)
			{
				return self::emailFor($attributes['username'], $this->context()->faker->safeEmailDomain());
			},
			'user_group_id' => User::GROUP_REG,
			'user_state' => 'valid',
			'register_date' => $timeline->at(),
			'last_activity' => function (array $attributes) use ($timeline)
			{
				return $timeline->after($attributes['register_date']);
			},
		];
	}

	/**
	 * Awaiting approval by a moderator.
	 *
	 * @return static
	 */
	public function moderated()
	{
		return $this->state(['user_state' => 'moderated']);
	}

	/**
	 * Registered, and has not confirmed their email address.
	 *
	 * @return static
	 */
	public function unconfirmed()
	{
		return $this->state(['user_state' => 'email_confirm']);
	}

	public static function describe(Entity $entity): array
	{
		return ['username' => $entity->username, 'email' => $entity->email];
	}

	/**
	 * Deleting a member queues a clean-up job. Run it here instead, so the command does not return
	 * with work still to do, and then remove what that clean-up leaves.
	 */
	public static function remove(Entity $entity): void
	{
		$userId = $entity->user_id;
		$username = $entity->username;

		static::silence($entity);
		$entity->setOption('enqueue_delete_cleanup', false);
		$entity->delete();

		$cleanUp = \XF::service(DeleteCleanUpService::class, $userId, $username);
		while (!$cleanUp->cleanUp(0)->isCompleted())
		{
			// each pass does as much as it can; with no time limit that is everything
		}

		foreach (self::SWEEP AS $table => $where)
		{
			\XF::db()->delete($table, $where, $userId);
		}
	}

	public static function emailFor(string $username, string $domain): string
	{
		$local = trim(preg_replace('/[^a-z0-9]+/', '.', strtolower($username)), '.');

		return ($local === '' ? 'member' : $local) . '@' . $domain;
	}

	protected function newEntity(array $attributes): Entity
	{
		$user = \XF::repository(UserRepository::class)->setupBaseUser();
		$user->bulkSet($attributes);

		[$scheme, $data] = self::hash($this->context()->password);
		$user->Auth->bulkSet(['scheme_class' => $scheme, 'data' => $data]);
		$user->Profile->password_date = $user->register_date;

		return $user;
	}

	protected function store(Entity $entity): void
	{
		parent::store($entity);

		// saved, so the database is what says the name is taken from here on
		unset(self::$taken[strtolower($entity->username)]);
	}

	protected function clockFor(Entity $entity): ?int
	{
		return $entity->register_date;
	}

	/**
	 * A name no member has, in the database or in this run, whose email address is also free.
	 */
	protected function uniqueUsername(): string
	{
		$faker = $this->context()->faker;
		$max = (int) (\XF::options()->usernameLength['max'] ?? 0) ?: 50;

		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$username = $faker->forumUsername($max);
			$key = strtolower($username);
			if (isset(self::$taken[$key]) || !self::forumAccepts($username))
			{
				continue;
			}

			$local = strstr(self::emailFor($username, 'example.com'), '@', true);
			$exists = \XF::db()->fetchOne(
				'SELECT 1 FROM xf_user WHERE username = ? OR email LIKE ? LIMIT 1',
				[$username, \XF::db()->escapeLike($local, '?@example.%')]
			);
			if (!$exists)
			{
				self::$taken[$key] = true;

				return $username;
			}
		}

		throw new \RuntimeException('Could not find a member name that is not already taken after 50 attempts');
	}

	/**
	 * Whether this forum would take the name: its length limits, its match pattern, its censor
	 * list and its existing members are all the entity's to judge, not ours to copy.
	 */
	private static function forumAccepts(string $username): bool
	{
		$probe = \XF::em()->create('XF:User');
		$probe->set('username', $username);

		return !$probe->hasErrors();
	}

	private static function hash(string $password): array
	{
		if (!isset(self::$hashes[$password]))
		{
			$auth = \XF::app()->auth(null);
			self::$hashes[$password] = [$auth->getAuthenticationName(), $auth->generate($password)];
		}

		return self::$hashes[$password];
	}
}
