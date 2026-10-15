# Test data generator for XenForo

Fills a development forum with test content — and removes it again. It is for add-on developers
who need a forum with members and dated content to test against, and it is not for a live forum.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

[Resource page](https://xenforo.com/community/resources/seeder-test-data-generator.10896/) ·
[support thread](https://xenforo.com/community/threads/seeder-test-data-generator.240676/)

**This is a beta.** It creates members in the states a forum holds them in - valid, awaiting
approval, unconfirmed, rejected, banned, bouncing, administrators, holding permissions, with a
history of changes and of names - forums, threads with replies, tags,
reactions, thread prefixes, image attachments, featured and sticky threads, polls with votes, content awaiting
approval or soft-deleted, forums of each type, search forums, conversations, custom user fields with members' values, user upgrades held and run out, warning
definitions, warnings, API keys, widgets, who is online, server error log entries, admin log
entries, moderator log entries and IP logs - and, where the forum has them, XenForo Resource Manager resources with ratings, reviews and discussion threads and
XenForo Media Gallery media items with comments - and removes them again. Profile posts are not
built yet.

Two things to know while it is one:

- **The classes your own seeders and factories build on may change before 1.0.** Their
  namespace will not. See *Your own seeders and factories*.
- **It has been run beside a few dozen add-ons, not beside yours.** See *Limits* for what another
  add-on can do that this one cannot stop.

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
| `errorLogQuietHours` | 2 | how long before now no seeded error log or admin log entry may be dated; never less than 2 |

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

**Anything marked *since 1.0.0b2* or later is not in the release before that one.** A
run given a parameter the installed version does not have makes everything else, names the parameter as
ignored and exits 1.

| seeder | creates | parameters |
|---|---|---|
| `Default` | members, forums, and threads by those members in those forums | `users` (20), `forums` (2), `forum_types` *(since 1.0.0b2)* (0; 1 makes the forums of each type in turn: discussion, article, question, suggestion), and those of `Thread` |
| `User` | members | `users` (20), `access` (0; more members, each with another way into their account), `active` (0; more members who have used the forum without posting), `moderated` (0; more members awaiting approval), `passwordless` (0; more members who registered through a connected account and have no password), `no_auth` (0; more members with no authentication record at all), `banned` (0; more members who are banned, by turns permanently and for thirty days, and one moderator who banned them), `bouncing` (0; more members whose mail bounced), `admins` *(since 1.0.0b3)* (0; more members who are administrators, with no administrator permissions; see *Members*), `permitted` *(since 1.0.0b3)* (0; more members who hold the permissions in `permissions`, through a user group made to grant them), `permissions` (which: `general.bypassFloodCheck`, or `conversation.maxRecipients:50` where the value is not allow; several separated by commas), `changes` *(since 1.0.0b4)* (0; more members who have changed their account, some of it changed by an administrator), `renamed` *(since 1.0.0b4)* (0; more members who have changed their name, some with a later request rejected or awaiting approval), `rejected` *(since 1.0.0b4)* (0; more members whose registration was rejected, by turns by the spam check and by a moderator), `ips` *(since 1.0.0b4)* (0; 1 gives every member made an IP log; see *Members*) |
| `Thread` | threads with replies, and a prefix | `threads` (12), `replies` (5, the most per thread), `authors` (5), `forum` (an existing forum's node id; a new forum if omitted), `forum_type` *(since 1.0.0b2)* (`discussion`; the type of the forum that is made), `prefix` (1, or 0 for none), `reactions` (0; the most per post), `tags` (0; how many different tags to file threads under), `featured` (0; how many of the threads are featured), `sticky` *(since 1.0.0b2)* (0; how many of the threads are sticky), `polls` *(since 1.0.0b2)* (0; how many of the threads have a poll, with votes from the authors), `states` (0; 1 adds threads and replies awaiting approval or soft-deleted), `permission_groups` *(since 1.0.0b5)* (0; user groups each denying its members others' threads in one forum, with the authors spread across them; see *Members*), `ips` *(since 1.0.0b4)* (0; 1 logs the address of every thread and reply, and gives each author made an IP log) |
| `SearchForum` *(since 1.0.0b2)* | search forums, each listing one forum's threads | `search_forums` (2), `forum` (an existing forum's node id; if omitted a new forum is made and given threads by `Thread`, whose parameters apply) |
| `Conversation` | conversations between members, with replies | `conversations` (5), `messages` (10; in each, the first included), `participants` (3; the most in one, the starter included), `members` (6; created for them to be between) |
| `UserField` | custom user fields of each kind, and members with values in them | `fields` (4; with 0 the forum's own are used), `members` (20), `filled` (80; the percentage of fields each member has a value in) |
| `UserUpgrade` | user upgrades, members holding one, and upgrades that have run out | `upgrades` (2; with 0 the forum's own are used), `active` (5), `expired` (5), `members` (10), `hooks` (0; see *User upgrades*) |
| `Resource` | Resource Manager resources, in categories | `resources` (6), `categories` (1), `category` (an existing resource category's id, instead of creating any), `authors` (3), `reviews` (0; ratings with a written review, for each resource), `ratings` (0; ratings with nothing written, for each resource), `featured` (0; how many of the resources are featured), `discussions` *(since 1.0.0b2)* (0; 1 gives each resource a discussion thread, in a forum made for them), `forum` *(since 1.0.0b2)* (an existing forum's node id for those threads) |
| `Media` | Media Gallery items: in a category, in an album, and where a guest cannot view them | `media` (6; in categories), `categories` (1), `category` (an existing media category's id), `albums` (1; each with two or three items), `hidden` (1; items in a private album), `authors` (3), `comments` *(since 1.0.0b2)* (0; how many comments each item in a category gets) |
| `Warning` | warning definitions, and warnings given to members | `warnings` (10), `definitions` (0 — how many to create; with 0 the forum's own are used), `members` (5) |
| `ApiKey` | API keys of each kind, with a new member for each member key | `user_keys` (2), `guest_keys` (1), `super_keys` (0), `inactive` (1; one more member key, switched off and limited to two read scopes) |
| `Widget` *(since 1.0.0b2)* | widgets with a title, in one widget position: forum statistics, members online and new posts by turns | `widgets` (3), `position` (`forum_list_sidebar`; the widget position they are shown in), `html` (0; how many more are HTML widgets with a sentence of text; see *Widgets*) |
| `Online` | who is online: members, guests and robots | `members` (10), `guests` (100), `robots` (200), `networks` (20; how many networks each kind comes from, 1 to 128), `top` (0; the percentage in the largest network), `robot_kinds` (40; how many different robots), `actions` (11; how many different controller and action pairs, up to 45), `robot_action` (none; one action most robots are on, as `Search::Results`), `robot_focus` (90; the percentage of robots on it), `ipv6` (5; percent), `stale` (10; percent seen too long ago to count as online) |
| `ErrorLog` | a server error log with history: recurring faults, from page views, commands and jobs | `errors` (300), `members` (5; created for about one page-view entry in three to name) |
| `ModeratorLog` *(since 1.0.0b4)* | a moderator log with history: a few moderators acting on members' threads, replies and accounts, and the members and threads they act on | `entries` (60, about), `moderators` (2), `members` (4), `threads` (6), `replies` (3, the most per thread) |
| `AdminLog` *(since 1.0.0b3)* | an admin log with history: a few administrators, the ordinary actions repeated and unusual ones once | `entries` (200), `admins` (3; administrators created for the entries to be by) |
| `Sample` | a little of everything above, bar who is online, the error log, the admin log, HTML widgets and members holding permissions, with resources and media only where the forum has those add-ons; what `seeder:validate` seeds and removes | |

**The `Thread` seeder varies each first post**, cycling through plain prose, a long post, a quote, a
list, an image, links, an attachment, and everything at once with a prefix — so whatever renders a
first post meets the range of BB code.

```bash
php cmd.php seeder:run Thread -p threads=12 -p forum=2
```

### Members

`Hampel\Seeder\Factory\UserFactory` builds a member with the forum's own registration defaults,
an address at a reserved domain, and the run's shared password.

**A seeded member's preferences are the forum's registration defaults**, as a new member's are:
*Default registration values* under *Options > User registration* in the admin control panel
(`registrationDefaults`). XenForo ships `receive_admin_email` off there, so no seeded member
receives email from administrators unless asked, and code that selects members by it - a mailing
list, a digest - finds none until one is set with `state(['Option.receive_admin_email' => true])`.
Look there first when seeded members are missing from something filtered by a preference.

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
| banned | `->banned()` permanently, `->banned($until)` until a timestamp in the future, `->banned($until, $moderator)` to say who by |
| mail to them bounced | `->bouncing()` |
| no email address at all | `->withoutEmail()` |
| no password, as after registering through a connected account | `->withoutPassword()`, with `->withConnectedAccount()` for the whole of such a registration |
| no authentication record at all, as a part-finished import can leave | `->withoutAuthentication()` |
| an administrator *(since 1.0.0b3)* | `->administrator()` with no administrator permissions, `->administrator('user', 'option')` with those |
| a permission *(since 1.0.0b3)* | `->withPermissions(['general' => ['bypassFloodCheck' => 'allow']])`, `'deny'`, or a number for a limit such as `['conversation' => ['maxRecipients' => 50]]` |
| another way into the account | `->withConnectedAccount('google')`, `->withTwoStep()`, `->withPasskey()`, `->withRememberKey()`, `->withConfirmationPending()` |
| registration rejected *(since 1.0.0b4)* | `->rejected()` by XenForo's spam check, `->rejected($moderator)` by a moderator from the approval queue |
| a history of changes to the account *(since 1.0.0b4)* | `->withChanges()`, `->withChanges(10, $admin)` |
| other names before this one *(since 1.0.0b4)* | `->renamed()`, `->renamed(3)`; with `->withUsernameRequest()` awaiting approval or `->withUsernameRequest('rejected')` |
| an IP log *(since 1.0.0b4)* | `->withIpLog()`, or `->withIpLog(40)` for that many logins |
| a member who has used the forum without posting | `->activeWithoutContent()`, or its parts: `->withLogin()`, `->withAlert()`, `->following()`, `->followedBy()` |
| values in custom user fields | `->withFields(['field_id' => 'value'])`, or `->withFieldValues()` for a made-up value in every field the forum has |

`is_moderator` sets the flag only: it creates no moderator record and grants no permissions.
`is_admin` as a column does the same; `administrator()` is the one that makes an administrator.

**A seeded administrator is a working login to the admin control panel.** `administrator()` writes
the administrator record XenForo keeps, so the member is listed among the forum's administrators
and has the run's password like every other seeded member. They are not a super administrator, and
with no permissions named they reach the control panel's front page and nothing behind it.
Cleaning the batch removes the record with the member.

**A permission is held through a user group made to grant it.** `withPermissions()` puts the member
in an extra group, so they stay in the registered group, and every member of a run given the same
permissions shares one group. `UserGroupFactory` makes such a group on its own:
`UserGroupFactory::new()->granting([...])->create()`. A permission the forum does not have is
refused. To use a group the forum already has, give its id in `secondary_group_ids`.

**A group can have permissions on one forum, too** *(since 1.0.0b5)*:
`UserGroupFactory::new()->grantingOn($forum, ['forum' => ['viewOthers' => 'deny']])`, as the
control panel's node permissions write them - `'content_allow'`, `'reset'` or `'deny'` for a flag,
a number for a limit. Only what XenForo lets a node have is taken. Members of groups with different
node permissions are in different permission combinations, and per-member filtering of content is
untestable without several: `permission_groups` on the `Thread` and `Default` seeders makes that
many groups, each denying its members other members' threads in one of the run's forums, and
spreads the authors across them. Others' threads rather than the forum, so an author is never
denied a forum they posted in.

- **Cleaning a group marks every style as changed and empties the forum's cached CSS.** XenForo
  does that whenever a user group is deleted. Groups are therefore left out of the `Sample` seeder.
- **XenForo then removes every permission combination no member has any more**, the forum's own
  as well as the run's. That is its own clean-up and runs whenever a group is deleted.

**`banned()` writes the ban record, and XenForo sets the member's banned flag from it.** Setting
`is_banned` with `state()` gives a banned member with no record of who banned them or until when,
which no forum holds. Who banned them is one new moderator for every member the factory makes,
unless one is given. Where the forum has a banned user group set, the member is in it.

- **A permanent ban of a moderated member makes them valid.** XenForo does that so they leave the
  approval queue. `->moderated()->banned()` is a valid, banned member; give an end date to keep
  them moderated.
- **A moderator or administrator cannot be banned**, and one cannot be `bouncing()`: XenForo
  refuses the first and never does the second, so the factory refuses both.
- **`bouncing()` is the member's state alone**, as XenForo's own bounce handling sets it. No
  bounce is logged.

**The ways in are rows, not working credentials.** A connected account has a made-up id at the
provider and no tokens; a passkey's key is not a key and can sign nothing; nobody holds the
authenticator secret or the cookie that matches a remember key. They are for code that asks what a
member has. `withConfirmationPending()` writes the confirmation row a registration leaves and
sends no email; `unconfirmed()` alone sets the state and writes no row.

**`activeWithoutContent()` is a member who has logged in, has an unread alert, follows someone and
is followed**, and has never posted. The login is an IP log row and a who-is-online row from the
member's own address, from the ranges set aside for documentation *(since 1.0.0b4; before,
`198.18.0.0/15`)*. The other party to the alert and both follows is
one more member, created with them; `following($member)`, `followedBy($member)` and
`withAlert($member)` take an existing one. A run's JSON lists what each member was given under
`has`.

**An IP log is what XenForo writes as a member uses the forum** *(since 1.0.0b4)*: a row for
registering, logins from then until their last activity - some through a "stay logged in" cookie -
logins to the admin control panel for an administrator, and a few changes to the account, such as
a new password or email address. Each member is given one to three addresses for the run and uses
the first most. About one address in four is one another member of the run already has, so an
address can lead to several members. Addresses come from the ranges set aside for documentation
(`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` and `2001:db8::/32`), which no visitor comes
from. A run's JSON lists each member's addresses under `addresses`, the one they use most first.

**A member's history is written as XenForo writes it** *(since 1.0.0b4)*. `withChanges()` saves
each change through the entities as the member, or as an administrator, with XenForo's clock at
that moment, so XenForo's own change log records it: time zone, location, website, signature,
email address, privacy and mail preferences by the member; custom title, email address, location
and a custom user field by the administrator. The member ends with the values the last changes
left. `renamed()` records username changes from made-up earlier names to the member's name - some
self-requested and approved by a moderator, some by an administrator - and does not rename the
member, so nothing of theirs has to be rewritten. A rejected registration has XenForo's rejection
record: the spam check's own reason at registration, or a moderator's some time after. **A
moderator's rejection writes no moderator log entry here**, where XenForo's approval queue would;
Seeder writes no moderator log at all.

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
| `ForumFactory` | a forum; the entity returned is its node | `type($id)`, `article()`, `question()`, `suggestion()`, `allowing(...$threadTypes)`, all *(since 1.0.0b2)* |
| `SearchForumFactory` *(since 1.0.0b2)* | a search forum; the entity returned is its node | `searching($forums)` |
| `ThreadFactory` | a thread and its first post | `locked()`, `sticky()`, `featured()`, `moderated()`, `deleted($reason, $by)`, `long()`, `withQuote()`, `withList()`, `withImage()`, `withLinks()`, `withFormatting()`, `withAttachment(n)`, `withPrefix($prefix)`, `withTags([...])`, `withPoll($choices, $votes)` *(since 1.0.0b2)*, `withIp()` *(since 1.0.0b4)* |
| `PostFactory` | a reply | `moderated()`, `deleted($reason, $by)`, `withQuote()`, `withAttachment(n)`, `withIp()` *(since 1.0.0b4)* |
| `ReactionFactory` | a member's reaction, by default to a post | `for($post)` or `for($thread)`, `reaction($id)` |
| `PrefixFactory` | a thread prefix; `node_ids` lists the forums that offer it | |

The `with...()` states add up: `->withQuote()->withList()` gives a first post with both. Set
`message` to supply the BB code yourself, and `post_date` to fix the date.

**Threads and replies are written through XenForo's own thread creator and replier**, so a message
is prepared as a posted one is. Their notifications are never sent, nobody is alerted, and no
moderator log is written. Replies are dated after the post they follow and never before their
author joined. Attachments are real PNG images. Links and images point at `example.com`.

**`withIp()` logs the address a post came from**, as XenForo does for one made in a browser: an IP
log row for the post, dated with it, from one of its author's addresses for the run, with the post
pointing at the row. XenForo logs a new thread's address against its first post. Without it a
seeded post has no IP log row, as one XenForo's own automated posting makes has none. Cleaning
removes the rows with the posts, which XenForo itself leaves for its pruning.

**Forum types, since 1.0.0b2.** `article()`, `question()` and `suggestion()` make a forum of that type, and
`type($id)` one of any type the forum has. It gets that type's settings as XenForo first offers
them, and a thread made in it is of the type such a forum starts: an article, a question, a
suggestion. Nothing more is made of the type - no question has a solution and no suggestion a
vote. An attribute that is a column of the forum's own row, such as `allow_posting`, can be
given to `create()` beside the node's.

**Search forums, since 1.0.0b2.** `SearchForumFactory` makes a search forum listing every visible thread, and
`searching($forums)` narrows it to the forums named. Its results are worked out as it is made,
so make it after the threads it should list; from then on XenForo refreshes them as it does any
search forum's, when a page view finds them more than ten minutes old.

```php
$questions = ForumFactory::new()->question()->create();
ThreadFactory::new()->count(10)->for($questions)->create();

SearchForumFactory::new()->searching($questions)->create(['sort_order' => 'title', 'sort_direction' => 'asc']);
```

**Polls, since 1.0.0b2.** `withPoll(4, 6)` makes the thread a poll thread: a question, four
choices, one vote a member, results anyone may see. Up to six of the members given to
`recycle()` vote, and with nobody recycled the poll has no votes. A vote is written as a row and
the poll's totals rebuilt, since XenForo's own voting queues a webhook.

**A forum has to take poll threads, and a new forum does not.** XenForo's *Allowed thread types*
setting starts empty, and a seeded forum is the same. A forum that `ThreadFactory` makes for a
poll thread takes them; one you give it must, or the poll is refused:

```php
$forum = ForumFactory::new()->allowing('poll')->create();
ThreadFactory::new()->for($forum)->recycle($members)->withPoll(4, 6)->create();
```

The `Thread` and `Default` seeders do this for the discussion forums they make when `polls` is
set. A forum that does not take polls gets none: an article, question or suggestion forum, or an
existing forum named with `forum` whose settings leave polls out.

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

**The hooks route has tests of what it writes and has not been run beside an add-on that hooks
upgrades.** Treat `hooks=1` and `withHooks()` as untried on a real forum.

XenForo does not delete payment profiles; a seeded one is removed by deleting its row, and is left
and reported where anything still refers to it.

### Resources and media

**These need XenForo Resource Manager and XenForo Media Gallery.** Seeder installs and runs without
either: the factories name those add-ons' classes only as strings, and a factory or seeder used on
a forum that lacks the add-on says so and creates nothing.

```php
ResourceFactory::new()->for($category)->recycle($authors)->count(6)->create();
ResourceFactory::new()->featured()->create();
ResourceRatingFactory::new()->for($resource)->recycle($members)->count(3)->create();

MediaFactory::new()->recycle($authors)->count(6)->create();     // in a media category
MediaFactory::new()->inAlbum($album)->create();
MediaFactory::new()->hidden()->create();                        // a guest cannot view it
```

| factory | creates | states |
|---|---|---|
| `ResourceCategoryFactory` | a resource category, with no discussion forum | `withDiscussionForum($forum)` *(since 1.0.0b2)* |
| `ResourceFactory` | a resource with its description and first version; visible, and with no file | `moderated()`, `featured()` |
| `ResourceRatingFactory` | a member's rating of a resource, with a written review | `for($resource)`, `withoutReview()`, `rating($stars)`, `anonymous()` |
| `MediaCategoryFactory` | a gallery category that holds media items | `forAlbums()` |
| `AlbumFactory` | a member's album that anyone may view | `private()`, `membersOnly()`, `in($category)` |
| `MediaFactory` | a media item: a real PNG, with the thumbnail the gallery makes of it | `inAlbum($album)`, `hidden()` |
| `MediaCommentFactory` *(since 1.0.0b2)* | a member's comment on a media item | `for($media)`, `moderated()` |

**A rating counts, and tells nobody.** It is of the resource's current version and by a member
drawn from those given to `recycle()`, never the author and never the same member twice; if nobody
is left, a new member is made. The resource's rating, its rating count and its review count all
move, and a review is in the news feed. It is written through the entity, since the Resource
Manager's rating service alerts the author. `featured()` writes XenForo's featured content record
through its entity for the same kind of reason: the service writes the moderator log.

**A media item is a real upload.** A 480 by 270 PNG goes through the gallery's own attachment
handler, so the item has an attachment, a file and a thumbnail, and its full image is served. All
are removed with the batch.

**`hidden()` puts the item in a new album only its owner can view.** A run's JSON gives each item's
`category_id`, `album_id` and `album_view_privacy`, each album's `view_privacy`, and each category.
Who can view an item in a category is the forum's permissions, which Seeder does not set.

**Nobody is told.** Resources go through Resource Manager's creator and albums through the
gallery's, with notifications never sent; a media item and a comment are saved through their
entities.

**Discussion threads, since 1.0.0b2.** A resource category has no discussion forum unless
`withDiscussionForum()` gives it one - a new forum, or the one passed. Each resource posted in
the category then has its discussion thread there: the resource's title, the first post the
Resource Manager writes, and the thread type that shows the resource above the replies. The
thread is dated as the resource is, recorded on its own and removed with the batch.

```php
$category = ResourceCategoryFactory::new()->withDiscussionForum()->create();
ResourceFactory::new()->for($category)->count(3)->create();
```

The thread is made by `ThreadFactory` and not by the Resource Manager, which would date it now
and queue a webhook. One difference follows: the resource's author does not watch the thread.

**Comments, since 1.0.0b2.** `MediaCommentFactory::new()->for($media)->recycle($members)` makes
a visible comment by one of those members. It counts towards the item's comments and its
category's, is in the news feed, and an item's comments are dated in the order they were made.

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

### Widgets

*Since 1.0.0b2.*

```php
$widget = WidgetFactory::new()->at('thread_view_sidebar')->create(['title' => 'Statistics']);

WidgetFactory::new()->of('new_posts', ['limit' => 3])->create();
WidgetFactory::new()->html('<b>Seeded</b> notice')->create();
```

| factory | builds | states |
|---|---|---|
| `WidgetFactory` | the forum statistics widget, with a title, in the sidebar of the forum list | `of($definitionId, $options)`, `at(...$positions)`, `html($markup)` |

**A seeded widget is on the forum's own pages**, for every visitor, until its batch is cleaned.
A definition or a position the forum does not have is refused. `at()` with no position makes a
widget that is shown nowhere.

**An HTML widget costs more than the others.** It has a template of its own, and saving or
deleting a template does what editing one in the admin control panel does: every style is marked
as changed and the cached CSS is emptied. So the default is a widget without one, and
`seeder:validate` seeds none.

XenForo compiles each widget to a file in its code cache and does not delete that file with the
widget. Cleaning a seeded widget deletes it.

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

**A fault in an add-on names a file that add-on does not have.** The add-on is a real one on the
forum; the class, file and line are invented. Nobody reading a seeded log should go looking for
them.

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

### The admin log

*Since 1.0.0b3.*

```bash
php cmd.php seeder:run AdminLog -p entries=500 -p admins=4
```

**Entries are written as data. Nothing is submitted to the admin control panel** and no setting
changes. Each entry is what XenForo records for a submission there: the administrator, the route
path such as `options/update`, the posted input with the token and any password masked, and the
address it came from.

| factory | builds | states |
|---|---|---|
| `AdminLogFactory` | one entry, by a new administrator unless given some to `recycle()` | `by($admin)`, `action('options/update', [...])`, `from('203.0.113.9')` |

**A log has repeats in it, on purpose.** A few actions account for about two entries in three and
each administrator keeps one address, so the same administrator doing the same thing from the
same place recurs, as it does on a real forum. Some posted values carry markup, for whatever
displays the log to escape. Some entries have an empty path, which is how XenForo logs the
control panel's front page.

**The seeder creates real administrators** for the entries to be by, and says so before it creates
anything. See *Members*. `AdminLogFactory::administrator()` gives a factory for one who registered
before the quiet period, so that no entry has to come before its author.

**No entry is dated within the quiet period**, the same one the server error log keeps and
`errorLogQuietHours` sets. An add-on that reports on the admin log by another rule will still
report on these; `seeder:run` names each one it finds active, and `seeder:validate` warns of them.

**XenForo prunes this log by age.** Entries older than its `adminLogLength` setting, 60 days
unless changed, go at its next daily clean-up whether or not the batch is cleaned.
`seeder:clean` reports those as already removed.

### The moderator log

*Since 1.0.0b4.*

```bash
php cmd.php seeder:run ModeratorLog -p entries=200 -p moderators=3
```

**Entries are written as rows. No moderator did anything**: no thread is locked or moved, and no
post is removed. Each entry is what XenForo records when one is - its own action names, such as
`delete_soft` or `move`, with the parameters its wording uses, and the content's title, author and
address as XenForo's handlers record them - about the seeder's own members, threads and replies.

| factory | builds | states |
|---|---|---|
| `ModeratorLogFactory` | entries about threads, posts and members, by a new moderator unless given some | `for($thread, $post, $member, ...)`, `by($moderator, ...)`, `count(n)` |

**Nothing that reacts to a moderator action sees these.** Other add-ons act on a moderator action
as it is taken - by extending XenForo's logger, or on each entry as it is saved - and send it to a
chat channel or a log. A row put straight into the table goes past both, and `seeder:run` names
each such add-on it finds active. An add-on that reads the table for new rows some other way
would still find them. Each row is inserted alone, so the id it was given and no other is
recorded, and cleaning never removes a real moderator's entry.

**The log is a history that agrees with the content as it is now.** An action that changes a
state is undone later unless the content is in that state now: an open thread was locked and
unlocked, a locked one was locked. A member was approved from the queue, rejected by the
moderator their rejection names, or had a change of name approved by the moderator who approved
it. No entry is dated within the quiet period the error log keeps, before its content or before
its moderator joined. `ModeratorLogFactory::moderator()` gives a factory for a moderator who
registered before the quiet period.

**XenForo prunes this log by age only when told to**, with its `moderatorLogLength` setting; 0,
the default, keeps everything.

### Your own seeders and factories

**Any add-on can carry seeders for its own content.** Put a class in the add-on's `Seed` directory
and run it as `Vendor/AddOn:Name`. Until 1.0, `AbstractSeeder`, `AbstractFactory` and
`AbstractBulkFactory` may still change; the changelog will say when they do. They stay in the
`Hampel\Seeder` namespace.

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

**Faker knows a few things about forums.** Besides Faker's own, `$this->context()->faker` has
`forumUsername()`, `threadTitle()` and `bbCodeMessage()`, and:

| method | gives |
|---|---|
| `crawlerUserAgent()` *(since 1.0.0b3)*, and the three below | a real crawler's user agent that this forum takes for a robot, the commonest most often |
| `crawlerUserAgent('bing')` | one this forum takes for that robot, by its key |
| `browserUserAgent()` | a browser's user agent that this forum does not take for a robot |
| `robotFor($userAgent)` | the key of the robot the forum takes an agent for, or `''` |
| `documentationIp()` *(since 1.0.0b4)* | an address from the ranges set aside for documentation, one in ten IPv6 |

Which agents are robots is the forum's robot list, which other add-ons can extend, so the same
agent can be a robot on one forum and not on another. `robotFor()` matches the list as XenForo
does without asking XenForo, so an add-on that extends that check to store the agents it sees
does not store these.

**Writing rows in bulk.** For a table whose rows stand alone, extend `AbstractBulkFactory` and
yield the rows from `rows()`, each as the database stores it. It is the wrong tool for content: no
entity is saved, so no counter, search index or other add-on's bookkeeping is updated.

A key written `Relation.column` sets a column on a related entity, which is created if need be and
saved with the main one.

`create()` records the entity for `seeder:clean`, switches its webhooks off, and saves it with
XenForo's clock moved to the date `clockFor()` returns. Override `store()` where saving takes more
than `$entity->save()`, `remove()` where deleting leaves something behind, and `describe()` to add
fields to the JSON a run prints.

**`store()` need not call `save()` itself.** It can hand the entity to a service - XenForo's, or
the add-on's own uploader - so that what is seeded is validated and stored as the real thing is.
The entity only has to be saved by the time `store()` returns.

**An attribute that is not a column is taken out in `newEntity()` and kept until `store()`** - a
title that goes into a phrase, or the file an upload is made from. Keep it in a static `WeakMap`
keyed by the entity, as this add-on's `PrefixFactory` does:

```php
/** @var \WeakMap */
private static $pending;

protected function newEntity(array $attributes): Entity
{
	$title = (string) $attributes['title'];
	unset($attributes['title']);

	$entity = parent::newEntity($attributes);

	self::$pending = self::$pending ?? new \WeakMap();
	self::$pending[$entity] = ['title' => $title];

	return $entity;
}

protected function store(Entity $entity): void
{
	$entity->save();

	$phrase = $entity->getMasterPhrase();
	$phrase->phrase_text = self::$pending[$entity]['title'];
	$phrase->save();
}
```

An array on the factory keyed by `spl_object_id()` is not a substitute: PHP gives a collected
entity's id to the next one, and each configuring method returns a copy of the factory with the
array in it.

**A `remove()` that leaves files behind is not caught by `seeder:validate`**, which counts rows.
XenForo puts an `index.html` in each directory it makes under `data` and `internal_data`, and
leaves both when the last file goes; and it never deletes a widget's compiled file. A factory
whose entities have files deletes them in `remove()`, and its author counts the files before
seeding and after cleaning.

## Commands

```bash
php cmd.php seeder:run [seeder]    # create content
php cmd.php seeder:config       # whether seeding is allowed, and the settings in use
php cmd.php seeder:validate     # whether this forum can be seeded - by seeding it and cleaning up
php cmd.php seeder:list         # the batches of seeded content on this forum
php cmd.php seeder:clean [batch]   # remove one batch, or every batch
php cmd.php seeder:forget [batch]  # keep the content, discard the record of it
```

`seeder:list` and `seeder:clean` take `--json`.

**`seeder:validate` is not a read-only check.** Where seeding is switched on it creates content
on the forum and removes it again; see *The round trip*. `seeder:validate --unattended` and
`seeder:config` write nothing.

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

**It compares rows, not files.** A file left in `data` or `internal_data` does not show here.

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
- **Another add-on may tell someone.** Seeded content is saved through XenForo's entities with
  XenForo's own mail, alerts, push notifications, webhooks and moderator log switched off or
  never called. An add-on that sends something of its own when a member, post or resource is
  saved - to a chat channel, a mailing service, a CRM - is not switched off by any of that. Try a
  small run on a forum that cannot reach the outside before a large one on a forum that can.
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
