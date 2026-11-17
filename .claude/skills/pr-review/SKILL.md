---
name: pr-review
description: "Run the Takes PR review: three specialist reviewers plus a judge that scores, labels and auto-merges (spec 1-03). Use when a PR is open with green CI and needs review, when a review round finished with MERGEABLE AFTER NITS or NOT MERGEABLE and the author must respond, or when deciding whether to re-run or escalate. Covers launching .claude/workflows/pr-review.js with the Workflow tool, the args it takes, how authors answer findings, and the 3-round limit."
---

# PR review and judge (spec 1-03)

Every PR in `JVillator0/takes` is reviewed by `.claude/workflows/pr-review.js`: up to three specialist reviewers (one GitHub review each), then the `pr-judge` scorecard, a `verdict:*` label and, on MERGEABLE, a squash auto-merge. The lead's Claude Code session runs it; it does not run in GitHub Actions.

## Before running

1. The PR was opened with the template (Summary · Why · Spec section · Changes · Money impact · Test evidence · Screenshots · Risks · Checklist), a Conventional Commit title and `Closes #N`. Bootstrap exception (milestones M0–M1, before the GitHub Project issues existed): `Refs spec <n-nn>` is accepted as the link; the judge says so in the PR body gate note.
2. Money or webhook PR: the author ticked "I can explain every line of this PR". Without it the judge fails the gate.
3. CI finished: `gh pr checks <n> --watch`. Red CI ⇒ the author fixes it first (the workflow still runs on red CI, but the verdict is NOT MERGEABLE).
4. Push everything first. The workflow records the reviewed head SHA; a push after the review makes the merge step refuse.

## Running it

Call the **Workflow** tool with:

```json
{
  "scriptPath": "<repo>/.claude/workflows/pr-review.js",
  "args": {
    "pr": 12,
    "repo": "JVillator0/takes",
    "cwd": "<repo>"
  }
}
```

| arg | required | meaning |
|---|---|---|
| `pr` | yes | PR number |
| `repo` | no (default `JVillator0/takes`) | `owner/name` |
| `cwd` | yes | `<repo>`: absolute path of a clean checkout kept at `origin/main` (the lead keeps one next to the worktrees, e.g. `../takes-review`, and runs `git pull --ff-only` there before a review). Agents read `.claude/agents/`, `.claude/rules/` and `.claude/specs/` from it. The Context step fetches the PR's head and base commits into it once; the other agents only run `git diff` and `git show` and never check out branches. |
| `round` | no | 1–3 (4 only with `escalation`). Default: number of earlier judge comments + 1. Above the limit the workflow stops. |
| `escalation` | no | `"D-075"` only, and only for round 4: the lead's one authorised extra round. |
| `judgeOnly` | no | `true` to skip the reviewers on a re-check after **MERGEABLE AFTER NITS**. Ignored when the last verdict was NOT MERGEABLE (that needs a full run). Docs-only and generated-only PRs are always judge-only. |

`<repo>` is always an absolute path. A harness PR that changes the script itself is reviewed by running the script from its own branch (it does not exist on `main` yet): point `scriptPath` at that worktree's copy, and `cwd` at the same worktree so the agents read the definitions under review.

### What it does

1. **Context**: PR metadata, head SHA, changed files, CI (waits up to ~9 min for pending checks), linked issue, spec file. Files are routed by path:
   - `laravel-backend-reviewer`: `app/` except `app/Filament`, `database/`, `routes/`, `config/`, `tests/`
   - `inertia-vue-reviewer`: `resources/js/`, `resources/css/`, `vite.config.*`
   - `filament-reviewer`: `app/Filament/`, `app/Providers/Filament/`, `lang/*/console.php`
   - docs (`*.md`, `docs/`, `.claude/specs/`, images) and generated files (lock files, Wayfinder output, `public/build/`) go to no reviewer; anything else is judged directly by the judge.
