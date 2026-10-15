# TESTING

What this add-on touches, what breaks quietly, what the automated suite settles, and what still
needs a person.

## Surfaces

- **CLI commands** — `seeder:config`, `seeder:validate`, `seeder:run`, `seeder:list`,
  `seeder:clean` and `seeder:forget`. XenForo loads every add-on's command classes to list them,
  so one that cannot load stops every command on the forum.
- **`config.php`** — reads `$config['hampelSeeder']`, `enableMail`, and the type of the
  `emailTransport` option.
- **Members** — `seeder:run` writes `xf_user` and its option, profile, privacy, authentication
  and group-relation rows through the entity, with XenForo's clock moved to each member's
  registration date. Removal runs XenForo's own delete clean-up service inline.
- **Forums, threads, replies, prefixes, attachments** — forums and prefixes through their
  entities; threads and replies through XenForo's `Thread\CreatorService` and `ReplierService`,
  with notifications never sent; attachments through `Attachment\PreparerService`, as real PNG
  files in `internal_data`. Removal deletes the entity, then any attachment files left unused.
- **Widgets** — through the `XF:Widget` entity, with the title phrase saved after it and, for an
  HTML widget, a master template saved before it, as XenForo's widget form does. Removal deletes
  the entity, which deletes the phrase and template, then the compiled file in
  `internal_data/code_cache/widgets` that XenForo leaves. An HTML widget is not in the sample.
- **Polls** — through XenForo's poll creator, saved with the thread as the thread form saves
  one. Votes are rows in `xf_poll_vote` followed by XenForo's own rebuild of the poll's totals,
  since its voting queues a webhook. A discussion forum's *Allowed thread types* setting is
  written into its type settings.
- **Tags** — made through the `Tag` entity and applied through XenForo's tag repository, not the
  thread creator, which only tags where the author has permission. XenForo deletes a tag nobody
  uses, so cleaning often finds seeded tags already gone.
- **Conversations** — through XenForo's `Conversation\CreatorService` and `ReplierService`, with
  notifications never sent. XenForo has no delete for a single message, so the factory removes one
  and corrects the conversation's counts and last message itself.
- **Custom user fields** — the field through its entity, with its phrases; values through the
  member's profile as an administrator sets them. XenForo keeps its list of fields for the life of
  the process and rebuilds it at the end of the request, so the factory forces both.
- **User upgrades** — records through their entities and groups through XenForo's group change
  service; XenForo's upgrade service and repository only when hooks are asked for. A payment
  profile, which XenForo never deletes, is removed by deleting its row.
- **Resource Manager resources** — through `XFRM:ResourceItem\Create`, as a fileless resource, with
  the dates of the resource, its description and its version all set by hand. A rating through
  its entity, since the rating service alerts the author; a featured resource through the featured
  content entity, since that service writes the moderator log and queues a webhook. A discussion
  thread through `ThreadFactory`, with the category's forum hidden from the Resource Manager
  while the resource saves so that it does not start one of its own.
- **Bans** — the `XF:UserBan` entity, which sets the member's banned flag itself and adds the
  forum's banned user group where one is set.
- **Media Gallery items** — a real PNG through the gallery's attachment handler, which makes the
  holding record and thumbnail; the item through its entity; albums through `XFMG:Album\Creator`;
  comments through their entity, which rewrites the item's and the category's comment counts.
  Files in `internal_data/attachments`, `data/attachments` and `data/xfmg`.
