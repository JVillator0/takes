<!--
Title: a Conventional Commit, e.g. `feat(billing): charge cost + 25% on completion`.
The squash merge keeps it as the commit message (D-039).
-->

## Summary

<!-- One or two sentences: what changes for the user or the codebase. -->

## Why

<!-- The problem or decision this answers. Link the issue: Closes #N -->

## Spec section

<!-- e.g. `.claude/specs/3-domain-foundation/03-SPEC.md` § Ledger, plus any D-NNN decisions. -->

## Changes

-

## Money impact

<!-- None, or: which amounts change, how they stay integer nanodollars, and the test that proves it. -->

## Test evidence

<!-- Commands run and their result (composer check, npm run types, npm run check), new tests. -->

## Screenshots

<!-- UI changes only; otherwise "n/a". -->

## Risks

<!-- What could break, how it is contained, how to roll back. -->

## Checklist

- [ ] PR title is a Conventional Commit
- [ ] Linked issue and spec section
- [ ] `composer check` passes locally
- [ ] `npm run check` and `npm run types` pass locally
- [ ] Tests added or updated for the behavior change
- [ ] No secrets, `.env` or credentials committed
- [ ] I can explain every line of this PR (mandatory on money/webhook PRs, spec 1-03)
