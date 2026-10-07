# TESTING

What this add-on touches, what breaks quietly, what the automated suite settles, and what still
needs a person.

## Surfaces

- **CLI commands** — `seeder:config` and `seeder:validate`. XenForo loads every add-on's command
  classes to list them, so one that cannot load stops every command on the forum.
- **`config.php`** — reads `$config['hampelSeeder']`, `enableMail`, and the type of the
  `emailTransport` option.
- **Schema** — one table, `xf_hampel_seeder_record`, created on install and dropped on uninstall.
  No columns on core tables.
- **Entity deletion** — `seeder:clean` deletes recorded entities of any type through the entity
  system, so every add-on's delete behaviour for that type runs.
- No class extensions, code event listeners, template modifications or options.

## Fragile points

- **The mail transport option holds credentials.** `seeder:config` prints its type only.
- **Faker falls back to `en_US` silently**, provider by provider, for a locale it does not know.
  `seeder:validate` warns when a locale has no providers at all.
- **A release built without `vendor/` installs nothing useful.** `build.json`'s exec steps cannot
  fail the build, so `Setup::checkRequirements()` refuses the install instead.

## Automated

```bash
vendor/bin/phpunit
vendor/bin/phpunit --order-by=random
```

Covers: both commands load as valid XenForo commands; the guard opens only on a literal `true`;
`config` shows each value's source, prints no transport setting but its type, and sends nothing;
`validate` exits 0, 1 and 2 as documented with and without `--strict`; a check that throws becomes
a `[fail]` row and does not end the run. For records: an entity is recorded once, with the real
date; clean removes a batch newest first and nothing outside it; an entity already gone, a removal
that throws and an entity type that no longer exists are each reported; clean refuses with seeding
off, on `n`, and with nobody to answer.

**The record tests write to the real database inside a transaction.** Each names its own batch and
none cleans every batch — on a forum holding real seeded content that would walk it, and an
entity's delete can do things a rollback does not undo. Keep it that way.

`FrameworkHalfTest` fails if anything in the framework half references the add-on half.

## Needs a human

- **After building a release, unzip it and confirm `vendor/fakerphp/` is inside** and that
  `CLAUDE.md`, `TESTING.md` and `PROPOSAL-seeder.md` are not.
- The commands have run on XenForo 2.3 only.
- **Uninstall has not been run.** `uninstallStep1()` drops the record table; a forum uninstalled
  with batches still recorded keeps the content and loses the means to remove it.
