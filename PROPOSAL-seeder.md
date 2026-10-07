# Seeder: a test data generator for XenForo, shaped like Laravel's seeding

**A plan for the `Hampel/Seeder` add-on: seeder classes, entity factories and Faker, on top of a
record table that lets every run be removed again.** Written for Simon to decide on, and for the
session that builds it. Nothing exists yet beyond this file.

It starts from the earlier design note (record table, config guard, clean-before-run build order)
and changes two things: the `boardUrl` gate is dropped, and the write layer becomes a
Laravel-style seeder and factory framework instead of one fixed `run` command.

## What carries over from Laravel, and what does not

**The three Laravel pieces map cleanly, and the mapping is the public API of the add-on.**

| Laravel | here | fits |
|---|---|---|
| `Seeder` class, `run()`, `$this->call([...])` | `Seed\AbstractSeeder`, same two methods | directly |
| `db:seed --class=` | `seeder:run [seeder]` | directly |
| `Factory`, `definition()`, states, `count()`, `sequence()` | `Factory\AbstractFactory`, same names | directly |
| `has()` / `for()` / `recycle()` | same names, resolved through entity relations | with a limit — see below |
| `afterMaking()` / `afterCreating()` | same | directly |
| `make()` versus `create()` | same; `make()` returns an unsaved entity | directly |
| `fake()` | `$this->faker` in a factory or seeder | directly |
| `WithoutModelEvents` | nothing | does not transfer |
| `migrate:fresh --seed` | `seeder:clean` | replaced — see below |

**Three places where copying Laravel would be wrong:**

- **A factory builds a content unit, not a table row.** A XenForo thread is a thread row and a
  first post that point at each other; a user is `xf_user` plus profile, option, privacy and
  authentication rows. So each factory owns a `store()` step that knows how its entity is really
  written, and `ThreadFactory` creates the first post itself. Laravel has no equivalent because
  Eloquent has no such coupling.
- **There is no `WithoutModelEvents`, deliberately.** In XenForo the entity lifecycle *is* the
  bookkeeping — reply counts, last-post data, message counts, the search index. Turning it off is
  what the importers do, and they pay for it with a full rebuild afterwards. The seeder keeps it
  on and suppresses only the outward-facing parts (below).
- **Laravel cleans up by dropping the database; this cannot.** A dev install or a copy of a live
  forum has content worth keeping. So every `create()` is recorded and `seeder:clean` removes
  exactly what was recorded. That is the one substantial thing here with no Laravel counterpart,
  and it is built first.

**One addition Laravel does not need: time.** The reason this is an add-on at all is that content
must carry dates. Factories take a timeline (`--span=90d`) and each child is dated after its
parent.

## Architecture

```text
Seeder/
├── Seed/                  seeders: AbstractSeeder, DefaultSeeder, UserSeeder, ForumSeeder, ...
├── Factory/               AbstractFactory + one per content unit (User, Forum, Thread, Post, ...)
├── Faker/                 XenForoProvider — usernames, thread titles, BB code bodies
├── Record/                the batch recorder and the cleaner
├── Timeline.php           date allocation across the span
├── Guard.php              the config.php check
├── Cli/Command/           run, clean, list, config, validate
└── Entity/ Repository/    SeedRecord
```

### Two halves, and the first must not know about the second

**The framework half is `Seed/`, `Factory/`, `Faker/`, `Timeline.php` and a `Recorder` interface;
the add-on half is the record table, the guard and the commands.** Nothing in the framework half
may reference the add-on half — no `\XF::config()` guard inside a factory, no direct use of the
record entity.

The reason is a decision deferred on 2026-10-07: whether the framework half becomes a Composer
package that other add-ons `require-dev`. Kept apart, extraction is a move and a namespace rename.
It is deferred rather than done because a package does not remove the need for the add-on
(`cmd.php` discovers commands only inside add-on directories), and it puts a copy of the framework
in every consuming add-on's `vendor/`, where the first autoloader registered wins. Decide after the
first add-on with its own tables has written a factory against this.

**The recorder is the only thing that differs between a site we must clean and one we will throw
away.**

| recorder | writes | for |
|---|---|---|
| `TableRecorder` | a row per entity, as it is saved | any install that outlives the run — the default |
| `NullRecorder` | nothing | a disposable sandbox, behind `--no-record` |

`--no-record` is still gated by the `config.php` flag. An unrecorded run cannot be cleaned, so it
is never the default and the command says so before it starts.

### Seeders

