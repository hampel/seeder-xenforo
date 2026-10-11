<?php namespace Hampel\Seeder\Factory;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Repository\TfaRepository;
use XF\Repository\UserRepository;
use XF\Service\User\DeleteCleanUpService;

/**
 * A member: the user row with its option, profile, privacy and authentication rows.
 *
 * Written through the entity, not XenForo's registration service, which sends confirmation and
 * welcome mail. The entities apply the forum's registration defaults themselves, so a seeded member
 * starts with the same preferences as one who registered. Vary them with `Option.`, `Profile.` and
 * `Privacy.` keys: `state(['Option.receive_admin_email' => true])`. Every member gets the run's one password and an address at a reserved domain.
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

	/** @var \WeakMap|null what each member is to be given once saved: [[kind, argument], ...] */
	private static $extras;

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

	/**
	 * Has no email address at all - as a member imported from elsewhere may not.
	 *
	 * @return static
	 */
	public function withoutEmail()
	{
		return $this->state(['email' => '']);
	}

	/**
	 * Has signed in with another service as well: a connected account row for that provider, with
	 * a made-up id at the provider and no tokens.
	 *
	 * @param string $provider one of the forum's connected account providers - google, github...
	 *
	 * @return static
	 */
	public function withConnectedAccount(string $provider = 'google')
	{
		return $this->extra('connected', $provider);
	}

	/**
	 * Has two-step verification switched on: an authenticator app and backup codes, as XenForo
	 * sets them up. The secret is random; nobody holds it.
	 *
	 * @return static
	 */
	public function withTwoStep()
	{
		return $this->extra('two_step');
	}

	/**
	 * Has a passkey registered. **The key is made up and cannot sign anything**: the row is there
	 * for code that asks whether a member has one, not for signing in with.
	 *
	 * @return static
	 */
	public function withPasskey()
	{
		return $this->extra('passkey');
	}

	/**
	 * Has a "stay logged in" key that has not run out. Nobody holds the cookie that matches it.
	 *
	 * @return static
	 */
	public function withRememberKey()
	{
		return $this->extra('remember');
	}

	/**
	 * Is waiting to confirm their email address, with the confirmation row a registration
	 * leaves - unconfirmed() alone sets the state and writes no row. No email is sent.
	 *
	 * @return static
	 */
	public function withConfirmationPending()
	{
		return $this->unconfirmed()->extra('confirmation');
	}

	/**
	 * Has logged in: an IP log row for the login and a who-is-online row, both at the member's
	 * last activity and from a reserved address.
	 *
	 * @return static
	 */
	public function withLogin()
	{
		return $this->extra('login');
	}

	/**
	 * Has an alert waiting, unread, from another member.
	 *
	 * @param User|null $from a new member if omitted
	 *
	 * @return static
	 */
	public function withAlert(?User $from = null)
	{
		return $this->extra('alert', $from);
	}

	/**
	 * Follows another member.
	 *
	 * @param User|null $other a new member if omitted
	 *
	 * @return static
	 */
	public function following(?User $other = null)
	{
		return $this->extra('following', $other);
	}

	/**
	 * Is followed by another member.
	 *
	 * @param User|null $other a new member if omitted
	 *
	 * @return static
	 */
	public function followedBy(?User $other = null)
	{
		return $this->extra('followed', $other);
	}

	/**
	 * Has used the forum without ever posting: has logged in, has an alert, follows someone and
	 * is followed - by one other member, created for the purpose. Nothing here is content.
	 *
	 * @return static
	 */
	public function activeWithoutContent()
	{
		return $this->state(function (array $attributes)
		{
			// one companion for all three, made when the member is
			$other = function ()
			{
				static $made = null;

				return $made = $made ?? static::new()->create();
			};

			return ['extras' => array_merge($attributes['extras'] ?? [], [
				['login', null], ['alert', $other], ['following', $other], ['followed', $other],
			])];
		});
	}

	/**
	 * @param mixed $argument
	 *
	 * @return static
	 */
	protected function extra(string $kind, $argument = null)
	{
		return $this->state(function (array $attributes) use ($kind, $argument)
		{
			return ['extras' => array_merge($attributes['extras'] ?? [], [[$kind, $argument]])];
		});
	}

	/**
	 * Values for custom user fields, as `[field id => value]`: text for a text field, a choice's
	 * key for one with choices, a list of keys for one that takes several. Set as an administrator
	 * would set them, so a field members cannot edit takes a value too. A field the forum does not
	 * have, or a value the field would not accept, is refused.
	 *
	 * @return static
	 */
	public function withFields(array $fields)
	{
		return $this->state(function (array $attributes) use ($fields)
		{
			return ['fields' => $fields + ($attributes['fields'] ?? [])];
		});
	}

	/**
	 * A made-up value in every custom user field the forum has - or, with a share below 1, in
	 * about that share of them, each member missing different ones. A field whose value the forum
	 * checks with a pattern of its own is left empty.
	 *
	 * @return static
	 */
	public function withFieldValues(float $share = 1.0)
	{
		return $this->state(function (array $attributes) use ($share)
		{
			$fields = [];
			foreach (\XF::app()->getCustomFields('users') AS $fieldId => $field)
			{
				if (mt_rand() / mt_getrandmax() >= $share)
				{
					continue;
				}

				$value = UserFieldFactory::valueFor($field, $this->context()->faker);
				if ($value !== null)
				{
					$fields[$fieldId] = $value;
				}
			}

			return ['fields' => ($attributes['fields'] ?? []) + $fields];
		});
	}

	public static function describe(Entity $entity): array
	{
		$described = ['username' => $entity->username, 'email' => $entity->email];

		$fields = $entity->Profile ? $entity->Profile->custom_fields->getFieldValues() : [];
		if ($fields)
		{
			$described['fields'] = $fields;
		}

		if (isset(self::$extras[$entity]))
		{
			$described['has'] = array_values(array_unique(array_column(self::$extras[$entity], 0)));
		}

		return $described;
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

		// XenForo takes an empty address only from an administrator, and has to be told so before
		// the address is set
		if (($attributes['email'] ?? null) === '')
		{
			$user->setOption('admin_edit', true);
		}

		// one group is the natural thing to write, and the entity refuses anything but a list
		if (isset($attributes['secondary_group_ids']) && !is_array($attributes['secondary_group_ids']))
		{
			$attributes['secondary_group_ids'] = [(int) $attributes['secondary_group_ids']];
		}

		$fields = $attributes['fields'] ?? [];
		$extras = $attributes['extras'] ?? [];
		unset($attributes['fields'], $attributes['extras']);

		foreach ($extras AS [$kind, $argument])
		{
			if ($kind === 'connected' && !\XF::em()->find('XF:ConnectedAccountProvider', $argument))
			{
				throw new \InvalidArgumentException("This forum has no connected account provider '{$argument}'");
			}
		}

		$this->fill($user, $attributes);

		if ($fields)
		{
			$set = $user->Profile->custom_fields;
			foreach ($fields AS $fieldId => $value)
			{
				if (!isset($set->getDefinitionSet()[$fieldId]))
				{
					throw new \InvalidArgumentException("This forum has no custom user field '{$fieldId}'");
				}

				// XenForo quietly drops a choice the field does not offer; a seeder that names one
				// has made a mistake and should hear of it
				$definition = $set->getDefinitionSet()[$fieldId];
				if (in_array($definition['type_group'], ['single', 'multiple'], true))
				{
					$unknown = array_diff(array_map('strval', (array) $value), array_map('strval', array_keys($definition['field_choices'])));
					if ($unknown)
					{
						throw new \InvalidArgumentException("A custom user field would not take its value: '{$fieldId}' has no choice '"
							. implode("', '", $unknown) . "'");
					}
				}

				$set->set($fieldId, $value, 'admin');
			}
			if ($user->Profile->hasErrors())
			{
				throw new \InvalidArgumentException('A custom user field would not take its value: '
					. implode(' ', array_map('strval', $user->Profile->getErrors())));
			}
		}

		if ($extras)
		{
			self::$extras = self::$extras ?? new \WeakMap();
			self::$extras[$user] = $extras;
		}

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

		try
		{
			foreach (self::$extras[$entity] ?? [] AS [$kind, $argument])
			{
				$this->give($entity, $kind, $argument instanceof \Closure ? $argument() : $argument);
			}
		}
		catch (\Throwable $e)
		{
			// the member is saved and not yet recorded: left now, nothing would ever remove them
			static::remove($entity);

			throw $e;
		}
	}

	/**
	 * One of the things a member has besides their own rows. Every date is given: left to its
	 * column default each would be the moment the first of its kind was made in this run.
	 *
	 * None of these is recorded. They are the member's, and XenForo removes each with the member.
	 *
	 * @param mixed $argument
	 */
	protected function give(User $user, string $kind, $argument): void
	{
		$em = \XF::em();
		$db = \XF::db();
		$when = max($user->register_date, min($user->last_activity, time()));
		// from the range set aside for testing, which no visitor can come from
		$address = '198.18.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
		$ip = \XF\Util\Ip::stringToBinary($address, false);

		switch ($kind)
		{
			case 'connected':
				$account = $em->create('XF:UserConnectedAccount');
				$account->bulkSet(['user_id' => $user->user_id, 'provider' => $argument, 'provider_key' => (string) mt_rand(10 ** 14, 10 ** 15)]);
				$account->save();
				break;

			case 'two_step':
				\XF::repository(TfaRepository::class)->enableUserTfaProvider($user, $em->find('XF:TfaProvider', 'totp'), [
					'secret' => substr(\Base32\Base32::encode(random_bytes(16)), 0, 16),
				]);
				$db->update('xf_user_tfa', ['last_used_date' => $when], 'user_id = ?', $user->user_id);
				break;

			case 'passkey':
				$passkey = $em->create('XF:Passkey');
				$passkey->bulkSet([
					'user_id' => $user->user_id, 'name' => 'Seeded passkey',
					'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
					'credential_public_key' => "-----BEGIN PUBLIC KEY-----\nseeded: not a key\n-----END PUBLIC KEY-----\n",
					// the model of authenticator; all zeroes is what one that will not say reports
					'aaguid' => str_repeat('0', 32),
					'create_date' => $when, 'create_ip_address' => $ip,
				]);
				$passkey->save();
				// XenForo lists the passkey among the member's two-step methods at the end of the request
				\XF::triggerRunOnce();
				$db->update('xf_user_tfa', ['last_used_date' => $when], 'user_id = ?', $user->user_id);
				break;

			case 'remember':
				$remember = $em->create('XF:UserRemember');
				$remember->generateForUserId($user->user_id);
				$remember->bulkSet(['start_date' => $when, 'expiry_date' => time() + 28 * 86400]);
				$remember->save();
				break;

			case 'confirmation':
				$confirmation = $em->create('XF:UserConfirmation');
				$confirmation->bulkSet(['user_id' => $user->user_id, 'confirmation_type' => 'email']);
				$confirmation->regenerateKey();
				$confirmation->confirmation_date = $user->register_date;
				$confirmation->save();
				break;

			case 'login':
				// through the entities, as everything is that other add-ons may have added columns to
				$log = $em->create('XF:Ip');
				$log->bulkSet([
					'user_id' => $user->user_id, 'content_type' => 'user', 'content_id' => $user->user_id,
					// this entity alone takes the address as text
					'action' => 'login', 'ip' => $address, 'log_date' => $when,
				]);
				$log->save();

				$online = $em->create('XF:SessionActivity');
				$online->bulkSet([
					'user_id' => $user->user_id, 'unique_key' => (string) $user->user_id, 'ip' => $ip,
					'controller_name' => 'XF\\Pub\\Controller\\ForumController', 'controller_action' => 'List',
					'view_state' => 'valid', 'view_date' => $when,
				]);
				$online->save();
				break;

			case 'alert':
				$from = $argument ?: static::new()->create();
				$alert = $em->create('XF:UserAlert');
				$alert->bulkSet([
					'alerted_user_id' => $user->user_id, 'user_id' => $from->user_id, 'username' => $from->username,
					'content_type' => 'user', 'content_id' => $user->user_id, 'action' => 'following',
					'event_date' => max($when, $from->register_date),
				]);
				$alert->save();
				break;

			case 'following':
			case 'followed':
				$other = $argument ?: static::new()->create();
				[$follower, $followed] = $kind === 'following' ? [$user, $other] : [$other, $user];
				$follow = $em->create('XF:UserFollow');
				$follow->bulkSet([
					'user_id' => $follower->user_id, 'follow_user_id' => $followed->user_id,
					'follow_date' => max($user->register_date, $other->register_date, min($when, time())),
				]);
				$follow->save();
				break;

			default:
				throw new \LogicException("Unknown '{$kind}'");
		}
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
