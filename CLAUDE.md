# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on — `Hampel/Seeder`, a test data generator. It sits at
`src/addons/Hampel/Seeder/` inside a XenForo install and is its own git repository; the install
around it is not, and any sibling add-ons beside it are other repositories.

Where the install has an `AGENTS.md` at its root, read it first — it carries the XF conventions,
the `_output`/`_data` boundary, the `cmd.php` command signatures and the per-add-on git layout.
This file covers only what is specific to this add-on.

## Read `PROPOSAL-seeder.md` before building anything

It is the design: seeders, factories and Faker in the shape of Laravel's seeding, on top of a
record of everything created so that a run can be removed again. It carries the build sequence,
what was verified against XenForo's source, and what was not. Update it when a decision changes.

## Two halves, and the first must not know about the second

| half | holds | may reference the other |
|---|---|---|
| framework | `Seed/`, `Factory/`, `Faker/`, `Timeline.php`, the `Recorder` interface | **no** |
| add-on | `Guard.php`, `Config.php`, `Cli/`, the record table and its entity | yes |

**Nothing in the framework half may call `Guard`, `Config`, or the record entity.** Whether that
half becomes a Composer package other add-ons require is undecided; kept apart, extracting it is a
move. A factory that reads `config.php` would end that.

**A package would keep the `Hampel\Seeder` namespace, so extraction breaks nobody's seeders.**
Checked on 2026-10-12 by registering a prefix through XenForo's class loader as an add-on's
Composer autoload does: a package's prefix is searched before the add-on directory, and a class
the package lacks is still found in the add-on. Two things follow for whoever does it:

- **Move the classes, never copy them.** Where both hold a class the package's copy loads and
  the add-on's is silently dead.
- **Nothing may name a framework class before the add-on's autoload is registered** - `Setup`
  during an install above all. The loader remembers a miss for the life of the process.

The check was a simulation in one process on XenForo 2.3.12, not an extracted package installed
from a zip.

## The guard is `config.php`, and only a literal `true` opens it

`Guard::assertEnabled()` is the first call in every command that writes. There is deliberately no
option for it: an option lives in the database, and a copy of another forum's database would bring
it along.

## Nothing a seeded entity does may leave the forum

No mail, alerts, push notifications, webhooks or moderator log. Do not call a creator service's
`sendNotifications()`, do not use `User\RegistrationService`, and take email addresses from
reserved domains only. `AbstractFactory::silence()` switches the `XF:Webhook` behaviour and the
`log_moderator` option off; a factory that overrides `remove()` must call it. **Silence every
entity the save touches, not only the one being saved** - applying a warning saves the post and
the member too.

**Prefer the entity to a XenForo service where the service does something outward.** The warn
service and the reaction repository both queue a webhook and can alert, so warnings and reactions
are written through their entities. The API key manager service emails every super
administrator, so keys are too. The Resource Manager's rating service alerts the resource's
author and XenForo's featured content service writes the moderator log, so ratings and features
are too.

**Where the only outward effect is another add-on's hook on a XenForo service, write through the
entity by default and offer the service as an opt-in** that says what it means before it runs.
User upgrades are the model: `withHooks()` and `hooks=1`.

**Anything new the add-on learns to create goes into `Seed\SampleSeeder`**, which is what
`seeder:validate` seeds and removes.

**An HTML widget stays out of `SampleSeeder`.** Saving its template marks every style as changed
and empties the cached CSS, which validating a forum must not do. The other widgets are in.
**So do user groups, and members given permissions**, for the same reason: deleting a user group
does the same to the styles and the CSS.

**Never generate a bare web address in a message.** XenForo turns one into a link preview and
queues a job that fetches it. Generated links carry link text and point at `example.com`.

## Content that belongs to another add-on

Resource Manager and Media Gallery factories **name those add-ons' classes only as strings** and
call `needs()` first, so Seeder loads and runs on a forum without them. Never `use` a class from
another add-on. `SampleSeeder` includes them only where the add-on is active.

**The suite cannot test them**: it loads this add-on alone. After changing one, run
`seeder:validate` on a forum that has the add-on, and count the files as `TESTING.md` says.

## Seeded error log entries are never recent

**`ErrorLogFactory::store()` moves any entry dated inside the quiet period back out of it**, and
that check is the last thing before the save so no state or override gets past it. Other add-ons
post new errors to chat as they appear; a seeded log must not look new. Do not add a way round
it, and do not put the error log in `SampleSeeder` or `DefaultSeeder`.

**The admin log keeps the same period and stays out of both seeders for the same reason.**
`AdminLogFactory::store()` makes the same check, against `ErrorLogFactory::quiet()`.

The factory lives in the framework half and cannot read `config.php`, so the period is handed to
it: `SeedRunner` calls `ErrorLogFactory::quietFor()` with `Config::errorLogQuietHours()`.

## A date left to its column default is wrong

**`'default' => \XF::$time` in an entity structure is evaluated once per process**, when the
structure is first built. With the clock moved for each save, the default freezes at whichever
moment that happened to be, and every later row of that kind gets it. Found on 2026-10-08 as
deletion log entries all carrying one date, a month before the posts they were about.

So moving the clock is not enough for a row whose date is a default. **For every row a new
factory's save writes, read the date back and compare it** - and where it is a default, set it
by hand afterwards, as `WritesPosts::finish()`, `attach()` and `ReactionFactory::store()` do.

## Dates on posts are forced in after validation

XenForo's thread creator and replier stamp `time()` during `validate()`, and validation readies the
save. So `ThreadFactory` and `PostFactory` validate first and then set the dates with `forceSet`.
Setting them earlier is silently overwritten; setting them later without `forceSet` throws.