```php
class DefaultSeeder extends AbstractSeeder
{
	public function run()
	{
		$users = UserFactory::new()->count($this->param('users', 20))->create();

		$this->call(ForumSeeder::class, ['users' => $users]);
	}
}
```

- **`seeder:run` with no argument runs `DefaultSeeder`**, as `db:seed` runs `DatabaseSeeder`.
- **Unlike Laravel, a seeder takes parameters** — `--param users=50`, read with `$this->param()`.
  An agent needs to say "200 threads" without writing a class. There are no per-seeder named
  options: a command cannot know which seeder it will be given, so `-p name=value` is the one
  form.
- **Any add-on can ship its own seeders and factories.** `seeder:run Vendor/Addon:Name` resolves
  to `Vendor\Addon\Seed\NameSeeder`. The class extends ours but is only loaded when asked for, so
  the other add-on takes no hard dependency. XFRM and XFMG seeders live in this add-on, each
  checking the target add-on is installed.

### Factories

```php
class ThreadFactory extends AbstractFactory
{
	protected $entity = 'XF:Thread';

	public function definition()
	{
		return [
			'title' => $this->faker->threadTitle(),
			'user_id' => UserFactory::new(),      // resolved lazily, as in Laravel
			'node_id' => ForumFactory::new(),
		];
	}

	public function locked()  { return $this->state(['discussion_open' => false]); }
	public function sticky()  { return $this->state(['sticky' => true]); }
}

ThreadFactory::new()
	->count(200)
	->recycle($users)                             // pick authors from an existing pool
	->for($forum)
	->has(PostFactory::new()->count([1, 40]))     // a range, weighted low
	->create();
```

**The base class does the three things no individual factory should have to remember:**

1. **Records the entity** in the batch as soon as it is saved. A third-party factory gets cleanup
   for free, and a run that dies halfway has still recorded everything it made.
2. **Moves the clock.** `\XF::$time` is a public static (`src/XF.php:69`). `create()` sets it to
   the entity's allocated date for the duration of the save and restores it after — the same idea
   as `Carbon::setTestNow()`. Every side-effect row the entity lifecycle writes (news feed, IP log,
   moderation records) then lands on the right date without each factory setting each column.
3. **Suppresses the outward-facing behaviour** (next section).

**`has()` and `for()` only work where the entity declares the relation.** Anything else is set by
passing the column in `state()`.

### Faker

**`fakerphp/faker` `^1.24` as a runtime Composer dependency**, loaded through `composer_autoload`.
It needs PHP `^7.4 || ^8.0`, `psr/container ^1.0 || ^2.0` and `symfony/deprecation-contracts
^2.2 || ^3.0`. XenForo 2.3.12 already bundles `psr/container 1.1.1` and
`symfony/deprecation-contracts 2.5.4`, so the add-on's `composer.json` declares both under
`replace` and ships Faker alone — no duplicate classes across two autoloaders.

- **`--seed=N` makes a run reproducible.** Faker is seeded, so the same command produces the same
  users, titles and bodies. For an agent that is worth more than the realism.
- **A `XenForoProvider`** covers what stock Faker gets wrong for a forum: usernames that pass
  XenForo's rules and do not collide with existing members, thread titles that are not lorem
  ipsum, and message bodies in BB code with the occasional quote, list and link.
- **Email addresses come from `safeEmail()` only**, which is restricted to `example.com`,
  `example.org` and `example.net`. This is a rule, not a default: a seeded member must never hold
  an address that could belong to someone.
- Locale is a config key, default `en_AU`.

## Nothing a seeded entity does may leave the forum

**A seeder run on a copy of a live forum must not send mail, alerts, push notifications or
webhooks.** The mail-transport hazard is the reason: a copied database brings its live transport
with it.

- **No creator services for notification.** `Thread\CreatorService` and `ReplierService` send
  notifications from a separate `sendNotifications()` call. Factories write entities and never make
  that call. `User\RegistrationService` is not used at all — it sends confirmation and welcome
  mail.
- **Webhooks are switched off per entity.** `Post` and `Thread` carry the `XF:Webhook` behaviour,
  which fires on every save and has an `enabled` option (`src/XF/Behavior/Webhook.php`). The base
  factory sets it to `false`. Found while surveying; the earlier design did not account for it.
- **The news feed is left on.** It is an internal table, it is dated from `post_date`, and a forum
  with an empty activity feed is not a realistic one. It is swept on clean.
- `seeder:validate` reports `enableMail` and the mail transport type, so a run on a risky install
  is visible before it starts.

## The guard

```php
$config['hampelSeeder']['enabled'] = true;
```

