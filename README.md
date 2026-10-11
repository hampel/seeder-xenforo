# Test data generator for XenForo

Fills a development forum with test content — and removes it again. It is for add-on developers
who need a forum with members and dated content to test against, and it is not for a live forum.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

**Early development.** It creates members, forums, threads with replies, tags, reactions, thread
prefixes, image attachments, content awaiting approval or soft-deleted, conversations, custom
user fields with members' values, user upgrades held and run out, warning definitions, warnings,
API keys, who is online and server error log entries - and, where the forum has them, XenForo
Resource Manager resources and XenForo Media Gallery media items - and removes them again. Polls and profile posts are not built yet.

## Requirements

- XenForo 2.3 or later
- PHP 8.1 or later

## Installation

Install it as you would any add-on, then allow seeding in `src/config.php`:

```php
$config['hampelSeeder']['enabled'] = true;
```

**Without that line every command that writes refuses to run.** The setting is in `config.php`
and not on the options page so that a copy of another forum's database cannot bring it along.

## Configuration

Everything is set in `src/config.php`, under `$config['hampelSeeder']`.

| key | default | |
|---|---|---|
| `enabled` | off | allow seeding on this forum — only a literal `true` counts |
| `locale` | `en_AU` | the [Faker](https://fakerphp.org/) locale for generated names and text |
| `errorLogQuietHours` | 2 | how long before now no seeded error log entry may be dated; never less than 2 |

## Usage

```bash
php cmd.php seeder:run -p users=50 -p threads=100 --span=6m
```

That creates 50 members, two forums and 100 threads with replies, dated across the last six months,
and prints what it made as JSON — every id, each member's name and address, and the one password
they all share. A summary for a person
goes to standard error, so the JSON can be piped:

```bash
php cmd.php seeder:run -p users=50 2>/dev/null | jq '.created["XF:User"][0]'
```

| option | |
|---|---|
| `-p name=value` | a parameter for the seeder; repeat it for more than one |
| `--span=90d` | how far back content is dated, ending now: `36h`, `90d`, `12w`, `6m`, `2y` |
| `--seed=N` | make the generated names, addresses and password the same on every run |
| `--batch=NAME` | name the batch; the date and time if omitted. A name already in use is refused |
| `--no-record` | do not record what is created — it can then never be cleaned |

Everything a run creates is recorded under its batch, as it is created. From PHP,
`\XF::repository('Hampel\Seeder:Record')->getRecords($batch, 'XF:User')` lists what a batch
holds, in the order it was made. A run that fails part-way
has still recorded what it made, exits 1, and says how to remove it.

**A parameter no seeder asks for fails the run.** A mistyped name, or one this seeder does not
take, changes nothing, so the forum would not hold what was asked for. The run makes everything
else, lists the names under `ignored` in its JSON and on standard error, and exits 1. A parameter
that is only overridden by another you gave is reported the same way.

### Seeders

`seeder:run` takes the name of a seeder, and runs `Default` without one.

| seeder | creates | parameters |
|---|---|---|
| `Default` | members, forums, and threads by those members in those forums | `users` (20), `forums` (2), and those of `Thread` |
| `User` | members | `users` (20), `access` (0; more members, each with another way into their account), `active` (0; more members who have used the forum without posting), `moderated` (0; more members awaiting approval), `passwordless` (0; more members who registered through a connected account and have no password), `no_auth` (0; more members with no authentication record at all) |
| `Thread` | threads with replies, and a prefix | `threads` (12), `replies` (5, the most per thread), `authors` (5), `forum` (an existing forum's node id; a new forum if omitted), `prefix` (1, or 0 for none), `reactions` (0; the most per post), `tags` (0; how many different tags to file threads under), `states` (0; 1 adds threads and replies awaiting approval or soft-deleted) |
| `Conversation` | conversations between members, with replies | `conversations` (5), `messages` (10; in each, the first included), `participants` (3; the most in one, the starter included), `members` (6; created for them to be between) |
| `UserField` | custom user fields of each kind, and members with values in them | `fields` (4; with 0 the forum's own are used), `members` (20), `filled` (80; the percentage of fields each member has a value in) |
| `UserUpgrade` | user upgrades, members holding one, and upgrades that have run out | `upgrades` (2; with 0 the forum's own are used), `active` (5), `expired` (5), `members` (10), `hooks` (0; see *User upgrades*) |
| `Resource` | Resource Manager resources, in categories | `resources` (6), `categories` (1), `category` (an existing resource category's id, instead of creating any), `authors` (3) |
| `Media` | Media Gallery items: in a category, in an album, and where a guest cannot view them | `media` (6; in categories), `categories` (1), `category` (an existing media category's id), `albums` (1; each with two or three items), `hidden` (1; items in a private album), `authors` (3) |
| `Warning` | warning definitions, and warnings given to members | `warnings` (10), `definitions` (0 — how many to create; with 0 the forum's own are used), `members` (5) |
| `ApiKey` | API keys of each kind, with a new member for each member key | `user_keys` (2), `guest_keys` (1), `super_keys` (0), `inactive` (1; one more member key, switched off and limited to two read scopes) |
| `Online` | who is online: members, guests and robots | `members` (10), `guests` (100), `robots` (200), `networks` (20; how many networks each kind comes from, 1 to 128), `top` (0; the percentage in the largest network), `robot_kinds` (40; how many different robots), `actions` (11; how many different controller and action pairs, up to 45), `robot_action` (none; one action most robots are on, as `Search::Results`), `robot_focus` (90; the percentage of robots on it), `ipv6` (5; percent), `stale` (10; percent seen too long ago to count as online) |
| `ErrorLog` | a server error log with history: recurring faults, from page views, commands and jobs | `errors` (300), `members` (5; created for about one page-view entry in three to name) |
| `Sample` | a little of everything above, bar who is online and the error log, with resources and media only where the forum has those add-ons; what `seeder:validate` seeds and removes | |

**The `Thread` seeder varies each first post**, cycling through plain prose, a long post, a quote, a
list, an image, links, an attachment, and everything at once with a prefix — so whatever renders a
first post meets the range of BB code.

```bash
php cmd.php seeder:run Thread -p threads=12 -p forum=2
```

### Members

`Hampel\Seeder\Factory\UserFactory` builds a member with the forum's own registration defaults,
an address at a reserved domain, and the run's shared password.

```php
UserFactory::new()
	->count(200)
	->sequence(['user_state' => 'valid'], ['user_state' => 'valid'], ['user_state' => 'email_bounce'])
	->state([
		'secondary_group_ids' => [5, 6],
		'Option.receive_admin_email' => true,
	])
	->create();
```

| to get | write |
|---|---|
| a column on the member | `state(['user_group_id' => 5])`, `['is_moderator' => true]`, `['register_date' => ...]` |
| a preference, profile or privacy setting | `state(['Option.receive_admin_email' => true])`, `'Profile.location'`, `'Privacy.allow_view_profile'` |
| awaiting approval, or unconfirmed | `->moderated()`, `->unconfirmed()`. A moderated member is in the approval queue, at the date they registered |
| no email address at all | `->withoutEmail()` |
| no password, as after registering through a connected account | `->withoutPassword()`, with `->withConnectedAccount()` for the whole of such a registration |
| no authentication record at all, as a part-finished import can leave | `->withoutAuthentication()` |
| another way into the account | `->withConnectedAccount('google')`, `->withTwoStep()`, `->withPasskey()`, `->withRememberKey()`, `->withConfirmationPending()` |
| a member who has used the forum without posting | `->activeWithoutContent()`, or its parts: `->withLogin()`, `->withAlert()`, `->following()`, `->followedBy()` |
| values in custom user fields | `->withFields(['field_id' => 'value'])`, or `->withFieldValues()` for a made-up value in every field the forum has |

`is_moderator` sets the flag only: it creates no moderator record and grants no permissions.

**The ways in are rows, not working credentials.** A connected account has a made-up id at the
provider and no tokens; a passkey's key is not a key and can sign nothing; nobody holds the
authenticator secret or the cookie that matches a remember key. They are for code that asks what a
member has. `withConfirmationPending()` writes the confirmation row a registration leaves and
sends no email; `unconfirmed()` alone sets the state and writes no row.

**`activeWithoutContent()` is a member who has logged in, has an unread alert, follows someone and
is followed**, and has never posted. The login is an IP log row and a who-is-online row from
`198.18.0.0/15`, the range set aside for testing. The other party to the alert and both follows is
one more member, created with them; `following($member)`, `followedBy($member)` and
`withAlert($member)` take an existing one. A run's JSON lists what each member was given under
`has`.

None of these rows is recorded separately: they are the member's, and go when the member does.

### Forums, threads and replies

```php
$forum = ForumFactory::new()->create(['title' => 'Announcements']);

ThreadFactory::new()
	->count(50)
	->for($forum)                                   // every thread in this forum
	->recycle($members)                             // authors drawn from these members
	->has(PostFactory::new()->count([0, 8]))        // each with up to eight replies
	->create();
```

| factory | builds | states |
|---|---|---|
| `ForumFactory` | a forum; the entity returned is its node | |
| `ThreadFactory` | a thread and its first post | `locked()`, `sticky()`, `moderated()`, `deleted($reason, $by)`, `long()`, `withQuote()`, `withList()`, `withImage()`, `withLinks()`, `withFormatting()`, `withAttachment(n)`, `withPrefix($prefix)`, `withTags([...])` |
| `PostFactory` | a reply | `moderated()`, `deleted($reason, $by)`, `withQuote()`, `withAttachment(n)` |
| `ReactionFactory` | a member's reaction, by default to a post | `for($post)` or `for($thread)`, `reaction($id)` |
| `PrefixFactory` | a thread prefix; `node_ids` lists the forums that offer it | |

The `with...()` states add up: `->withQuote()->withList()` gives a first post with both. Set
`message` to supply the BB code yourself, and `post_date` to fix the date.

**Threads and replies are written through XenForo's own thread creator and replier**, so a message
is prepared as a posted one is. Their notifications are never sent, nobody is alerted, and no
moderator log is written. Replies are dated after the post they follow and never before their
author joined. Attachments are real PNG images. Links and images point at `example.com`.

**Tags.** `withTags(['alpha', 'two words'])` files a thread under those tags as XenForo's own
tagging does. A tag that does not exist is made, and removed with the batch; one that exists is
used and left alone — though XenForo itself deletes any tag once nothing is filed under it, unless
it is marked permanent. Names are tidied the way XenForo tidies them, and one the forum would not
accept is refused. A run's JSON lists each tag it made with the form of it that goes in a URL
(`two words` is `two-words`), and each thread with the tags it carries.

**Content states.** `moderated()` posts the thread or reply as awaiting approval: it is in the
approval queue and not counted. `deleted()` posts it, gives it whatever replies and reactions were
asked for, and then soft-deletes it — the order it happens in on a real forum — with a deletion log
entry dated after its last reply.

**Reactions.** The member reacting is drawn from those given to `recycle()`, never the author and
never the same member twice; if nobody is left, a new member is made. A reaction updates the
post's summary and its author's score, appears in the news feed, and alerts nobody.

```php
ThreadFactory::new()
	->recycle($members)
	->has(ReactionFactory::new()->count([0, 5]))                      // on the first post
	->has(PostFactory::new()->count(4)->has(ReactionFactory::new()->count([0, 3])))
	->create();
```

### Conversations

```php
ConversationFactory::new()
    ->recycle($members)
    ->to(2)                                              // two recipients: three participants
    ->has(ConversationMessageFactory::new()->count(9))
    ->create();
```

| factory | creates | states |
|---|---|---|
| `ConversationFactory` | a conversation, its first message and a row for each participant | `from($member)`, `to(n)` or `to([$member, ...])` |
| `ConversationMessageFactory` | a reply | |

**Every message has its own date.** Each reply is dated at least a second after the message before
it, so a conversation never has two messages in the same second, and none is dated before its
author joined or after now. Set `start_date` on the conversation or `message_date` on a reply to
place one exactly.

**Each reply is from someone other than whoever wrote the message before it**, drawn from the
conversation's participants, so a conversation with replies has messages from at least two
members. Set `user_id` on a reply to choose.

**It tells nobody.** XenForo's creator and replier are used with their notifications off: no alert
and no email. The recipients' unread counts do go up, as they would, and come back down when the
batch is cleaned. Members made by `UserFactory` have an address at a reserved domain unless
`withoutEmail()` is used.

```bash
php cmd.php seeder:run Conversation -p conversations=5 -p messages=10
```

### Custom user fields

```php
$field = UserFieldFactory::new()->choices(['Beginner', 'Expert'])->create(['field_id' => 'experience']);

UserFactory::new()->withFields(['experience' => 'expert'])->create();
UserFactory::new()->withFieldValues(0.8)->count(50)->create();
```

| factory | creates | states |
|---|---|---|
| `UserFieldFactory` | a custom user field, with its title, description and choice phrases | `type($type)`, `matching($form)`, `choices([...])`, `group($group)`, `required()`, `onRegistration()`, `onMessages()` |

**The types are XenForo's eight**: `textbox`, `textarea`, `bbcode`, `select`, `radio`, `checkbox`,
`multiselect` and `stars`. A date, a colour, a number, an email address or a URL is not a type but
a text box that checks its value: `matching('date')`.

**`withFields()` takes a choice's key, not its label**, and a list of keys for a field that takes
several. `choices(['Dark blue'])` makes the key `dark_blue`. Values are set as an administrator
sets them, so a field members cannot edit takes one too. A field the forum does not have, a
choice the field does not offer and a value the field's check rejects are all refused.

**`withFieldValues()` fills the forum's own fields as well as seeded ones.** A share below 1 leaves
each member without some. A field checked by a pattern or callback of the forum's own is left
empty, since a value cannot be made up for it.

Removing a field removes every member's value for it, as XenForo does.

### User upgrades

```php
$upgrade = UserUpgradeFactory::new()->lasting(1, 'year')->grantingGroups([5])->create();

UserUpgradeActiveFactory::new()->for($member)->for($upgrade)->create();
UserUpgradeExpiredFactory::new()->for($other)->for($upgrade)->create();
```

| factory | creates | states |
|---|---|---|
| `UserUpgradeFactory` | an upgrade, not offered for sale | `lasting(n, $unit)`, `permanent()`, `grantingGroups([...])` |
| `UserUpgradeActiveFactory` | a member holding an upgrade now, in its groups | `cancelled()`, `withHooks()` |
| `UserUpgradeExpiredFactory` | an upgrade a member held and no longer does | `withHooks()` |
| `PaymentProfileFactory` | the payment profile an upgrade has to name; switched off | |

**Nothing can be bought.** XenForo requires every upgrade to name a payment profile, so one is
made: switched off, and pointing at an account at `example.com`. The upgrade itself has
`can_purchase` off. No purchase request or payment log is written.

**An upgrade still held always ends in the future**, or never. XenForo's own cron expires one that
has run out and alerts the member, so the factory refuses to make one. An upgrade that has run out
ended on its due date where that has passed, and otherwise at some moment before now. Set
`start_date`, `end_date` and `original_end_date` to place one exactly.

**By default no other add-on hears of it.** The records are written through their entities and the
group change through XenForo's group change service, which is everything XenForo's upgrade service
does except be the thing other add-ons extend. `withHooks()`, or `-p hooks=1`, goes through
XenForo's upgrade service and upgrade repository instead, so an add-on that acts when a member is
upgraded or an upgrade runs out acts for these too - **which can mean sending the member's details
to another system.** The seeder says so before it starts. Either way the member is not alerted.

XenForo does not delete payment profiles; a seeded one is removed by deleting its row, and is left
and reported where anything still refers to it.

### Resources and media

**These need XenForo Resource Manager and XenForo Media Gallery.** Seeder installs and runs without
either: the factories name those add-ons' classes only as strings, and a factory or seeder used on
a forum that lacks the add-on says so and creates nothing.

```php
ResourceFactory::new()->for($category)->recycle($authors)->count(6)->create();

MediaFactory::new()->recycle($authors)->count(6)->create();     // in a media category
MediaFactory::new()->inAlbum($album)->create();
MediaFactory::new()->hidden()->create();                        // a guest cannot view it
```

| factory | creates | states |
|---|---|---|
| `ResourceCategoryFactory` | a resource category, with no discussion forum | |
| `ResourceFactory` | a resource with its description and first version; visible, and with no file | `moderated()` |
| `MediaCategoryFactory` | a gallery category that holds media items | `forAlbums()` |
| `AlbumFactory` | a member's album that anyone may view | `private()`, `membersOnly()`, `in($category)` |
| `MediaFactory` | a media item: a real PNG, with the thumbnail the gallery makes of it | `inAlbum($album)`, `hidden()` |

**A media item is a real upload.** A 480 by 270 PNG goes through the gallery's own attachment
handler, so the item has an attachment, a file and a thumbnail, and its full image is served. All
are removed with the batch.

**`hidden()` puts the item in a new album only its owner can view.** A run's JSON gives each item's
`category_id`, `album_id` and `album_view_privacy`, each album's `view_privacy`, and each category.
Who can view an item in a category is the forum's permissions, which Seeder does not set.

**Nobody is told.** Resources go through Resource Manager's creator and albums through the
gallery's, with notifications never sent; a media item is saved through its entity. A resource
category has no discussion forum, so no thread is started.

### Warnings

```php
$definitions = WarningDefinitionFactory::new()->count(6)->create();

WarningFactory::new()
	->count(20)
	->recycle($posts)            // each about one of these posts
	->recycle($definitions)      // each for one of these reasons
	->by($moderator)
	->create();
```

| factory | builds | states |
|---|---|---|
| `WarningDefinitionFactory` | a reason a moderator can pick, with its title and conversation phrases | `permanent()`, `points(n)` |
| `WarningFactory` | a warning given to a member, by default for a post | `for($post)`, `against($user)`, `by($moderator)`, `custom($title, $points)`, `permanent()`, `expired()` |

**A warning is applied as XenForo applies one**: the member's warning points go up, the post is
marked, and any warning actions the forum has set for that many points are triggered. A warning
whose expiry has already passed is then expired, so its points come back off. Removing a warning
reverses all of it.

**It tells nobody.** XenForo's own warn service sends a webhook and can start a conversation with
the member; a seeded warning does neither, alerts nobody, and writes no moderator log.

With no definitions given to `recycle()`, a warning uses one of the forum's own; on a forum with
none, it is written out as a custom warning. To seed definitions alone:

```bash
php cmd.php seeder:run Warning -p warnings=0 -p definitions=12
```

### API keys

```php
$key = ApiKeyFactory::new()->for($member)->scopes(['thread:read', 'user:read'])->create();

$key->api_key;      // the value to send as XF-Api-Key
```

| factory | builds | states |
|---|---|---|
| `ApiKeyFactory` | a key that acts as a member, with every scope | `for($member)`, `guest()`, `superUser()`, `inactive()`, `scopes([...])`, `used()` |

**A seeded key is a working credential.** A run prints each key's value, as it prints the members'
password, because whatever is testing the API needs it. A super user key can do anything on the
forum it was made on, so the seeder makes none unless asked.

XenForo emails every super administrator when a key is created through the control panel. A
seeded key is written without that.

### Who is online

```bash
php cmd.php seeder:run Online -p guests=5000 -p robots=70000 -p networks=30 -p top=80
```

That is a swarm: 70,000 robots from 30 networks with 80% of them in one, in about ten seconds.

```php
SessionActivityFactory::new()
	->members($users)
	->guests(5000, 40)            // across 40 networks, evenly
	->robots(60000, 12, 0.8)      // across 12 networks, 80% of them in one
	->ipv6(0.05)
	->stale(0.1)
	->create();
```

**These rows are written in bulk**, straight into the table, because saving tens of thousands of
entities one at a time would take minutes. They are still recorded and still removed by
`seeder:clean`. A run counts them in its JSON and does not list them.

**Addresses come from ranges nobody is issued**, because a guest's row is keyed by its address and
a seeded row must never stand where a real visitor's would: guests from the lower half of
`10.0.0.0/8`, robots from the upper half, members from `198.18.0.0/15`, IPv6 from `2001:db8::/32`.
A network here is a /16. On a public forum none of those can be a real visitor; on a development
machine, where visitors arrive from private addresses, `10.0.0.0/8` can be — so a row already in
the table is left exactly as it is and not recorded.

**XenForo deletes these rows itself**, an hour after their view date. `seeder:clean` reports any
that have already gone.

Robots are drawn from forty of the names XenForo knows, the well-known crawlers first and a handful
of them taking most of the rows, as on a real forum. `-p robot_kinds=N`, or `->robotKinds(N)`,
changes how many. What each visitor is
doing is spread across real controller and action pairs, reading threads commonest, with a few
errors: eleven pairs by default and up to 45 with `-p actions=N` or `->actions(N)` — for testing
something that reports only the top few.

**A swarm on one page:** `-p robot_action=Search::Results`, or `->robotsOn('Search::Results', 0.9)`,
puts nine robots in ten on that one action while guests and members carry on as usual. The
action has to exist on the forum.

### The server error log

```bash
php cmd.php seeder:run ErrorLog -p errors=500
```

**Entries are written as data. Nothing is made to fail**, so the forum logs no real error. Each is
drawn from a pool of made-up faults — in a handful of the forum's installed add-ons, in XenForo
itself, in a template, in a file outside the forum — with a few of them accounting for most
entries, as in a real log.

| factory | builds | states |
|---|---|---|
| `ErrorLogFactory` | one entry | `web()`, `cli()`, `job()`, `unknownOrigin()`, `forUser($member)`, `inAddOn('Vendor/AddOn')`, `inCore()`, `php('E_DEPRECATED')`, `fatal()`, `inTemplate('public:thread_view')`, `missingClass('Vendor/AddOn')`, `missingAutoload('Vendor/AddOn')`, `outside()` |

**No entry is ever dated within two hours of now.** Anything that watches the log for new errors
and posts them somewhere looks at recent entries, and a seeded log must not be taken for a burst
of new ones. The factory enforces this when it saves, whatever date it was given.
`errorLogQuietHours` in `config.php` makes the period longer.

**Whatever reports on the log by another rule will still report on these**: a digest that covers
the days they are dated in, or anything that forwards every entry it has not seen. `seeder:run`
names each such add-on it finds active before it creates anything, and `seeder:validate` warns of
them. Cleaning removes the entries from the log; it cannot recall what was already sent.

**Cleaning can itself set a reader off.** One that remembers the id of the last entry it forwarded
finds, after a clean, that the log now ends before that id. It may take that for an emptied log
and forward every real entry again. Clean a seeded error log before such a reader has run, or
expect one replay.

Each fault comes mostly from where it would — a job's class from the job queue, a command's from
the command line, a template only ever from a page view — and occasionally from elsewhere.
An origin is recorded the way XenForo records one: a page view's address and input, a command
line, and for a job the job manager's frame in the stack trace.

### Your own seeders and factories

**Any add-on can carry seeders for its own content.** Put a class in the add-on's `Seed` directory
and run it as `Vendor/AddOn:Name`:

```php
namespace Vendor\AddOn\Seed;

use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Seed\AbstractSeeder;
use Vendor\AddOn\Factory\ThingFactory;

class ThingSeeder extends AbstractSeeder
{
	public function run()
	{
		$owner = UserFactory::new()->create();

		ThingFactory::new()->count($this->count('things', 10))->create(['user_id' => $owner->user_id]);
	}
}
```

```bash
php cmd.php seeder:run Vendor/AddOn:Thing -p things=25
```

**Keep them in the repository and out of the release.** Seeders and factories are development
tooling, like tests: a forum running the add-on has no use for them, and a development install is
a clone of the repository, which has them. Remove them in the add-on's `build.json`, before any
line that moves files:

```json
"rm -frv _build/upload/src/addons/{addon_id}/Seed _build/upload/src/addons/{addon_id}/Factory"
```

Name whichever directory holds the factories, and leave it out of that line if it also holds
classes the add-on needs.

A factory says what one entity looks like by default, in the manner of a Laravel model factory:

```php
namespace Vendor\AddOn\Factory;

use Hampel\Seeder\Factory\AbstractFactory;
use Hampel\Seeder\Factory\UserFactory;

class ThingFactory extends AbstractFactory
{
	protected $entity = 'Vendor\AddOn:Thing';

	public function definition()
	{
		return [
			'title' => $this->context()->faker->sentence(4),
			'user_id' => UserFactory::new(),      // created when needed, and replaced by its id
		];
	}

	public function hidden()
	{
		return $this->state(['visible' => false]);
	}
}
```

| method | |
|---|---|
| `new()`, `state()`, `sequence()`, `count()` | configure; each returns a changed copy. `count([2, 9])` draws a number in that range each time |
| `recycle($entities)` | use these wherever the definition names a factory of the same kind, picking one at random |
| `for($entity)` | make everything built belong to this one |
| `has($factory)` | after each is created, create these belonging to it |
| `afterMaking()`, `afterCreating()` | callbacks given the entity |
| `make()` | build without saving |
| `create()` | build, save and record |
| `raw()` | the attributes only |

**Writing rows in bulk.** For a table whose rows stand alone, extend `AbstractBulkFactory` and
yield the rows from `rows()`, each as the database stores it. It is the wrong tool for content: no
entity is saved, so no counter, search index or other add-on's bookkeeping is updated.

A key written `Relation.column` sets a column on a related entity, which is created if need be and
saved with the main one.

`create()` records the entity for `seeder:clean`, switches its webhooks off, and saves it with
XenForo's clock moved to the date `clockFor()` returns. Override `store()` where saving takes more
than `$entity->save()`, `remove()` where deleting leaves something behind, and `describe()` to add
fields to the JSON a run prints.

## Commands

```bash
php cmd.php seeder:run [seeder]    # create content
php cmd.php seeder:config       # whether seeding is allowed, and the settings in use
php cmd.php seeder:validate     # whether this forum can be seeded, and whether that is safe
php cmd.php seeder:list         # the batches of seeded content on this forum
php cmd.php seeder:clean [batch]   # remove one batch, or every batch
php cmd.php seeder:forget [batch]  # keep the content, discard the record of it
```

`seeder:list` and `seeder:clean` take `--json`.

### Removing seeded content

**`seeder:clean` deletes only what was recorded when the content was created.** It asks first;
`--force` skips the question, and without a terminal to answer the question nothing is deleted.

It exits 0 when everything recorded is gone and 1 if anything could not be removed. Whatever
could not be removed stays recorded, so the command can be run again. A removal that loses a
database deadlock, as happens when something else is writing the same rows, is tried again up to
three times before it counts as failed.

### Exit codes

| | all fine | warnings only | any failure |
|---|---|---|---|
| `seeder:validate` | 0 | 0 | 1 |
| `seeder:validate --strict` | 0 | 2 | 1 |

1 always means a check failed. This is not the monitoring-plugin numbering, where 1 is a warning
and 2 is critical.

### The round trip

**`seeder:validate` seeds a little of everything the add-on can create, removes it, and compares the row count of every table
before and after.** It is the proof that cleaning leaves nothing behind on this forum, with this
forum's add-ons installed. It takes a couple of seconds on a small forum; counting every table of
a large one takes longer.

| it reports | when |
|---|---|
| `[fail] left behind` | one of XenForo's own tables did not return to its count |
| `[warn] other add-on` | another add-on's table differs — it may log what ran, or hold something of ours |
| `[warn] server error log` | entries were written while it ran |

`--unattended` skips it, and it never runs with seeding switched off.

### Uninstalling

**The add-on refuses to uninstall while any batch is recorded.** Uninstalling drops the records,
and the seeded content would then stay on the forum with nothing left that knows which it is. The
refusal says what to run first:

| to | run |
|---|---|
| delete the seeded content | `php cmd.php seeder:clean` |
| keep the content as the forum's own | `php cmd.php seeder:forget` |

`seeder:forget` deletes nothing but the records, so it runs with seeding switched off. Content it
forgets can never be cleaned.

## Limits

- **Seeded content shows as active today.** XenForo's content activity log, which feeds trending
  content, is dated the day the content was created whatever date the content carries.
- **Daily statistics are not touched.** Backdated content does not appear in the statistics graph
  unless statistics are rebuilt, and if they are rebuilt while seeded content exists, cleaning
  does not take it back out. Rebuild again after cleaning.
- **Seeding and cleaning both take about a second per fifty posts.**
- **Another add-on's own tables are that add-on's to clean.** Removal goes through XenForo's
  entities, so an add-on that cleans up when a member or post is deleted is handled; one that does
  not will show as a warning in `seeder:validate`.

## Mail

**Turn mail off before seeding a copy of a live forum.** The seeder sends nothing and gives every
generated member an address at a reserved domain such as `example.com`. But a forum that can send
will later mail its real members about seeded content — a digest, a watched thread.
`seeder:validate` warns when `enableMail` is on.

```php
$config['enableMail'] = false;
```

## Licence

MIT. See `LICENSE.md`.
