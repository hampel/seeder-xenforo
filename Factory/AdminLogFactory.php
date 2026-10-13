<?php namespace Hampel\Seeder\Factory;

use Hampel\Seeder\Context;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * An entry in the admin log, written as data: what XenForo records each time an administrator
 * submits something in the admin control panel. Nothing is submitted and nothing is changed - the
 * forum gains a row that says it was.
 *
 * By default each entry is by a new administrator, so give the factory some to share with
 * `recycle()`. The action is drawn from a pool of ordinary ones, a few of them far more often
 * than the rest, and an administrator keeps one address - so the same administrator doing the same
 * thing from the same place turns up again and again, as it does in a real log.
 *
 * Besides the entry's columns there is one attribute:
 *
 *     action   [route path, posted input] - what the entry's address and data are taken from
 *
 * **No entry is ever dated inside the quiet period before now**, the same one the server error log
 * keeps. Whatever reports on this log watches for recent entries, and a seeded one must not look
 * recent. This is enforced when the entry is saved, whatever date was asked for.
 */
class AdminLogFactory extends AbstractFactory
{
	protected $entity = 'XF:AdminLog';

	/** @var array|null the actions entries are drawn from, built once per process */
	private static $pool;

	public function definition()
	{
		$context = $this->context();

		return [
			'user_id' => self::administrator(),
			'action' => function ()
			{
				return self::drawn();
			},
			'request_date' => function (array $attributes) use ($context)
			{
				$admin = \XF::em()->find('XF:User', $attributes['user_id']);

				// an administrator did nothing before they were a member
				return min($admin ? $context->timeline->after($admin->register_date) : $context->timeline->at(), self::latest());
			},
			'request_url' => function (array $attributes) { return $attributes['action'][0]; },
			'request_data' => function (array $attributes) { return $attributes['action'][1]; },
			'ip_address' => function (array $attributes)
			{
				// one address to an administrator, from the range set aside for testing, which no
				// visitor can come from; now and then they are somewhere else
				$id = (int) $attributes['user_id'];
				$away = mt_rand(1, 100) <= 10 ? mt_rand(1, 3) : 0;

				return pack('C4', 198, 18 + $away % 2, ($id >> 8) % 256, ($id + $away) % 254 + 1);
			},
		];
	}

	/**
	 * A factory for an administrator who can be in this log: one who registered before the quiet
	 * period began, since an entry cannot be dated inside it and cannot come before its author.
	 */
	public static function administrator(): UserFactory
	{
		return UserFactory::new()->administrator()->state(['register_date' => function ()
		{
			return min(Context::current()->timeline->at(), self::latest() - 60);
		}]);
	}

	/**
	 * By this administrator. Any member will do: the log holds an id and does not ask.
	 *
	 * @return static
	 */
	public function by(User $admin)
	{
		return $this->state(['user_id' => $admin->user_id]);
	}

	/**
	 * This submission: the route path XenForo records, such as `options/update`, and what was
	 * posted. XenForo masks passwords and its own token before logging; this does not, so give
	 * the input as it would be logged.
	 *
	 * @return static
	 */
	public function action(string $routePath, array $input = [])
	{
		return $this->state(['action' => [$routePath, $input]]);
	}

	/**
	 * From this address, as text.
	 *
	 * @return static
	 */
	public function from(string $address)
	{
		$binary = \XF\Util\Ip::stringToBinary($address, false);
		if ($binary === false)
		{
			throw new \InvalidArgumentException("'{$address}' is not an address");
		}

		return $this->state(['ip_address' => $binary]);
	}

	public static function describe(Entity $entity): array
	{
		return ['user_id' => $entity->user_id, 'request_url' => $entity->request_url, 'request_date' => $entity->request_date];
	}

	protected function newEntity(array $attributes): Entity
	{
		unset($attributes['action']);

		return parent::newEntity($attributes);
	}

	protected function store(Entity $entity): void
	{
		// the rule the error log keeps, applied last so that no state or override gets past it
		if ($entity->request_date > self::latest())
		{
			$entity->request_date = self::latest() - mt_rand(0, 3600);
		}

		// XenForo logs the control panel's front page as an empty path, and writes it with a
		// query; the entity will not take one. So it is saved with a path and then given none.
		$front = $entity->getValue('request_url') === '';
		if ($front)
		{
			$entity->request_url = 'index';
		}

		$entity->save();

		if ($front)
		{
			$entity->fastUpdate('request_url', '');
		}
	}