## Composer

**Faker is a runtime dependency**, loaded through `composer_autoload`, so `build.json` re-runs
`composer install --no-dev` into the release.

- **`replace` names `psr/container` and `symfony/deprecation-contracts` on purpose.** Faker
  requires both and XenForo 2.3 already ships both. XenForo's class loader is consulted first, so
  a second copy in this add-on's `vendor/` would never load and would only ship.
- **`config.platform.php` is 8.3 while the add-on's floor is 8.1**, and they cannot be the same
  number: the test framework needs 8.3 and the pin governs the whole solve. So after any
  `composer update`, check the runtime packages still meet 8.1:

  ```bash
  python3 -c "
  import json; l=json.load(open('composer.lock'))
  for p in l['packages']: print(p['name'], p.get('require',{}).get('php','-'))"
  ```

**`phpunit/phpunit` is required in `require-dev` and never imported, on purpose.** Every active
add-on that declares `composer_autoload` shares XenForo's class loader, and two PHPUnit majors in
it kill a test run; the pin keeps a `composer update` from moving this add-on into that. Pinned to
`^12.0` on 2026-10-07. It can come off if the test framework ever pins PHPUnit itself.

## Two ways to create, and which to use

| the table's rows | use |
|---|---|
| are content: saving one updates counters, an index, another add-on | `AbstractFactory`, one entity at a time |
| stand alone, and there may be tens of thousands | `AbstractBulkFactory`, multi-row inserts |

**Do not reach for the bulk path because something is slow.** It skips everything an entity save
does, so content written through it leaves the forum inconsistent. Who is online is the model
case: no dependents, no side effects, pruned by XenForo within the hour.

A bulk factory never overwrites: a key already in the table is skipped and not recorded.

**Never point a bulk factory at a table other add-ons add columns to** - the member, profile,
conversation and post tables above all. A multi-row insert names only the columns it knows. A
column another add-on added without a default makes that insert fail outright (MySQL error 1364
under strict mode), and which add-ons are installed differs from forum to forum. An entity save
has the same exposure but XenForo's own code shares it, so such a column gets found and fixed; a
bulk insert of ours would be the only thing broken.

## Tests that create content

**They run against the real database inside a transaction, and three things end that transaction
early** — after which every later write in the test is permanent:

- **a job that runs and throws.** The job manager answers with `rollbackAll()`. A job run in a
  test has only this add-on loaded, so it throws on the first entity another add-on extends. Fake
  the queue with `fakesJobs()` in any test that creates or removes content.
- **cleaning every batch.** Always name the batch.
- **attachments write real files**, which a rollback does not remove. A test that attaches must
  clean its batch in a `finally`.
- **another process deadlocking with it.** MySQL answers a deadlock by rolling the whole
  transaction back, and the test carries on outside one. Another add-on's suite running against
  the same database at the same moment is enough. If a run fails on `DeadlockException`, check
  `pgrep -af phpunit` before believing anything else it reports, then look for what it left.
- **a hot table.** Every page view writes `xf_session_activity`, so a test holding locks on
  thousands of its rows can deadlock with a real visitor. `BulkTest` is kept small for that
  reason. Assert that a clean's `failed` list is empty: the cleaner turns a failed removal into a
  list entry and carries on, which in a test means carrying on outside the transaction.
- **DDL.** `CREATE TABLE` commits. No test here creates or drops a table.
- anything else that calls `rollbackAll()` or `commit()`.

**Every test in a transaction runs with the data registry faked** - `Tests\TestCase::setUp()` does
it. Creating or removing content rewrites several registry rows, the forum's own cron writes the
same rows every minute, and on MariaDB 11.8 the loser of that race gets `Record has changed since
last read` (error 1020). Before this, about one run in three failed on it. A test that needs the
real registry row has to say so and take the race.

**The same `setUp()` stands in for the member statistics repository**, for the same reason one
table over. Saving a new member calls `emptyCache('staff_members')`, which writes a row of
`xf_member_stat`, and XenForo's `memberStatsCache` cron rewrites that table every ten minutes.
Found on 2026-10-12 as one failed run that crossed the ten-minute mark. Only `emptyCache()` is
stood in for: a test that reaches any other method of that repository fails and says so.

**Never empty or bulk-delete from the record table in a test**, even inside a transaction. On a
forum where other people have batches, the day the transaction does not hold their content is
stranded. Mock `RecordRepository` instead.

**A test that saves a prefix or a warning definition calls `useTemporaryCodeCache()`.** Saving one
recompiles a phrase group into the code cache, a real file a rollback does not put back.

**Never run the real `seeder:validate` round trip from a test outside a transaction.**
`CommandsTest` replaces it with a canned result; `RoundTripTest` runs the real one in a
transaction with the queue faked.

The framework reports it (*This test's transaction did not survive*). Treat that message as
"something was left in the database" and go and look.

## Seeded runs stay reproducible only if nothing reseeds

Faker reseeds `mt_rand()` at random when a generator is destroyed. `Context::faker()` collects
garbage before seeding for that reason; do not create a second generator during a run.

## Commands and tests

```bash
vendor/bin/phpunit
php ../../../../cmd.php seeder:config
php ../../../../cmd.php seeder:validate
```

`Cli/RendersReport.php` is a copy of the trait `Hampel/Monolog` carries, with the namespace
changed. Do not improve it here: fix the original and take it again.

Every `\XF::logError()` and `logException()` in this add-on begins with `Seeder: `.
