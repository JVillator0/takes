---
name: agent-worktrees
description: Run up to five coding agents in parallel on Takes with a fixed pool of git worktrees (takes-a … takes-e), served by Valet and isolated per letter (database, test database, Reverb and Vite ports). Use before handing a task to an agent, when choosing which worktree, database or port an agent gets, when two agents might collide, when a run fails for reasons that look environmental, and when releasing a worktree after its PR merges.
---

# Agent worktrees: a fixed pool, not one per task

Parallel agents need separate checkouts, or they overwrite each other's files, branches and test data. A worktree per task does not scale: every checkout needs its own dependencies, `.env`, databases and caches, and abandoned ones pile up. Takes uses a **fixed pool of five** (D-070): created once, each with a letter, taken by one agent at a time and released when its PR merges.

The pool is described in **`.claude/worktree-pool.md`** (spec 1-04). Read it before handing out a worktree; the table below is the summary.

## 1. The pool

All checkouts are siblings under `~/Documents/Work/promptedit/`.

| Checkout | Role | URL (Valet) | DB / test DB | Reverb | Vite |
|---|---|---|---|---|---|
| `takes` | lead, `main`, serves the app the reviewer looks at | https://takes.test | `takes` / `takes_testing` | 8080 | 5173 |
| `takes-a` | pool | https://takes-a.test | `takes_a` / `takes_testing_a` | 8081 | 5174 |
| `takes-b` | pool | https://takes-b.test | `takes_b` / `takes_testing_b` | 8082 | 5175 |
| `takes-c` | pool | https://takes-c.test | `takes_c` / `takes_testing_c` | 8083 | 5176 |
| `takes-d` | pool | https://takes-d.test | `takes_d` / `takes_testing_d` | 8084 | 5177 |
| `takes-e` | pool | https://takes-e.test | `takes_e` / `takes_testing_e` | 8085 | 5178 |

- **Main checkout** (`takes`): the lead session only. It stays on `main` and is the only checkout that receives Pixelforge webhooks (ngrok, D-047). Agents never work in it.
- **Pool size is the upper bound** on agents writing at once. Do not create a sixth worktree because the pool is busy: wait for one, or ask the lead to raise the bound.
- **Never:** nested worktrees; a worktree on `main` (git refuses while the main checkout holds it).
- Shared services: Homebrew **Postgres 17 on port 5417** (one server, one database pair per letter) and Valet (nginx + PHP 8.5). Only the lead starts, stops or reconfigures them.

### Creating the pool (once, after Gate A)

```bash
cd ~/Documents/Work/promptedit/takes && git fetch origin
git worktree add ../takes-a origin/main --detach        # repeat for b … e
cd ../takes-a
composer install && npm ci
scripts/worktree-env.sh a                               # .env from .env.example + letter values (spec 1-04)
createdb -p 5417 takes_a && createdb -p 5417 takes_testing_a
php artisan migrate --seed
valet link takes-a && valet secure takes-a
```

`scripts/worktree-env.sh <letter>` builds `.env` from `.env.example` with the letter's database, URL and ports. It never copies secrets from the main `.env`; it reads them itself with `op read` (D-004), and provider keys go to a worktree only when it really needs the real API (rarely; see section 3).

## 2. Taking and releasing a worktree

**See the pool:**

```bash
.claude/skills/agent-worktrees/scripts/pool_status.sh ~/Documents/Work/promptedit/takes
```

It prints, per worktree: branch, commits ahead of `origin/main`, uncommitted files, lock reason and live processes. A worktree is **free** when it is not locked, has no uncommitted files, no agent process lives in it, and its branch is merged or abandoned. A squash-merged branch still shows commits ahead; check the PR.

**Take one (the lease is the lock, so lock first):**

```bash
git -C ../takes-b worktree lock --reason "<agent>: <spec or issue> (<YYYY-MM-DD>)" . \
  && git -C ../takes-b fetch origin \
  && git -C ../takes-b switch -c <type>/<slug> origin/main
```

`git worktree lock` fails when the worktree is already locked, so it is the atomic lease: if it fails, someone else holds the letter; pick another one and touch nothing here. Locking before the fetch and switch means two agents picking the same letter can never both change its branch. The lock shows in `git worktree list` and in the status script, and keeps `git worktree prune` / `remove` away from a worktree in use. Refresh caches before handing it out (section 4).

**Integrate:** only through a PR. The agent opens it (`gh pr create`, Conventional Commit title, the 1-01 template), waits for `gh pr checks <n> --watch`, and the `pr-review` workflow (spec 1-03) reviews and squash-merges it. Agents never push to `main`, merge or force-push. If `main` moves, the agent rebases **only when asked** and then stops running tests until it reports; never rebase while the review workflow is reading the branch.

**Release after merge** (or when the task is abandoned):

```bash
git -C ../takes-b worktree unlock .
git -C ../takes-b switch --detach origin/main
git -C ../takes-b branch -D <type>/<slug>       # local only, after it merged
```