2. **Review**: each reviewer with files reads its definition in `.claude/agents/` (inline fallback checklist if missing), reviews only its own diff and returns `{reviewer, summary, findings}` (reviewers are read-only). The workflow renders that into **one** `COMMENT` review with inline comments, headed `<Name> · automated reviewer (Claude Opus 5.5)`, and a small post step publishes it (findings on lines outside the diff move into the review body). Reviewers without files are N/A; a reviewer that fails is reported as failed and the judge reviews its files.
3. **Judge**: `pr-judge` scores the 9 dimensions, checks the gates and returns findings; the script recomputes the verdict (it can only get stricter), numbers findings #1…#n, posts the scorecard comment and sets the label. On MERGEABLE it re-reads the head SHA and checks and runs `gh pr merge --squash --auto --delete-branch --match-head-commit <sha>`, falling back to a direct squash merge (same `--match-head-commit`) when enabling auto-merge fails: "clean status", "Protected branch rules not configured for this branch" or "Auto merge is not allowed for this repository"; the outcome is read back from `gh pr view --json state,autoMergeRequest`. If the SHA moved it refuses and says so.

It returns `{ verdict, score, merged, autoMergeEnabled, outcome, decision, commentUrl, round, reviewedSha, reviewers, findings }`.

## Responding to findings (author)

- Fix in **new commits**. Never amend, rebase or force-push a PR under review: the threads must keep pointing at real commits.
- Reply on **every** review thread, then resolve it:
  - `Fixed in abc1234` (short SHA of the commit with the fix; add the test that proves it when behaviour changed), or
  - `Won't fix: <reason>` (the judge decides whether to accept it).
- Pre-existing findings do not block the PR; open a follow-up issue if they matter.
- Push, wait for green CI, then re-run:
  - after **MERGEABLE AFTER NITS**: `judgeOnly: true` (round increments automatically);
  - after **NOT MERGEABLE**: full run.
- **Max 3 rounds.** If round 3 is not MERGEABLE, the lead decides (D-075): fix the important findings, comment the decision on the PR, run round 4 with `escalation: "D-075"`; minors left after it go to a follow-up issue.

## After the merge

The issue closes through `Closes #N`. The author's worktree is released as described in the `agent-worktrees` skill (spec 1-04).

## Labels

`verdict:mergeable`, `verdict:nits`, `verdict:not-mergeable` exist in the repo; the workflow keeps at most one of them on the PR. `verdict:mergeable` is applied only when the PR was merged or auto-merge was enabled; when a MERGEABLE verdict is refused at merge time (head moved, CI not green, merge error) every `verdict:*` label is removed, because the PR needs a re-run. Create them again (idempotent) with:

```bash
gh label create verdict:mergeable --color 0e8a16 --description "Judge: MERGEABLE" --force -R JVillator0/takes
gh label create verdict:nits --color fbca04 --description "Judge: MERGEABLE AFTER NITS" --force -R JVillator0/takes
gh label create verdict:not-mergeable --color d93f0b --description "Judge: NOT MERGEABLE" --force -R JVillator0/takes
```

## Tests of the workflow script

The gate logic (verdict thresholds, score validation, CI derivation, findings numbering, PR body checks) and whole runs with stubbed agents are covered by `node:test` (Node 22+), outside CI because the harness CI is PHP-first:

```bash
node --test '.claude/tests/*.test.mjs'
```

Run it after any change to `.claude/workflows/pr-review.js`. The tests evaluate the script's pure helpers up to the `// ---- end of pure helpers` marker, so keep helpers above it.

## Troubleshooting

- **"Head moved … re-run the workflow"**: someone pushed after the review. Re-run (same round number is fine: pass `round`).
- **Merge refused, CI still pending**: wait for CI, then re-run with `judgeOnly: true` and the same `round`.
- **A reviewer agent failed**: the judge reviews that reviewer's files itself and the scorecard says so. Resume with `resumeFromRunId` to retry only what failed.
- **Duplicate comments**: reviewers and the judge mark their posts with `<!-- pr-review:… sha=… round=… -->`; a re-run for the same SHA and round edits instead of posting again.

## Escalation after round 3 (D-075)

The lead (not Javier) decides. Fix the open important/critical findings, comment the decision on the PR, then run the workflow once more with `args: { pr, repo, cwd, round: 4, escalation: "D-075" }`. Round 5 is refused. Minors left after round 4 become a follow-up issue.
