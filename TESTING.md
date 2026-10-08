# TESTING

What this add-on touches, what breaks quietly, what the automated suite settles, and what still
needs a person.

## Surfaces

- **CLI commands** — `seeder:config` and `seeder:validate`. XenForo loads every add-on's command
  classes to list them, so one that cannot load stops every command on the forum.
- **`config.php`** — reads `$config['hampelSeeder']`, `enableMail`, and the type of the
  `emailTransport` option.
- **Members** — `seeder:run` writes `xf_user` and its option, profile, privacy, authentication
  and group-relation rows through the entity, with XenForo's clock moved to each member's
  registration date. Removal runs XenForo's own delete clean-up service inline.
- **Forums, threads, replies, prefixes, attachments** — forums and prefixes through their
  entities; threads and replies through XenForo's `Thread\CreatorService` and `ReplierService`,
  with notifications never sent; attachments through `Attachment\PreparerService`, as real PNG
  files in `internal_data`. Removal deletes the entity, then any attachment files left unused.
- **Reactions** — through the `ReactionContent` entity, not XenForo's reaction repository, which
  alerts the author and queues a webhook. The save rewrites the content's reaction summary, so the
  content is silenced first. Published to the news feed by hand, with the date corrected.
- **Content states** — moderated content is inserted in that state; deleted content is posted and
  then soft-deleted through `softDelete()` once its replies and reactions exist.
- **Warnings** — definitions through their entity and three phrases; warnings through the
  `Warning` entity, not XenForo's warn service, which queues a webhook and can send a conversation.
  Saving a warning saves the post it is about and the member it was given to, so both are
  silenced first.
- **The job queue** — run and clean both run the jobs they queued before returning, and rebuild
  the board statistics cache.
- **Schema** — one table, `xf_hampel_seeder_record`, created on install and dropped on uninstall.
  No columns on core tables.
- **Entity deletion** — `seeder:clean` deletes recorded entities of any type through the entity
  system, so every add-on's delete behaviour for that type runs.
- **Code event listener** — `addon_pre_uninstall`, hinted to this add-on, refuses the uninstall
  while a batch is recorded. XenForo marks the add-on as processing before it fires the event, so
  the listener takes that mark back before it throws.
- No class extensions, template modifications or options.

## Fragile points

- **The mail transport option holds credentials.** `seeder:config` prints its type only.
- **Faker falls back to `en_US` silently**, provider by provider, for a locale it does not know.
  `seeder:validate` warns when a locale has no providers at all.
- **Post dates.** XenForo's services stamp the real time during validation; the factories force
  the content's date in afterwards. A XenForo release that changes that flow breaks backdating
  quietly: content would all be dated now.
- **Deleting content is moderator-logged by default**, and other add-ons hook that log. Seeded
  entities have it switched off.
- **XenForo's permission builder caches the forum tree per process.** Dropped before queued jobs
  run, or a rebuild after cleaning writes a deleted forum's permission rows back.
- **A failed delete leaves a transaction open.** Each removal runs in its own and is unwound to
  where it started, reading the adapter's savepoint count by reflection.
- **A column default of `\\XF::$time` is evaluated once per process**, when the entity's structure
  is first built. With the clock moved, that freezes the default at whatever moment the first
  entity of that kind happened to be saved - so a date left to its default is wrong for every one
  after it. Known cases are set by hand: deletion log, attachment dates, the reaction feed row.
  Any new factory has to check the dates of every row its save writes.
- **A warning past its expiry is saved live and then expired**, in two steps under two clocks, so
  XenForo applies and removes its points itself. Saved in one step it would stay live for ever.
- **Warning actions are the forum's.** A seeded warning that takes a member past a threshold
  triggers whatever the forum has configured - a ban, a group change. XenForo reverses it when the
  warning is removed; that reversal has only been run on a forum with no actions set.
- **The content activity log is dated today**, whatever the content's date.
- **Member names are the forum's to judge.** Length limits, the match pattern and the censor list
  all vary by forum, so the factory asks the entity and tries another name on a refusal.
- **Faker reseeds at random when a generator is destroyed**, which would end a seeded run's
  reproducibility part-way through.
- **Another add-on's tables.** A row-count comparison across a seed and a clean will show any
  add-on that logs job runs or page views; that is theirs, not a leak.
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

For seeding: the factory surface (states, sequences, counts, callbacks, copies); a member is whole,
dated inside the window, has a reserved-domain address and the run's password; names obey the
forum's length limit; no mail is sent and webhooks are off; cleaning members restores the row
counts; `seeder:run` refuses with seeding off, on bad input and on a batch that exists, prints JSON
and a summary on separate streams, and on a failing seeder exits 1 with its work recorded.

For content: a thread is a thread and its first post, dated as asked, with forum and member
counters right; replies are in date order and never precede their author; `recycle()`, `for()` and
`has()` create nothing beyond what they were given; the `with...()` states add up and stay on
reserved domains with no bare address; a prefix has its phrase and forum association; an attachment
is a real PNG and its file is gone after cleaning; a seeded forum cleans back to its starting
counts across eighteen tables with no alert, mail, watch, IP or moderator log row; a removal that
fails mid-transaction is undone and the rest still happen.

For reactions and states: a reaction updates the post's summary and its author's score, is dated
after the reactor joined, appears in the feed with its own date and alerts nobody; reactors are
never the author and never repeat; a moderated thread and reply are in the approval queue and not
counted; a deleted thread was posted, replied to, reacted to and then deleted, its log dated after
its last reply, its reactions no longer counted, and no moderator log written; attachments carry
their post's date; all of it cleans back to the starting counts.

For warnings: a definition has its three phrases and cleaning removes them; a warning on a post
has the right member, moderator, title, points and dates, marks the post, raises the member's
points, and leaves the moderator log, conversations and alerts untouched; a warning past its expiry
is expired with its points taken back; `against()` and `custom()`; the forum's own definitions are
used when none are given; cleaning takes the points back and unmarks the post.

**Tests that save a prefix or a warning definition use a temporary code cache**
(`useTemporaryCodeCache()`), because that save recompiles a phrase group into a real file.

For the round trip: the real one, in a transaction, creates and removes a forum and reports no
table changed; a row left behind in a core table is a leak; differences are sorted into leaks,
other add-ons' tables and tables that move anyway; each outcome renders as the right row and exit
code.

**Those tests fake the job queue**, so they do not prove the drain - `seeder:validate` on a real
forum does. See *Needs a human*.

For uninstalling: the listener is registered for this add-on only, refuses while a batch exists
without leaving the add-on marked as processing, and allows it once nothing is recorded;
`seeder:forget` discards records and leaves content.

`FrameworkHalfTest` fails if anything in the framework half references the add-on half.

## Needs a human

- **After building a release, unzip it and confirm `vendor/fakerphp/` is inside** and that
  `CLAUDE.md`, `TESTING.md` and `PROPOSAL-seeder.md` are not.
- **A disposable forum, before a release.** Install the built zip on a fresh XenForo with
  development off, switch seeding on, and run in order: `seeder:validate`, `seeder:run`, an
  uninstall (refused), `seeder:clean`, an uninstall (succeeds, and the record table is gone). Last
  run on 2026-10-08 against XenForo 2.3.12 and PHP 8.3, all as described.
- **An uninstall from the admin control panel** has not been clicked; the command line takes the
  same path to the listener.
- **`seeder:validate` on a real forum, with the queue live**, after any change to a factory or to
  cleaning. It is the only check that runs with every add-on loaded and jobs really running, and
  it is what found the permission-cache leak.
- The commands have run on XenForo 2.3 only.
