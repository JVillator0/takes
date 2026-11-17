# 1-05 · Planning in GitHub: milestones, issues, public Project

Status: **Approved** · Milestone: **M1 Harness** · Depends on: 0 · Unblocks: all · Parallel: no
Reviews: product ☑ · technical ☑

## Goal
The evaluator can see the plan and its execution: specs → milestones → issues → PRs (with reviews) on a public Project board.

## Mapping
- One **milestone** per child spec folder (M0 … M10), description = spec goal + link to the spec file under `.claude/specs/` (D-041; the specs and the Project intentionally mirror each other). Closing a milestone cuts a `v0.N.0` tag + GitHub Release with notes generated from Conventional Commits; `v1.0.0` only at submission (D-043).
- One **issue** per item in each spec's *Issues* section, titled with the spec-section anchor (e.g. `[5.2] Webhook signature`) so titles stay unique. Issues for every milestone are created up front (D-070: specs 6-8 start at Gate B). All milestones exist from the start. Body: context (spec section link), acceptance criteria copied from the spec, "Out of scope".
- Labels: `spec/0` … `spec/10`, `type:feature|chore|docs|test|fix`, `area:backend|frontend|filament|harness|infra`, `required|stretch`, `verdict:*`.
- Every PR body contains `Closes #N`; branch names `<type>/<issue>-<slug>` (e.g. `feat/42-webhook-signature`).

## Project (GitHub Projects v2, public)
- Name: **Takes · plan and execution**.
- Fields: Status (Todo / In progress / In review / Done), Worktree (main/a/b/c/d/e, D-070), Size (S/M/L), Required/Stretch. Milestone and spec come from the milestone and `spec/n` label.
- Views: *Board by status*, *Roadmap by milestone*, *By worktree* (shows the parallelism).
- Automation: issue added → Todo; PR opened → In review; PR merged → Done.
- Views and the built-in workflows cannot be created through the API; they are set once in the Project UI by Javier (manual step, tracked on #27).

## Creation (scripted, after spec approval)
`scripts/plan-to-github.sh` reads each spec's *Issues* list from `.claude/specs/` and creates milestones, labels and issues with `gh`, then adds them to the Project (`gh project item-add`). Idempotent (skips existing titles); `--dry-run` prints what it would create. It sets Worktree and Size from the phase table in 1-04.

## Acceptance criteria
- [ ] The Project is public and linked from the README.
- [ ] Every merged PR is linked to an issue in a milestone.

## Issues
1. `scripts/plan-to-github.sh` (with `--dry-run`, Worktree/Size fields).
2. Create labels, milestones, issues, Project and views.

## Open questions
- None blocking.
