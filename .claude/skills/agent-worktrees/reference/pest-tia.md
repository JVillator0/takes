# Fast Laravel test feedback in fixed worktrees: seed once, Pest 5 `--tia`, coverage from cache

An optional recipe for when the Takes suite gets slow. In a large Laravel 13 + Pest 5 project it took a ~7,000-test suite from about 70 minutes to about 40 seconds per branch, with the coverage floor checked on every branch. Adopt the parts you need; the composer scripts and `tests/Pest.php` changes go through a normal PR (spec 1-01 owns them).

## 1. Seed reference data once per test database

`RefreshDatabase` runs `migrate:fresh` once per process and wraps every test in a transaction. When the base test case declares `$seeder`, that seeder runs inside the single `migrate:fresh`, not per test:

```php
abstract class TestCase extends BaseTestCase
{
    /** @var class-string<Seeder> */
    protected $seeder = TestBaselineSeeder::class;   // e.g. customer-guard roles and permissions
}
```

- Measure first: time an empty test against one that runs each seeder.
- Remove the per-test calls of those seeders, and nothing else.
- Tests that need those tables empty clear them themselves (seeder tests, permission counts).
- Concurrency tests (`--group=concurrency`, spec 3-03) use `DatabaseMigrations` or committed seed data, not `RefreshDatabase`; they run serially and are not part of this optimisation.

## 2. Memory for every process

Paratest workers do not inherit `php -d memory_limit=...`. Put it in `phpunit.xml`:

```xml
<php>
    <ini name="memory_limit" value="2G"/>
</php>
```

A run that ends without a summary line usually means a worker died of memory.

## 3. Test impact analysis (`--tia`)

`--tia` records which source files each test touches and re-runs only the tests whose files changed, replaying cached results for the rest. It needs a coverage driver (pcov).

- The cache lives in `~/.pest/tia/<worktree-key>/`, so each pool worktree keeps its own warm graph.
- A branch with no baseline falls back to the default branch. Worktrees cannot check out `main` (the main checkout holds it), so each keeps a local, never-pushed `tia-base` branch, named in `tests/Pest.php`:

  ```php
  pest()->tia()->defaultBranch('tia-base');
  ```

- **It never saves on a detached HEAD.** A run there records everything and keeps nothing.
- Composer kills scripts after 300 s; make `Composer\\Config::disableProcessTimeout` the first entry of any `test:tia` / `test:coverage` script.

Flow per worktree:
1. Once (or when the cache is lost): `git switch -C tia-base origin/main`, then a full `--tia` run, then a `--tia --coverage` run.
2. Before handing it to an agent: `git switch -C tia-base origin/main` and a `--tia` run (incremental), then `git switch -c <type>/<slug> origin/main`.
3. The agent, on its branch: `DB_DATABASE=takes_testing_<letter> vendor/bin/pest --parallel --tia` before each push.

Reading the output: the `Tests:` line says how many were affected and how many replayed; "fresh graph" means a full recording; "Recorded commit is no longer reachable" means the baseline is not an ancestor of HEAD (refresh `tia-base`).

## 4. Coverage from the cache

Record with a plain `--tia` first; a first `--tia --coverage` with no graph skips TIA. After one recording, `--tia --coverage --min=85` (the Gate B floor, D-060) replays too. Line coverage says a line ran, not that an assertion watches it; `pest --mutate` on money code is the stricter measure.