**Every command that writes refuses unless that line is in `src/config.php`.** The flag lives on
the forum, not with the caller, so a production install — which never has the line — cannot be
seeded by a mistyped path. The earlier design's second check, refusing unless `boardUrl` ends in
`.local`, is dropped: sandbox instances do not have that shape.

`seeder:clean` asks before deleting unless `--force`. It is still gated by the config flag.

## Records and cleanup

```text
xf_hampel_seeder_record
  record_id     uint pk auto
  batch         varchar(50)
  entity        varchar(100)     short name: 'XF:Thread'
  entity_id     varbinary(100)   primary key value; JSON for a composite key
  factory       varchar(150)     the factory class that made it, if one did; nullable
  record_date   uint             the real time of recording, not the entity's moved clock
```

`(entity, entity_id)` is unique, so an entity belongs to the batch that recorded it first.

**Keyed by entity short name, not content type** — nodes, user groups and most add-on entities
have no content type.

**Clean walks the batch in descending `record_id`.** Creation order is already dependency order,
so reversing it deletes posts before threads before forums before users, with no per-type ordering
table to maintain. Each factory may override `remove()`; the default is `$entity->delete()`. The `factory` column is
how clean finds that override without a table of which type needs what.

**A record that cannot be removed stays**, the rest of the batch is still cleaned, and the command
exits 1 — so clean can be run again. An entity already gone is reported and its record cleared.

Then, in order:

1. **Delete recorded entities**, newest first, through the entity system so cascades and the
   search index are right.
2. **Run user clean-up inline.** Deleting a user enqueues `UserDeleteCleanUp`; clean runs
   `User\DeleteCleanUpService` directly so the command does not return with work still queued.
   Users go last because that service's `stepChangeOwner` reassigns any content still attributed
   to them.
3. **Sweep what the clean-up service leaves.** It deletes `xf_ip` rows for the user itself and
   explicitly keeps content IPs; it does not touch `xf_news_feed`, `xf_session_activity`,
   `xf_thread_view` or `xf_attachment_view`. Sweep these by the batch's user and content ids.
4. **Delete `xf_stats_daily` rows for the batch's date range, then rebuild that range.**
   `StatsRepository::build()` upserts counters and never removes a row, so a day whose only
   content was seeded would keep its stale count. The stock `xf-rebuild:stats` empties the whole
   table first, which is correct but heavier than needed.
5. **Rebuild counters** for the forums, threads and users the batch touched but did not create —
   a seeded post in an existing thread, for instance.
6. Delete the batch's records and report what was removed, table by table.

**Run and clean both drain the jobs they queued before returning** (`Record\JobDrain`), then
rebuild the board statistics cache. Removing a member queues a search re-index per member; left
queued, the command looks finished while the forum is still changing, and a row-count comparison
taken straight afterwards is wrong.

**`run` refuses a batch name that already exists.** Batches default to a timestamped name;
`seeder:list` shows each with its counts and date range.

## Commands

```bash
php cmd.php seeder:run [seeder] [--param k=v ...] [--span=90d] [--seed=N] [--batch=NAME]
php cmd.php seeder:clean [batch] [--force]        # default: every batch
php cmd.php seeder:list
php cmd.php seeder:config
php cmd.php seeder:validate
```

- **`run` writes JSON to stdout and a human summary to stderr**, so `seeder:run 2>/dev/null | jq`
  works. The JSON lists every created entity by type and id, with the batch name.
- **Seeded users share one password, printed in the output.** An agent testing login needs it.
- `config` and `validate` follow the house pattern for add-on commands.

## What the importer framework is and is not good for

**`XF\Import\Data\*` is not the write layer.** It can be driven without an import session — a
`DataManager` needs only a `Log` object, and `save(false)` skips the log — but it writes without
counters and relies on a full rebuild afterwards, which is the opposite of what a seeder adding 200
threads to an existing forum wants. Entities with the clock moved do the same job and keep the
counters right.

It remains the reference for which side-effect rows a backdated entity needs:
`insertStateRecord()` and `importRawIp()` in particular.

## Build sequence

Each phase ends with something that runs.

1. **Skeleton.** `xf-addon:create`, Composer with Faker and the test framework, `build.json`,
   `.gitignore`, `.gitattributes`, the guard, `seeder:config` and `seeder:validate`.
2. **Records and `clean`, before anything creates content.** Table, entity, recorder, cleaner,
   `seeder:list`. `clean` against an empty table proves the plumbing.