Before handing a released worktree to someone else, check it is clean. Review foreign changes file by file; never revert them in bulk and never adopt them without asking.

## 3. Isolation per letter

| Resource | Rule |
|---|---|
| Test database | `takes_testing_<letter>`, passed on every test command: `DB_DATABASE=takes_testing_b vendor/bin/pest`. Parallel runs create `takes_testing_<letter>_test_<n>` from it. |
| App database | `takes_<letter>`; `migrate:fresh` only in your own letter, after checking the directory and branch in the same command. |
| URL | `https://takes-<letter>.test` through Valet; no `php artisan serve`. |
| Ports | With a=1 … e=5: Reverb `8080 + n`, Vite `5173 + n` (table above). Stop servers by port (`lsof -tiTCP:<port>`), never by process name. |
| Queue | `QUEUE_CONNECTION=database`; run `php artisan queue:work` in your letter only while you need it. |
| Logs and scratch | Inside the worktree (`storage/logs`), never a shared `/tmp/run.log`. |
| **Provider APIs** | **Limits are per account**: Pixelforge 60 rpm, 3 generations in flight, 500/day, shared with the deployed demo and evaluators; Anthropic has a $5 monthly cap (D-046). Worktrees use `Http::fake` and **signed webhook fixtures** by default. Only `main` talks to Pixelforge, and only one manual smoke runs at a time across the pool. |
| Webhooks | Worktrees do not receive webhooks. Takes finish through the reconcile job (spec 5); webhook behaviour is tested with signed fixtures. This also proves the polling fallback. |

**One letter, one process set.** A second process on the same test database (a coverage run next to an agent's run) gets its tables dropped mid-run by the other's `migrate:fresh`; the failures look like real bugs. **Only one full test suite at a time across the pool.**

## 4. Fast tests in a fixed worktree

A fixed pool pays off when each worktree keeps warm caches between tasks. The Laravel + Pest 5 recipe (seed reference data once per test database, memory limit in `phpunit.xml`, `--tia` with a local `tia-base` branch, coverage from the cache) is in **`reference/pest-tia.md`**. Adopt it only when the suite gets slow; until then, run the narrowest set that covers the change.

## 5. Running agents in the pool

- **Every shell command carries its absolute `cd` and a branch check:** `cd ~/Documents/Work/promptedit/takes-b && [ "$(git branch --show-current)" = "<branch>" ] || exit 1`. Shells do not keep the cwd between calls.
- **The brief names everything:** worktree, branch, letter's databases and ports, the spec section, the files the agent owns, and "do not change branch".
- **Heavy jobs queue:** full suites, coverage, builds. At most one full suite on the machine at a time; check `uptime` before launching.
- **The stash is shared by all worktrees.** Never `git stash` / `git stash pop`; use a WIP commit.
- **Destructive commands** (`migrate:fresh`, `db:wipe`, `rm -rf`, `git reset --hard`) only after checking the directory and branch in the same command.
- **The list of live agents can lie.** Before declaring a worktree free, check its processes and its lock (`pool_status.sh`), not just the agent list. Agents often launch one more test run after reporting: `pgrep -fl "takes-<letter>/vendor/bin/pest"`.
- **Never pipe a mutation runner into `head`** (SIGPIPE leaves a mutated file behind); pipe into `tail` or a log and check `git status` afterwards.

## 6. Cleanup

- `git worktree prune` removes records of deleted worktree folders; locked ones are kept.
- Squash merges do not show in `git branch --merged`; check the PRs before deleting local branches.
- Drop parallel test databases (`takes_testing_<letter>_test_<n>`) only when nothing runs in that letter.
- Shrinking the pool: unlock, `git worktree remove ../takes-<letter>`, `valet unlink takes-<letter>`, drop its databases, update `.claude/worktree-pool.md`.

## 7. Diagnosing environmental failures

| Symptom | Likely cause |
|---|---|
| Tables vanish mid-run; FK or "relation does not exist" errors in unrelated tests | Two processes on one test database |
| `SQLSTATE[08006]` connection refused | Postgres 17 not on 5417, or `DB_PORT` missing in the worktree `.env` |
| "permission denied for table" on a new database | It lacks the owner and grants of the others |
| A run with no summary line | A worker died; usually memory |
| A test at 0 % CPU for minutes | A lock wait; look at `pg_stat_activity` for sessions waiting on a lock (concurrency tests left a transaction open) |
| "already checked out at" | The branch is checked out in another worktree; one branch per worktree |
| Work appears in the wrong branch | A command ran without its `cd` and branch check |
| `takes-<letter>.test` serves another checkout's code | Valet link points elsewhere: `valet links` |
| Vite or Reverb "address already in use" | Another letter's port; use your letter's port from the table |
| HTTP 429 from Pixelforge in a worktree | A test or manual run hit the real API; switch to `Http::fake` (`Http::preventStrayRequests()`) |
| The main checkout fails after a merge | Dependencies the branch added were not installed there, or a queue worker still runs old code |
