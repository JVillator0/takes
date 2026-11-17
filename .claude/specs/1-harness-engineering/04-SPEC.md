# 1-04 · Parallel work: worktree pool on Valet, Sail compatibility

Status: **Approved** · Milestone: **M1 Harness** · Depends on: 0 · Unblocks: Gate B · Parallel: no
Reviews: product ☑ · technical ☑

## Goal
Up to five agents write in parallel (D-070) without colliding (files, DBs, ports, webhooks), using Valet for speed, while the project still runs with Sail for evaluators.

## Pool (agent-worktrees skill)
| Checkout | Role | URL | DB / test DB | Reverb | Vite |
|---|---|---|---|---|---|
| `takes` | lead, `main`, serves the app Javier reviews | https://takes.test | `takes` / `takes_testing` | 8080 | 5173 |
| `takes-a` | pool | https://takes-a.test | `takes_a` / `takes_testing_a` | 8081 | 5174 |
| `takes-b` | pool | https://takes-b.test | `takes_b` / `takes_testing_b` | 8082 | 5175 |
| `takes-c` | pool | https://takes-c.test | `takes_c` / `takes_testing_c` | 8083 | 5176 |
| `takes-d` | pool | https://takes-d.test | `takes_d` / `takes_testing_d` | 8084 | 5177 |
| `takes-e` | pool | https://takes-e.test | `takes_e` / `takes_testing_e` | 8085 | 5178 |

- Homebrew Postgres 17; `valet link takes-a && valet secure takes-a`, etc.
- `scripts/worktree-env.sh <letter>` builds `.env` from `.env.example` + letter-derived values. It does not copy secrets from the main `.env`; it calls `op read` itself (D-004). Pixelforge/Anthropic keys go to a worktree only when it really needs the real API.
- Lease = `git worktree lock --reason "<agent>: <issue> (<date>)"`; release after merge (`unlock`, detach, delete local branch).
- **Provider limits are per account** (60 rpm, 3 generations in flight, 500/day, shared with the deployed demo and evaluators). Worktrees use `Http::fake` and signed fixtures by default; only `main` talks to Pixelforge, and only one manual smoke runs at a time across the pool. Noted in `worktree-pool.md`.
- One letter, one process set. Only one full test suite at a time across the pool.
- Queue locally: `QUEUE_CONNECTION=database`; each checkout runs its own `queue:work` only while needed.

## Webhooks locally
- The **local** Pixelforge key's webhook URL points to a plain **ngrok** tunnel to **main** (`ngrok http https://takes.test --host-header=rewrite`, D-047). A random URL is fine because the build happens in one session; if the tunnel restarts, update the webhook URL on the local key in the Pixelforge dashboard. Afterwards the production environment on Laravel Cloud is used. The dashboard's "resend events" is the demo path for replays.
- Worktrees don't receive webhooks; they finish takes through the reconcile job (spec 5) and test webhooks with signed fixtures. This is intentional: it also proves the polling fallback works.

## Sail (must keep working)
`compose.yaml`: `laravel.test`, `pgsql` (17), `reverb` (`php artisan reverb:start --host=0.0.0.0 --port=8080`), `queue` (`php artisan queue:work`). README documents the Sail `.env` overrides. CI validates the file (spec 1-01).

## Which work runs where (D-070)
| Phase | a | b | c | d | e |
|---|---|---|---|---|---|
| Before Gate A | — | — | — | — | — (lead only: specs 0, 1) |
| M3, after the 3-02 schema PR | 3-01 Identities | 3-03 Money | 3-04 Client | 3-02 actions | — |
| Gate B | 4 Takes | 5 Webhooks & live | 7 Admin console | 6 Billing (backend first) | 8 AI (backend first) |

## Acceptance criteria
- [ ] Five worktrees run their suites (one at a time) and serve their URLs simultaneously without port/DB conflicts.
- [ ] `sail up` + `sail artisan migrate --seed` works from a fresh clone.

## Issues
1. `.claude/worktree-pool.md` + `scripts/worktree-env.sh`.
2. Create the pool (after Gate A).
3. Verify `compose.yaml` and the README Sail section (built in spec 0 issue 3/4) still work with the worktree env script.

## Open questions
- None blocking.