- **Other ways into an account, and activity without content** — connected account, two-step,
  passkey, remember key, confirmation, login IP, who-is-online, alert and follow rows, each through
  its entity (two-step through XenForo's `TfaRepository`). None is recorded: XenForo's member
  delete removes them all. A member without a password is given XenForo's own no-password scheme
  before the save; one without authentication is saved whole and has that record deleted.
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
- **Administrators** — the member, then the `Admin` entity, which sets the member's flag and
  writes the permission entries. Removed by XenForo's member clean-up. A seeded administrator can
  sign in to the admin control panel.
- **User groups and permissions** — the `UserGroup` entity, then XenForo's permission service,
  which writes the group's permission entries, global and on a node. A member given permissions joins the group before
  being saved, so XenForo builds their permission combination with it. Deleting a group is
  XenForo's own: its entries go, the style cache is marked changed and the CSS cache emptied, and
  a job removes every permission combination no member has. Not in the sample, for the CSS cache.
- **The moderator log** — rows inserted one at a time with `insert()`, never the entity or
  XenForo's logger, each id recorded as the insert gave it, and removed by id. Two add-ons on a
  forum can react to a moderator action as it is taken, by extending the logger or on the entity's
  save; neither sees a row. The bulk writer refuses a table another add-on has given a column with
  no default.
- **A member's history** — the change log is XenForo's own behaviour, written as each change is
  saved through the `User`, `UserProfile`, `UserOption` and `UserPrivacy` entities with the clock
  moved and the editor as the visitor. Username changes are `UsernameChange` entities with the new
  name trusted, as XenForo's own log of a change trusts it; a pending one puts itself in the
  approval queue. A rejection is the `User` entity's own `rejectUser()`, as the moderator. All of
  it goes in XenForo's member clean-up.
- **IP logs** — rows written through the `Ip` entity with their dates given, as XenForo's own
  `logIp()` writes them with the date left to the column default. A member's go with the member,
  through XenForo's clean-up and Seeder's sweep of `xf_ip` by member. A post's go with the post:
  XenForo leaves them for its pruning, so Seeder deletes them itself. A seeded post points at its
  row through `ip_id`, as a posted one does.
- **The admin log** — entries written through the `AdminLog` entity as data; XenForo itself
  writes that table with a query. An entry for the control panel's front page has an empty path,
  which the entity refuses, so it is saved with one and then updated. XenForo prunes the table by
  age.
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
- **Code event listener** — `hampel_admin_api_redaction`, another add-on's event, answered with
  this add-on's secrets: none. Never fired on a forum without that add-on.
- **Code event listener** — `addon_pre_uninstall`, hinted to this add-on, refuses the uninstall
  while a batch is recorded. XenForo marks the add-on as processing before it fires the event, so
  the listener takes that mark back before it throws.
- **Phrase** — one, the uninstall refusal, since the control panel shows it to an administrator.
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
- **Some jobs are queued only when the request ends** (`\XF::runOnce()`), after a naive drain has
  finished. The drain releases them first; without that a conversation left its search re-index
  queued behind every run.
- **XenForo quietly blanks a choice a field does not offer.** The member factory checks choices
  itself for that reason.
- **An upgrade held past its end is expired by XenForo's cron**, through the downgrade service,
  which alerts the member and fires other add-ons' hooks. The factory refuses to create one.
- **Hooks on upgrades are other add-ons' code.** With `hooks=1` their jobs are queued and the drain
  runs them; what they do is theirs. The suite loads no other add-on, so it proves only that the
  rows come out the same by either route.
- **Some add-ons finish a delete at the end of the request.** The gallery recounts an owner's
  quota and rebuilds an album's thumbnail then - by which time a clean has deleted the owner and
  the album, and XenForo logs a server error for each. The cleaner ends each removal as its own
  request would (`\XF::triggerRunOnce()`). Found on 2026-10-11 as thirteen error log rows.
- **XenForo rolls back every open transaction when it logs a server error.** Anything inside a
  removal that logs one undoes the removal, and code that carries on then writes outside a
  transaction. The cleaner checks its transaction is still open before deleting a record, and
  runs deferred work only after the commit. Found on 2026-10-11, when `seeder:validate` left one
  member with no record of it.
- **An emptied album says it has no thumbnail while the file is still there**, so the gallery's own
  delete leaves it. The album factory deletes the file itself.
- **An album's thumbnail is dated by the real clock**, whatever the dates of its items. It is a
  cache-busting stamp, not a date anyone is shown.
- **A member is saved before their extras are written, and recorded after.** A failure between the
  two would leave a member nothing could remove, so the factory removes them itself. Found on
  2026-10-11 as one stranded member.
- **The passkey table's `aaguid` column is `NOT NULL`, 32 characters**, where the entity calls it
  nullable.
- **XenForo 2.3.0 keeps an entity's cascaded saves after it is saved**; later releases clear them.
  A thread made by the creator service then re-saved its first post on every later save, and a
  reaction to that post threw. The factory clears the link after each save, by reflection. Found
  on 2026-10-11 by running at the declared floor.
- **XenForo 2.3.0's member clean-up does not remove passkeys.** The member factory does.
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

Covers: every command class the add-on ships is one XenForo can load, against the full list, so
a command added without a test fails; the guard opens only on a literal `true`;
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

For the cleaner: a removal rolled back from under it keeps its record, and work put off to the end
of the request runs after each removal is committed.

For tags: new ones are made and recorded, existing ones used and left, names tidied, dates taken
from the thread, and a name the forum would not accept refused. For the cleaner: a removal that
loses a deadlock is tried again, and after three tries its record is kept.

For conversations: the conversation, its first message and each participant's rows carry the date
asked for; replies alternate authors among the participants and each is later than the last, never
in the same second; nobody is alerted, mailed or has an address logged; cleaning restores the
counts and the members' unread totals; removing one reply leaves the conversation pointing at the
one before.

For fields and upgrades: a field has its phrases and choices and is known to the forum at once;
every kind of field takes a made-up value; an unknown field, an unknown choice and a rejected
value are refused; values go with the member or the field. An upgrade is not for sale; one held
ends in the future and puts the member in its groups, by either route; one run out has the dates
given, by either route, and leaves no group change and no alert; both seeders clean back to their
starting counts.

For Resource Manager and Media Gallery: only what happens on a forum without them - each factory
and seeder says so and creates nothing, a refusal is not logged as a server error, and the sample
leaves both out. **Creating and removing their content is not covered by the suite**, which loads
this add-on alone; see *Needs a human*.

For parameters: one that no seeder asks for exits 1, is named in the JSON and on standard error,
and leaves what was understood made and recorded; one that only a called seeder asks for is not
reported; one run's parameters do not excuse the next run's; a run that fails reports the failure
and no ignored parameters.

For account access: each way in writes the rows XenForo keeps for it, dated in the past, and sends
nothing; a member active without content has the login, alert and follows and no content; a named
member can be the other party; an unknown provider is refused before anyone is created; a member
whose extras fail is removed, not left unrecorded; the seeder cleans back to its starting counts
across eighteen tables. A moderated member is in the approval queue at their registration date, a
date given to one is the date in the queue, a member in any other state is not in it, and
removing the member takes the entry with them. A member without a password has XenForo's
no-password scheme and cannot log in with one while an ordinary member can; a member without
authentication has no such record, has every other row, and can still be removed. A banned member
has XenForo's ban record, dated after they joined, and the flag it sets; one moderator bans
however many are made, or the one named; a permanent ban takes a moderated member out of the
queue and a temporary one does not; with a banned user group set the member is in it, and
cleaning takes the ban, the group change and the membership; staff are refused as banned or
bouncing before anyone is created, and so is a ban that has already run out.

For reactions and states: a reaction updates the post's summary and its author's score, is dated
after the reactor joined, appears in the feed with its own date and alerts nobody; reactors are
never the author and never repeat; a moderated thread and reply are in the approval queue and not
counted; a deleted thread was posted, replied to, reacted to and then deleted, its log dated after
its last reply, its reactions no longer counted, and no moderator log written; attachments carry
their post's date; a featured thread has XenForo's featured content record at the thread's date,
writes no moderator log, and loses the record when cleaned; all of it cleans back to the
starting counts.

For forum types and search forums: a forum of each type has that type's settings and its threads
are of its type; a type the forum does not have is refused; an attribute the node has no column
for is set on the forum's own row; a search forum lists the visible threads of the forums it
searches and no others, with results dated when it was made; cleaning one leaves no node, no
row, no results and no member's own results; the seeders give each of several forums threads in
turn.

For polls and sticky threads: a thread with a poll is a poll thread with its choices and no
votes; votes come from the members given, one each, dated after the thread, and the poll's totals
and cached results agree with the rows; a forum takes the thread types it is told to and a new
one takes none; a poll is refused in a forum that does not take them and with fewer than two
choices; cleaning leaves no poll, choice or vote; the seeders make the sticky threads and polls
asked for, and give polls only to forums that take them.

For widgets: a widget has its title phrase, its position and a compiled file, and no template;
an HTML widget has a template of its own holding its markup; a widget can be of another
definition, in several positions or none; a definition or position the forum does not have is
refused; cleaning leaves no row, phrase, template or compiled file; the seeder puts its widgets
in the position named. These tests stand in for one method of XenForo's style repository:
compiling a template empties the cached CSS with a `TRUNCATE`, which would commit the test's
transaction.

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

For administrators and the admin log: an administrator has the record behind the flag, no
permissions unless named, and one the forum lacks is refused; cleaning leaves no record,
permission entry or member; an entry has an administrator, a route path, masked input, a packed
address and a date after its author registered; no entry is dated inside the quiet period; a log
has repeats alike in administrator, address, path and input, and markup in what was posted; the
empty path is written; entries are recorded under a name a later process can load; the seeder
says it makes administrators and names the add-ons that will report.

For permissions: a group writes flags and limits as the control panel does, and a permission the
forum lacks or a value it would not take is refused; a member given permissions holds them, stays
in the registered group and has a permission combination of their own; members given the same
permissions share one group and `make()` creates none; cleaning removes members, group and
entries, and after XenForo's job every permission table is back; the `User` seeder makes the
members it is asked for and refuses `permitted` without `permissions`. The tests found that only
deleting a group marks the styles changed, not creating one.

For the moderator log: every entry's action is one XenForo has wording for, with each
parameter that wording names; dated inside the content's life, after its moderator joined and
outside the quiet period; the content's author, title and discussion as XenForo's handlers record
them; from the documentation ranges; every row recorded; threads, posts and members all covered;
a thread's history agrees with whether it is locked, stuck and visible now; no moderator log
entity is saved, checked by saving through the entity, which fails it; cleaning removes the
batch's rows and not a real moderator's written meanwhile; a table with a column no row could fill
is refused; the seeder makes its moderators, members and content.

For a member's history: changes by the member and by an administrator, each inside the member's
span, each field's old value the one before it left, the member ending with the last values, and
the dates spread rather than all at the moment of the run; a history of names from made-up names
to the member's own, each change following the last, the member not renamed and their last-change
dates set as XenForo sets them; a request awaiting approval in the queue and dated after the
changes, a rejected one with a moderator and a reason; a rejection by the spam check with its
reason at registration, and by a moderator after both had joined, logged in the change log as
that moderator; out of the approval queue either way; cleaning takes every row; the seeder makes
what it is asked for and nothing is refused at zero.

For IP logs: a member has one registration row at their registration date from their first
address, the logins asked for with the last at their last activity, administrator logins only
for an administrator (whichever order the two states are given in), account changes from XenForo's
own action names, every row inside the member's span and from their own addresses, all from the
documentation ranges; the run's JSON lists the addresses, the most used first; members share
addresses and some have several, checked with an address source that never repeats, so sharing by
chance cannot pass it; a thread's first post and each reply have a row dated with them and point
at it, a post not asked for has none, and nothing is logged against a thread; cleaning takes every
row, including those for posts by a member the batch did not make; both seeders write them only
when asked.

For node permissions: a group's entries on a forum are written as the control panel writes them,
flags and limits, and none globally; a permission no node can have, a global flag value, an
unknown permission and an unsaved node are each refused; members of groups with different node
permissions hold them and are in different combinations, a member in no group keeps the forum's
own once XenForo's rebuild has run; cleaning removes the entries with the group; the Thread seeder
puts every author in a group and gives as many combinations as groups, an author still sees their
own thread, and through `Default` each group is denied a different forum.

For user agents: the robot `robotFor()` names agrees with XenForo's own check on crawlers,
browsers and a command-line client; crawlers are all robots to the forum, Google's commonest; one
can be asked for by robot, and an unknown robot is refused; a browser is never a robot.

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

For the secrets declaration: it names this add-on only, declares no options because there are
none, claims the one `config.php` block, and fails when a setting is added to that block.

For uninstalling: the listener is registered for this add-on only; XenForo's own `preUninstall()`
and the control panel's uninstall action are both refused with the phrased reason, and both leave
the add-on active, not processing, with its listeners back in the cache - each fails if the reset
is taken out; and allows it once nothing is recorded;
`seeder:forget` discards records and leaves content.

`FrameworkHalfTest` fails if anything in the framework half references the add-on half.

`ErrorLogPrefixTest` fails if a call that writes to the server error log does not begin its
message with `Seeder: `. That test reads the source; the tests of a seeder that throws and of a
check that throws also assert the prefix on what was logged.

**Every test in a transaction stands in for XenForo's member statistics repository**, as well as
faking the data registry. Saving a new member empties the cached staff list, a row XenForo's own
cron rewrites every ten minutes, and a test holding it loses that race with error 1020. One test
asserts that creating a member leaves the table as it was.

## Needs a human

- **After building a release, unzip it and confirm `vendor/fakerphp/` is inside** and that
  `CLAUDE.md`, `TESTING.md` and `PROPOSAL-seeder.md` are not.
- **A disposable forum, before a release.** Install the built zip on a fresh XenForo with
  development off, switch seeding on, and run in order: `seeder:validate`, `seeder:run`, an
  uninstall (refused), `seeder:clean`, an uninstall (succeeds, and the record table is gone). Run
  `seeder:validate` once before installing Media Gallery and Resource Manager and once after, so
  both a forum without them and one with them are covered. Last run on 2026-10-15 from the 1.0.0b4 zip,
  against XenForo, Media Gallery and Resource Manager 2.3.13 on PHP 8.3 and 2.3.0 on PHP 8.1: the
  round trip, and `User`, `Thread` and `ModeratorLog` with everything new in that release - IP
  logs for members and posts from the documentation ranges, change logs, username changes in each
  state, rejections by the spam check and by a moderator, and moderator log entries none of them
  newer than the quiet period - no row, mail, alert or server error left, and the record table
  gone at the uninstall. Both
  runs had both add-ons installed throughout; a forum without them was last covered on 2026-10-12
  from the 1.0.0b1 zip. Every seeder was last run there on 2026-10-11.
- **The upgrade, before a release.** On the same kind of forum, install the previous build's zip,
  seed a batch, then upgrade to the new zip by path. Check the output says `Importing add-on data`
  and not `All data imported`, that the batch is still listed, that no file is on disk that the
  new zip does not hold, and that the uninstall is still refused. Last run on 2026-10-15 to
  1.0.0b4, from the published 1.0.0b3 on XenForo 2.3.13 with PHP 8.3 and on 2.3.0 Patch 2 with
  PHP 8.1.
- **An uninstall from the admin control panel has not been clicked in a browser.** The suite
  dispatches the control panel's uninstall action and checks the refusal and the state it leaves,
  so what is unseen is only how the error is displayed.
- **`seeder:validate` on a forum with Resource Manager and Media Gallery installed** is the only
  check of resources and media. Besides its table counts, count the files under `data/xfmg`,
  `data/attachments` and `internal_data/attachments` before and after, and check no server error
  was logged: validate compares tables, not files. Last done on 2026-10-12 with both at 2.3.5,
  when ratings and a featured resource joined the sample. For ratings, also read back each
  resource's rating count, sum and review count against its ratings, and check no alert was
  written. Stars on the rendered pages can only be seen on a forum without an add-on that
  hides them.
- **Resource discussion threads and media comments**, which the suite cannot reach either. Seed
  `Resource` with `discussions=1` and `Media` with `comments=3` as named batches and read back:
  each resource's `discussion_thread_id`, that thread's type (`resource`) and that its dates are
  the resource's; no row in `xf_thread_watch` for it; each item's comment count, last comment
  date and its category's count; and the news feed row of each comment dated as the comment is.
  Fetch a discussion thread, its resource and a media item as a guest. Last done on 2026-10-13
  with both add-ons at 2.3.5: three resources, six comments, every page 200 on its own template,
  no alert, moderator log or server error, cleaned with no row left.
- **"No alert after seeding" holds for the seed, not for the forum afterwards.** XenForo's
  trophy cron alerts a member who reaches a trophy, and a seeded member's first post can do that
  once the job queue has run. That alert is XenForo's, goes to the seeded member and is removed
  with them. Count alerts before the queue runs, or leave out the `trophy` content type.
- **`seeder:validate` on a real forum, with the queue live**, after any change to a factory or to
  cleaning. It is the only check that runs with every add-on loaded and jobs really running, and
  it is what found the permission-cache leak.
- **That a seeded error log really does stay out of a chat channel.** Only the dates were checked,
  against the rule the poller on the development forum uses; the poller itself was not run.
- **`AlbumFactory::in()`**, which no seeder uses. Run by hand on 2026-10-12 with Media Gallery
  2.3.5: an album in a new album category and one in a given category, a media item in the
  second, each page 200 to a guest once the run's jobs had drained, cleaned with no row or file
  left.
- **A seeded administrator in the admin control panel.** The record, the flag and the permission
  entries were read back; nobody has signed in as one, or looked at a seeded log on XenForo's
  admin log page.
- **Permissions from another add-on, seen on a page.** On the development forum members were
  given `Hampel/HideAdverts`' permission and that add-on's own check read it, with every add-on
  loaded; nobody has signed in as one and looked at a page.
- **A seeded moderator log on XenForo's moderator log page.** Each entry was read back through
  XenForo's entity and its wording rendered. On 2026-10-15, in a sandbox on XenForo 2.3.13 from
  the 1.0.0b4 zip, the log's list (paged) and two single entries were fetched over HTTP as a super
  administrator: 200, rows present, no template or PHP error. Nobody has looked at them in a
  browser, or at a thread's moderator actions.
- **A seeded member's history in the admin control panel.** Read back on the development forum,
  and on 2026-10-15 in the same sandbox a member's change log tab and the username change log
  were fetched over HTTP: 200, rows present, no error. Nobody has looked at them or at the
  approval queue in a browser, or approved or rejected the seeded request there.
- **Seeded node permissions in the admin control panel.** Each member's view of each seeded
  thread was checked through XenForo's own `canView()` on the development forum; nobody has looked
  at a forum's permissions page or browsed as such a member.
- **A seeded IP log in the admin control panel.** Read back on the development forum, and on
  2026-10-15 in the same sandbox a member's IP list (on one address, and on three) and the member
  list for an address two members share were fetched over HTTP: 200, rows present, no error. An
  add-on that reads the IP log showed every seeded row. Nobody has looked at those pages in a
  browser, or at a post's IP.
- **`crawlerUserAgent()` with an add-on that extends the robot list.** Its agents were checked
  against XenForo's own list only; which robot each is on a forum with a longer list was not.
- **`seeder:run UserUpgrade -p hooks=1` on a forum with an add-on that hooks upgrades.** Not run:
  on the development forum the one such add-on has no API key, and its jobs would only fail.
- **The declared floor, before a release that changes a factory.** A disposable forum at XenForo
  2.3.0 on PHP 8.1, with the 2.3.0 Media Gallery and Resource Manager: lint the zip's PHP there,
  then the same sequence as above. Last run on 2026-10-15 at 2.3.0 Patch 2 and PHP 8.1.34, from the
  1.0.0b4 zip. Its first run found two defects that 2.3.12 and 2.3.13 do not show; the run from a zip built with
  both fixed was clean.
- The commands have run on XenForo 2.3 only.
