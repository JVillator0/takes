# 1-03 · PR review and auto-merge workflow

Status: **Approved** · Milestone: **M1 Harness** · Depends on: 1-01, 1-02 · Unblocks: every feature PR · Parallel: no
Reviews: product ☑ · technical ☑

## Goal
Every PR receives three specialist reviews and a judge's scored verdict, published on GitHub, and is merged automatically only when the judge says MERGEABLE and CI is green. The PR history becomes the evidence of the harness.

## Flow
1. **Author** (lead or worktree agent) opens the PR with `gh pr create`, linked to its issue (`Closes #N`), using the template (PR title is a Conventional Commit, e.g. `feat(billing): …`, D-039; squash keeps it as the commit message): *Summary · Why · Spec section · Changes · Money impact · Test evidence · Screenshots · Risks · Checklist*.
2. `gh pr checks --watch` until CI is green. Red CI ⇒ author fixes before review.
3. **Review workflow** (Claude Code Workflow tool, saved as `.claude/workflows/pr-review.js`, run from a local session; ≤ 4 concurrent agents, **all Opus 5.5**). The workflow records the reviewed **head SHA**.
   - Select reviewers by changed paths (spec 1-02); each reviewer receives only the diff of its own paths. A reviewer with no matching files is skipped and listed as N/A. **Docs-only or generated-file PRs run the judge only.**
   - Reviewers run in parallel; each posts **one GitHub review** via `gh api repos/:o/:r/pulls/:n/reviews` with `event=COMMENT`, inline comments on lines, header `Laravel Backend Review · automated reviewer (Claude Opus 5.5)`.
   - The **judge** runs after the reviewers, reads diff + reviews + CI + linked issue + spec section, and posts the scorecard comment.
4. **Verdict handling**
   - MERGEABLE ⇒ judge re-reads `gh pr checks` and the PR head SHA immediately before merging; if the SHA differs from the reviewed one it refuses and the workflow re-runs. Otherwise it adds label `verdict:mergeable` and runs `gh pr merge --squash --auto --delete-branch`, so GitHub itself waits for the required checks; the issue closes; the worktree is released. Money/webhook PRs also need the author's checked "I can explain every line" item in the PR body (1-01 template).
   - MERGEABLE AFTER NITS ⇒ label `verdict:nits`; author fixes in new commits, replies to threads; workflow re-runs (judge only needs to re-check).
   - NOT MERGEABLE ⇒ label `verdict:not-mergeable`; author fixes in **new commits** (no force-push), replies "Fixed in abc1234" or "Won't fix: …" per thread, resolves; full workflow re-runs.
   - Max 3 rounds; then the lead escalates to Javier.
5. Since author and reviewers use the same GitHub account, GitHub only allows `COMMENT` reviews (no APPROVE). The verdict lives in the judge comment + label.

## Judge rubric (adapted from `diimo/core/.claude/agents/code-reviewer.md`)
Scores 0–10, N/A excluded from the average:
1. Intent & scope (matches issue/spec, nothing extra)
2. Correctness
3. **Money safety** (nanodollars, ledger, exactly-once, failed = free)
4. Robustness (provider failures, idempotency, concurrency, ordering)
5. Architecture & conventions
6. Clarity (explainable in a live interview)
7. Tests (meaningful, cover the failure paths)
8. Security & authorization
9. UX vs wireframes (only if UI changed; checked against the custom admin pages (dashboard, Reports, Reconciliation, take infolist) and the public screens; standard Filament list/view resources are not graded)

**Hard gates** (any failure ⇒ NOT MERGEABLE): CI green (includes the `pr-title` Conventional Commit check) · any applicable **Money safety or Robustness score below 7** · no float in money paths · migrations reversible · no secrets · PR body complete and linked to an issue · English only · no unresolved critical finding.

**Verdict:** MERGEABLE = avg ≥ 8.0, 0 critical, gates pass · MERGEABLE AFTER NITS = avg ≥ 7.0, 0 critical, only minor findings · otherwise NOT MERGEABLE, with the list of what must change.

Findings are numbered #1…#n across Critical / Important / Minor, each tagged *introduced* vs *pre-existing*.

## Judge comment format
```
## Judge verdict: MERGEABLE · 8.6 / 10
| Dimension | Score | Note |
|---|---|---|
| Money safety | 10 | Settlement only inside terminal transition; unique (usage_record_id) where type='charge'. |
| … | | |
Gates: CI ✓ · CC title ✓ · no float ✓ · reversible migrations ✓ · secrets ✓ · PR body ✓ · English ✓
Findings: #2 (minor, introduced) … 
Decision: merged with squash.
```

## Acceptance criteria
- [ ] PR #1 (harness) is reviewed by running the script from its branch (the script cannot exist on `main` yet) and merges; PR #2 is the first PR reviewed by the workflow from `main`.
- [ ] One seeded bad PR (float in money code) gets NOT MERGEABLE with the gate named; it stays closed and is linked from the README "How this was built" section.
- [ ] Review comments and verdicts are visible on the public PRs.

## Issues
1. Workflow script `.claude/workflows/pr-review.js`.
2. Judge prompt + rubric; reviewer output schema.
3. Labels (`verdict:*`, `spec/*`, `type:*`).
4. Dry run on a throwaway PR, then on PR #1.

## Open questions
- None blocking (D-006 settled model and auto-merge).
