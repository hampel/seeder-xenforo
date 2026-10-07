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
move and a namespace rename. A factory that reads `config.php` would end that.

## The guard is `config.php`, and only a literal `true` opens it

`Guard::assertEnabled()` is the first call in every command that writes. There is deliberately no
option for it: an option lives in the database, and a copy of another forum's database would bring
it along.

## Nothing a seeded entity does may leave the forum

No mail, alerts, push notifications or webhooks. Do not use a creator service's
`sendNotifications()`, do not use `User\RegistrationService`, switch the `XF:Webhook` behaviour
off on every entity a factory saves, and take email addresses from Faker's `safeEmail()` only.

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

## Commands and tests

```bash
vendor/bin/phpunit
php ../../../../cmd.php seeder:config
php ../../../../cmd.php seeder:validate
```

`Cli/RendersReport.php` is a copy of the trait `Hampel/Monolog` carries, with the namespace
changed. Do not improve it here: fix the original and take it again.

Every `\XF::logError()` and `logException()` in this add-on begins with `Seeder: `.
