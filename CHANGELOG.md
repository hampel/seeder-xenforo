# CHANGELOG

## 1.0.0 (unreleased)

First release.

- `seeder:run` fills a forum with test content from seeders and factories, with Faker: members,
  forums, threads and replies with prefixes, tags, attachments and reactions, featured threads,
  moderated and deleted content, conversations, custom user fields, user upgrades, warnings, API
  keys, who is online, and the server error log
- forums can be of any type - article, question, suggestion - with threads of that type, and
  search forums list the threads of the forums they are given
- threads can be sticky and can have a poll with votes, and a forum can be told which other
  types of thread it takes
- Resource Manager resources and Media Gallery items, where those add-ons are installed;
  resources can be rated, reviewed and featured and can have discussion threads, and media
  items can have comments
- members can be given other ways into their account (a connected account, two-step
  verification, a passkey, a remember key, a pending confirmation) and activity without content
- members awaiting approval are in the approval queue at the date they registered, so a seeded
  queue has an order
- members can be banned, permanently or until a date, with the ban record XenForo keeps, and
  can be in the state XenForo gives a member whose mail bounced
- members can be made without a password, as XenForo makes one who registers through a connected
  account, or with no authentication record at all
- members can be administrators, with the administrator record XenForo keeps and whichever
  administrator permissions are named
- admin log entries, by seeded administrators, with the repeats a real log has
- members can hold permissions, through a user group made to grant them; user groups can be made
  on their own with the permissions they grant
- members can have an IP log - registering, logins, logins to the admin control panel and changes
  to the account - and threads and replies can log the address they were posted from, all from
  addresses in the ranges set aside for documentation that some members share
- Faker can give a real crawler's user agent that the forum takes for a robot, a browser's that
  it does not, and the robot the forum takes any user agent for
- widgets, with a title, in any widget position; cleaning one also deletes the compiled file
  XenForo leaves behind
- everything created is recorded in a batch: `seeder:list` shows the batches, `seeder:clean`
  removes one and `seeder:forget` keeps the content and discards the record
- content is dated across a span of time and not all at the moment it was made, and a run given
  a `--seed` produces the same content again
- a parameter no seeder asks for fails the run and is named, so a mistyped one is not silently
  dropped
- nothing a seeded entity does leaves the forum: no mail, alerts, push notifications, webhooks or
  moderator log entries
- nothing runs until `$config['hampelSeeder']['enabled'] = true;` is in `config.php`
- `seeder:config` reports the settings and `seeder:validate` checks them, then seeds a sample and
  removes it and compares every table before and after
- uninstalling is refused while a batch is recorded, so seeded content is never left with no way
  to remove it
- other add-ons can ship their own seeders and factories built on `AbstractSeeder`,
  `AbstractFactory` and `AbstractBulkFactory`