3. **Factory core with `UserFactory`.** `definition`, `state`, `count`, `sequence`, `make`,
   `create`, recording, the clock, Faker and `--seed`. First real round trip: create 20 users,
   clean, compare. *Done 2026-10-08, along with `seeder:run`, the JSON output, `--span` and the
   name-to-class resolution that phase 4 needed.*
4. **A first consumer in another add-on.** The mechanism exists; what is untested is whether it
   is a usable harness, and the case that tests it is an add-on with its own tables. That factory
   is written in the other add-on, by its own session.
5. **The no-trace test** (below), which every later phase must keep passing.
6. **Forums, threads, posts and `Timeline`.** `has`, `for`, `recycle`. `DefaultSeeder` and
   `--span`.
7. **The rest, each as its own factory:** reactions, conversations, profile posts, attachments
   (real image bytes with a real extension), polls, bans and warnings, then XFRM and XFMG.

### The no-trace test is the acceptance test

**Count the rows in every `xf_` table, seed, clean, count again; the two must match.** Tables that
legitimately move (sessions, the job table, caches) go on a short named allow-list. This is the
test that says cleanup is correct, and it finds the side-effect tables nobody listed. It needs a
real database, so it runs in a disposable sandbox instance, not in the unit suite.

Unit tests cover factory resolution, state merging, sequences and timeline allocation with Faker
seeded.

## Decisions for Simon

- **PHP floor.** Faker allows 7.4. Recommended: **8.1**, unless a 7.4 sandbox needs seeding — that
  costs readonly properties, enums and first-class callables across the whole framework.
- **XenForo floor.** Recommended: **2.3 first**. Entity short names (`'XF:Thread'`) are used
  throughout so that a later 2.2 port is not a rewrite, but nothing is tested on 2.2 until asked.
- **Command prefix.** `seeder:` here; `hampel-seeder:` if the short form risks a collision.
- **Is this ever released?** If so, the title should describe the function ("Seeder: test data
  generator") and the resource description must say it is for development installs. The build
  strips this file either way.
- **Search indexing during a run.** Left on, so search is correct without a rebuild. A large run
  is slower for it. A `--no-index` option with a rebuild at the end is easy to add if runs get big.

## Not verified

- **That moving `\XF::$time` is sufficient for every dated side effect.** Verified: the property is
  public and static, and the news feed behaviour dates from `post_date`. Not verified: every
  repository that stamps a row. The no-trace test does not catch a wrong date, so phase 6 needs a
  check that reads the dates back.
- **Whether an add-on's feature tests write to the real database.** If they do, a factory called
  from a test suite on an install that outlives the run leaves untracked content unless it records.
  This bears on the package question above.
- **Thread and forum last-post data with posts created out of date order.** `Thread::postAdded()`
  only advances `last_post_date` when the new post is later, which is right if posts are created
  oldest first. Factories will create in date order; a test should pin it.
- **Speed with content.** Members are measured: 500 created in 10 seconds and removed in 32, on
  2026-10-08. Removal is the slow half, since each member runs XenForo's own clean-up service and
  a search job. Threads and posts are not measured yet.
- **Faker's `replace` entries on XenForo 2.2**, whose bundled versions were not read.

## Found while building

- **A Faker generator reseeds `mt_rand()` at random from its destructor**, and holds a reference
  cycle with its providers, so a discarded one is destroyed whenever the collector next runs. In a
  process that has made more than one generator, that lands part-way through a seeded run and
  ends its reproducibility. `Context::faker()` collects cycles before it seeds.
- **A forum's own rules decide what a member may be called.** The development install caps names
  at 25 characters where XenForo's default is 50. The member factory asks the entity whether a
  name is acceptable instead of copying the rules.
- **A job run inside a test runs with only this add-on loaded**, throws on the first entity
  another add-on extends, and the job manager answers a throw with `rollbackAll()` — which ends
  the test's transaction and makes its later writes permanent. Tests that create content fake the
  job queue.

## How this was surveyed

2026-10-07, read-only, against XenForo 2.3.12 on PHP 8.3: `src/XF.php`, `src/XF/Import/Data/`,
`src/XF/Import/DataManager.php`, `src/XF/Service/User/DeleteCleanUpService.php`,
`src/XF/Repository/StatsRepository.php`, `src/XF/Job/Stats.php`, `src/XF/Behavior/Webhook.php`,
the `Post` and `Thread` entity structures, the thread creator and replier services, and
`src/vendor/composer/installed.json`. Faker's requirements were read from Packagist for `v1.24.1`.
The Laravel seeding and factory APIs were mapped from knowledge of Laravel 12, not re-read.
