# Worktree pool

How up to five agents work in parallel without colliding on files, databases, ports or provider quota (spec 1-04, D-070). Read this before taking a worktree.

## Pool

| Checkout | Role | URL | DB / test DB | Reverb | Vite |
|---|---|---|---|---|---|
| `takes` | lead, `main`, the app Javier reviews | https://takes.test | `takes` / `takes_testing` | 8080 | 5173 |
| `takes-a` | pool | https://takes-a.test | `takes_a` / `takes_testing_a` | 8081 | 5174 |
| `takes-b` | pool | https://takes-b.test | `takes_b` / `takes_testing_b` | 8082 | 5175 |
| `takes-c` | pool | https://takes-c.test | `takes_c` / `takes_testing_c` | 8083 | 5176 |
| `takes-d` | pool | https://takes-d.test | `takes_d` / `takes_testing_d` | 8084 | 5177 |
| `takes-e` | pool | https://takes-e.test | `takes_e` / `takes_testing_e` | 8085 | 5178 |

- Checkouts are siblings: `~/Documents/Work/promptedit/takes` and `takes-a` … `takes-e` (`git worktree add ../takes-<l> --detach`).
- Postgres 17 (Homebrew) on port **5417**, role = the macOS user, no password. Port 5432 is another Postgres version on this machine; never point the app at it.
- Valet serves every checkout over TLS (`valet link takes-<l> && valet secure takes-<l>`).
- `VITE_PORT`, `REVERB_SERVER_PORT` (the port `reverb:start` binds) and `REVERB_PORT` (the port clients connect to) are written to `.env` now, both Reverb ports set to the slot's value. Reverb itself arrives with spec 5, which must keep `config/reverb.php` reading `REVERB_SERVER_PORT` so five `reverb:start` processes do not collide on 8080. Start Vite with `npm run dev -- --port <vite> --strictPort`.

## Scripts

| Command | What it does |
|---|---|
| `scripts/worktree-env.sh <a-e\|main>` | Idempotent. Builds `.env` from `.env.example` plus the slot values above (`APP_URL`, `DB_*`, ports, `APP_KEY` if missing), creates both databases if missing, links and secures the Valet site if missing. Refuses to run when the directory name does not match the slot (`--force` overrides). `--no-db`, `--no-valet` skip steps. |
| `scripts/worktree-env.sh <l> --with-secrets` | Same, then `scripts/env-from-1password.sh local`. Only for a checkout that really needs the real provider API (normally only `main`). |
| `scripts/test.sh [pest args]` | Runs `vendor/bin/pest` with `DB_DATABASE` derived from the directory name (`takes-<l>` ⇒ `takes_testing_<l>`, `takes` ⇒ `takes_testing`). Equivalent to `DB_DATABASE=takes_testing_<l> vendor/bin/pest`. |
| `scripts/env-from-1password.sh <local\|production>` | Writes only the provider secrets into `.env` (or `.env.production`) from 1Password references in the git-ignored `scripts/secrets.local.env`, or from `$TAKES_KEYSTORE_DIR/<env>.env`. Never prints values; `chmod 600`. |

Secrets are never copied from another checkout's `.env`. Vault names, item names, references and keystore paths stay out of git (D-004).

## Lease protocol

A worktree belongs to one agent at a time. The lock reason is the lease.

1. **Pick** a free letter: `git worktree list` (a locked worktree is leased; `git worktree list --porcelain` shows the reason).
2. **Lease** it from inside the worktree, then branch:
   ```bash
   git worktree lock --reason "<agent>: <issue> (<YYYY-MM-DD>)" .
   git switch -c <type>/<short-name> origin/main
   scripts/worktree-env.sh <l>
   ```
3. **Work** only inside that checkout. Never edit `takes` (main) or another letter's files.
4. **Release** after the PR is merged (from inside the worktree):
   ```bash
   git worktree unlock .
   git switch --detach origin/main
   git branch -D <type>/<short-name>
   ```
   A released worktree is detached, unlocked and has no local feature branch. Its databases and Valet site stay for the next lease.

## Shared limits

- **One full test suite at a time across the pool.** A filtered run (`scripts/test.sh --filter …`) is fine; announce a full run before starting it.
- **One process set per letter.** Each checkout runs its own `queue:work` (`QUEUE_CONNECTION=database`) only while it needs one, and uses its own Vite and Reverb ports.
- **Only `main` talks to the real Pixelforge API.** Limits are per account, shared with the deployed demo and evaluators: **60 requests/min, 3 generations in flight, 500/day**. Worktrees use `Http::fake` and signed fixtures; worktree `.env` files have no provider keys unless `--with-secrets` was used for a stated reason. Only one manual smoke run at a time, and only from `main`.

## Webhooks locally

- The **local** Pixelforge key's webhook URL points to a plain **ngrok** tunnel to `main` (D-047):
  ```bash
  ngrok http https://takes.test --host-header=rewrite
  ```
  The URL is random. If the tunnel restarts, update the webhook URL on the local key in the Pixelforge dashboard. After the build session, the Laravel Cloud production environment is used. The dashboard's "resend events" is the demo path for replays.
- **Worktrees do not receive webhooks.** They finish takes through the reconcile job (spec 5) and test webhooks with signed fixtures. This is intentional: it also proves the polling fallback works.

## Recreate the pool from scratch

```bash
cd ~/Documents/Work/promptedit/takes
scripts/worktree-env.sh main --with-secrets
for l in a b c d e; do
  git worktree add "../takes-$l" --detach origin/main
  (cd "../takes-$l" && scripts/worktree-env.sh "$l" && composer install && npm ci && php artisan migrate)
done
```
