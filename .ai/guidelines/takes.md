# Takes project

- Takes is an image-generation studio on Pixelforge; customers pay provider cost + 25% from prepaid credits. Specs live in `.claude/specs/` (start with `README.md`; decisions in `DECISIONS.md`).
- Everything committed (code, comments, commits, PRs) is in English. Commits and PR titles follow Conventional Commits.
- Before editing, read the rules file for the paths in scope: `.claude/rules/architecture.md` (PHP), `money.md`, `webhooks.md`, `frontend.md` (`resources/js`), `filament.md` (`app/Filament`).
- Business logic lives in `final readonly` Actions with a single `execute()` method, reads in Query Objects, inputs in `final readonly` DTOs with `fromArray()`. No Service or Repository classes (the only exception is `App\Services\Pixelforge\PixelforgeClient`).
- Two guards: admins use `users` + `web`; customers use `customers` + `customer`. Customer routes use `auth:customer` plus the middleware that calls `Auth::shouldUse('customer')`.
- Checks: `composer check`, `npm run check`, `npm run types`. In a pool worktree, pass the letter's test database: `DB_DATABASE=takes_testing_<letter> vendor/bin/pest`.
- Pool worktrees never call the real Pixelforge or Anthropic APIs: use `Http::fake` and signed webhook fixtures.