	/**
	 * The newest moment an entry may carry.
	 */
	private static function latest(): int
	{
		return time() - ErrorLogFactory::quiet();
	}

	/**
	 * One of the pool's actions, the first few far more often than the rest.
	 */
	private static function drawn(): array
	{
		$pool = self::pool();

		// about two entries in three are one of the first four
		$index = mt_rand(1, 100) <= 65 ? mt_rand(0, 3) : mt_rand(4, count($pool) - 1);

		return $pool[$index];
	}

	/**
	 * Route paths as XenForo's admin controllers have them, each with the input its form posts.
	 * The token is masked as XenForo masks it, and so is anything named for a password. Several
	 * values carry markup, since whatever shows this log has to escape it.
	 */
	private static function pool(): array
	{
		if (self::$pool !== null)
		{
			return self::$pool;
		}

		$token = ['_xfToken' => '********'];
		$ajax = $token + ['_xfResponseType' => 'json', '_xfWithData' => '1'];

		return self::$pool = [
			['login/keep-alive', $ajax + ['_xfRequestUri' => '/admin.php?options/groups/basicBoard/']],
			['options/update', $token + [
				'options_listed' => ['boardTitle', 'boardDescription', 'boardInactiveMessage'],
				'options' => [
					'boardTitle' => 'Example Forum',
					'boardDescription' => 'A forum about examples & other things',
					'boardInactiveMessage' => '<p>Back <b>soon</b>. <a href="https://example.com/status">Status</a></p>',
				],
			]],
			['', $token + ['navigation_id' => 'setup', 'collapsed' => '1']],
			['users/list', $ajax + ['criteria' => ['username' => 'exam', 'user_state' => ['valid', 'moderated']], 'order' => 'username']],
			['options/update', $token + [
				'options_listed' => ['registrationSetup'],
				'options' => ['registrationSetup' => ['enabled' => '1', 'emailConfirmation' => '1', 'moderation' => '0', 'minimumAge' => '13']],
			]],
			['users/0/save', $token + [
				'user' => ['username' => 'Example Member', 'email' => 'example.member@example.com', 'user_group_id' => '2'],
				'password' => '********',
				'profile' => ['about' => "Likes <script>alert('x')</script> and \"quotes\"", 'location' => 'Example & Sons'],
			]],
			['notices/0/save', $token + [
				'title' => 'Maintenance <tonight>',
				'message' => '<div class="notice">The forum will be <strong>read-only</strong> from 10pm.</div>',
				'notice_type' => 'block', 'active' => '1', 'dismissible' => '1',
			]],
			['templates/example_template.123/save', $ajax + [
				'style_id' => '0', 'type' => 'public', 'title' => 'example_template',
				'template' => "<xf:if is=\"\$xf.visitor.user_id\">\n\t<p class=\"example\">{{ phrase('welcome_back') }}</p>\n</xf:if>",
			]],
			['nodes/sort', $ajax + ['nodes' => '[{"id":1,"children":[{"id":2},{"id":3}]}]']],
			['forums/example.2/save', $token + [
				'node' => ['title' => 'Example <b>forum</b>', 'description' => 'Questions & answers', 'parent_node_id' => '1', 'display_order' => '10'],
				'allow_posting' => '1', 'moderate_threads' => '0',
			]],
			['user-groups/registered.2/save', $token + ['title' => 'Registered', 'display_style_priority' => '0', 'username_css' => 'color: #333; font-weight: bold']],
			['add-ons/Vendor/AddOn/toggle', $ajax + ['active' => '0']],
			['styles/default-style.1/style-properties/group', $token + ['properties' => ['paletteColor1' => 'hsl(210, 60%, 40%)'], 'group' => 'palette']],
			['users/example-member.123/delete', $token + ['rename' => '0']],
			['logs/server-errors/clear', $token],
			['tools/rebuild', $token + ['job' => 'XF:User', 'options' => ['batch' => '500']]],
			['banning/users/save', $token + ['username' => 'Example Member', 'ban_length' => 'permanent', 'user_reason' => 'Spam: <a href="https://example.com/">link</a>']],
			['phrases/example_phrase.456/save', $ajax + ['title' => 'example_phrase', 'phrase_text' => 'Hello, {name}! <em>Welcome</em>.', 'language_id' => '0']],
		];
	}
}
