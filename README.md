# Test data generator for XenForo

Fills a development forum with test content — and removes it again. It is for add-on developers
who need a forum with members and dated content to test against, and it is not for a live forum.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

**Early development.** It creates members, and removes them again. Forums, threads and posts are
not built yet.

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

## Usage

```bash
php cmd.php seeder:run -p users=50 --span=6m
```

That creates 50 members registered across the last six months, and prints what it made as JSON —
each member's id, name and address, and the one password they all share. A summary for a person
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

Everything a run creates is recorded under its batch, as it is created. A run that fails part-way
has still recorded what it made, exits 1, and says how to remove it.

### Seeders

`seeder:run` takes the name of a seeder, and runs `Default` without one.

| seeder | creates | parameters |
|---|---|---|
| `Default` | everything below | all of theirs |
| `User` | members | `users` (20) |

### Your own seeders and factories

**Any add-on can ship seeders for its own content.** Put a class in the add-on's `Seed` directory
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
| `new()`, `state()`, `sequence()`, `count()` | configure; each returns a changed copy |
| `afterMaking()`, `afterCreating()` | callbacks given the entity |
| `make()` | build without saving |
| `create()` | build, save and record |
| `raw()` | the attributes only |

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
```

`seeder:list` and `seeder:clean` take `--json`.

### Removing seeded content

**`seeder:clean` deletes only what was recorded when the content was created.** It asks first;
`--force` skips the question, and without a terminal to answer the question nothing is deleted.

It exits 0 when everything recorded is gone and 1 if anything could not be removed. Whatever
could not be removed stays recorded, so the command can be run again.

### Exit codes

| | all fine | warnings only | any failure |
|---|---|---|---|
| `seeder:validate` | 0 | 0 | 1 |
| `seeder:validate --strict` | 0 | 2 | 1 |

1 always means a check failed. This is not the monitoring-plugin numbering, where 1 is a warning
and 2 is critical.

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
