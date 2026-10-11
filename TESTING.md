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
- **Tags** — made through the `Tag` entity and applied through XenForo's tag repository, not the
  thread creator, which only tags where the author has permission. XenForo deletes a tag nobody
  uses, so cleaning often finds seeded tags already gone.
- **Reactions** — through the `ReactionContent` entity, not XenForo's reaction repository, which
  alerts the author and queues a webhook. The save rewrites the content's reaction summary, so the
  content is silenced first. Published to the news feed by hand, with the date corrected.
- **Content states** — moderated content is inserted in that state; deleted content is posted and
  then soft-deleted through `softDelete()` once its replies and reactions exist.
- **Who is online** — `xf_session_activity`, written in bulk with multi-row inserts and no entity.
  Each row is recorded; removal is a multi-row delete by key. A row whose key is already in the
  table is skipped and not recorded.
- **The server error log** — entries written through the `ErrorLog` entity as data. Other add-ons
  read this table and send what they find: see *Fragile points*.
- **API keys** — through the `ApiKey` entity, not XenForo's key manager service, which emails
  every super administrator. A run prints key values: they are live credentials on that forum.
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
- **Seeded error log entries are read by whatever reports on that log.** The quiet period - no
  entry within two hours of now - is all that keeps them from anything that posts new errors as
  they appear, and it rests on such a thing looking only at recent entries. Anything that selects
  by row id, or by a longer window, reports them. Known readers are named in
  `Seed\ErrorLogSeeder::READERS`; add to it when another turns up.
- **Cleaning seeded error log entries lowers the log's highest id.** A reader that keeps the id
  of the last entry it forwarded then holds one past the end of the table. One was seen, on
  2026-10-11, to treat that as an emptied log and be due to forward every real entry again.
- **A guest's who-is-online row is keyed by its address.** A seeded row at a real visitor's address
  would replace theirs and then be deleted with the batch. Addresses come from reserved ranges,
  and a key already present is left alone.
- **Bulk rows skip everything an entity save does.** Right for a table whose rows stand alone and
  wrong for anything else.
- **A key that is not text** - a binary address - is stored base64-encoded inside the record's
  JSON, since raw bytes cannot go into JSON.
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

For tags: new ones are made and recorded, existing ones used and left, names tidied, dates taken
from the thread, and a name the forum would not accept refused. For the cleaner: a removal that
loses a deadlock is tried again, and after three tries its record is kept.

For reactions and states: a reaction updates the post's summary and its author's score, is dated
after the reactor joined, appears in the feed with its own date and alerts nobody; reactors are
never the author and never repeat; a moderated thread and reply are in the approval queue and not
counted; a deleted thread was posted, replied to, reacted to and then deleted, its log dated after
its last reply, its reactions no longer counted, and no moderator log written; attachments carry
their post's date; all of it cleans back to the starting counts.

For bulk rows: a binary key survives being recorded and fits the column; members, guests and robots
are written in the numbers, networks and ranges asked for, with known robot names and view dates
inside the online window; a share can be put in one network, given IPv6 addresses or made stale;
a row already in the table is neither overwritten nor recorded nor cleaned; cleaning removes
exactly what was written and reports rows XenForo already pruned.

For the error log: no entry is dated inside the quiet period, whatever the span or the date asked
for, and the period can be lengthened but not shortened; each origin has the request state and
trace that mark it; each kind of fault has the file, message and trace a reader tells it by; a log
has faults that recur, covers many days and cleans away; the seeder names members only on page
views; whoever runs it is told, before anything is created, which active add-ons will report on
the entries.

For API keys: a key acts as a member by default and is made after the member was; `for()` names
a member even when others are recycled; super user, guest and inactive kinds; scopes are written
as rows that go when the key is cleaned, and an unknown scope is refused; no mail is sent. On a
real forum the seeded keys were sent to the API: member, guest and super user keys were accepted
and the inactive one refused.

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
- **That a seeded error log really does stay out of a chat channel.** Only the dates were checked,
  against the rule the poller on the development forum uses; the poller itself was not run.
- The commands have run on XenForo 2.3 only.
