#!/usr/bin/env bash
# Stop hook: when PHP files under app/ differ from origin/main (committed, staged,
# unstaged or untracked) and that set of changes was not analysed yet, run
# `composer stan` (Larastan) with a 60 s budget and show a non-blocking hint if it
# reports errors. CI is the source of truth; this hook never blocks Claude from
# stopping (always exit 0).
set -u

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$project_dir" 2>/dev/null || exit 0
cat >/dev/null # discard the hook payload

command -v git >/dev/null 2>&1 || exit 0
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0
[ -d vendor ] || exit 0

# Compare with the merge base of origin/main, so PHP committed during the turn
# (WIP commits in pool worktrees) is still analysed. Fall back to HEAD.
base="$(git merge-base origin/main HEAD 2>/dev/null || git rev-parse HEAD 2>/dev/null)"
[ -n "$base" ] || exit 0

# Fingerprint of every PHP change under app/ since the base, plus untracked files.
changes="$( {
    git diff "$base" -- 'app/*.php' 'app/**/*.php' 2>/dev/null
    git ls-files --others --exclude-standard -- 'app/' 2>/dev/null | grep '\.php$' | while read -r f; do
        printf '%s\n' "$f"
        cat "$f" 2>/dev/null
    done
} )"
[ -n "$changes" ] || exit 0

if command -v shasum >/dev/null 2>&1; then
    fingerprint="$(printf '%s' "$changes" | shasum -a 256 | cut -d' ' -f1)"
else
    fingerprint="$(printf '%s' "$changes" | sha256sum | cut -d' ' -f1)"
fi
# Stored per worktree inside its git dir: never committed, never shared.
state_file="$(git rev-parse --git-dir)/claude-stan-fingerprint"
[ "$(cat "$state_file" 2>/dev/null)" = "$fingerprint" ] && exit 0
printf '%s' "$fingerprint" >"$state_file" 2>/dev/null

if grep -q '"stan"' composer.json 2>/dev/null; then
    cmd=(composer stan --no-interaction -- --no-progress --error-format=raw)
elif [ -x vendor/bin/phpstan ]; then
    cmd=(vendor/bin/phpstan analyse --no-progress --error-format=raw)
else
    exit 0
fi

# Print {"systemMessage": "..."}: Claude Code shows it to the user without blocking.
hint() {
    if command -v jq >/dev/null 2>&1; then
        jq -cn --arg m "$1" '{systemMessage: $m}'
    elif command -v node >/dev/null 2>&1; then
        node -e 'process.stdout.write(JSON.stringify({systemMessage: process.argv[1]}) + "\n")' "$1"
    fi
}

# laravel/pao rewrites PHPStan output as JSON when it detects an agent; PAO_DISABLE
# keeps the raw "path:line:message" format parsed below.
out="$(mktemp -t claude-stan.XXXXXX)"
err="$(mktemp -t claude-stan-err.XXXXXX)"
PAO_DISABLE=1 "${cmd[@]}" >"$out" 2>"$err" &
pid=$!
elapsed=0
while kill -0 "$pid" 2>/dev/null && [ "$elapsed" -lt 60 ]; do
    sleep 1
    elapsed=$((elapsed + 1))
done

if kill -0 "$pid" 2>/dev/null; then
    pkill -P "$pid" 2>/dev/null
    kill "$pid" 2>/dev/null
    rm -f "$out" "$err"
    hint "Larastan did not finish in 60 s; run composer stan before pushing."
    exit 0
fi

wait "$pid"
status=$?
if [ "$status" -ne 0 ]; then
    # Raw format: one "path:line:message" per error.
    count="$(grep -cE ':[0-9]+:' "$out" 2>/dev/null)" || true
    if [ "${count:-0}" -gt 0 ]; then
        first="$(grep -m 3 -E ':[0-9]+:' "$out" | sed "s|$project_dir/||" | tr '\n' ' ')"
        hint "Larastan reported ${count} issue(s); run composer stan. ${first}"
    else
        # No error lines: PHPStan or composer itself failed (config, memory, missing script).
        reason="$(cat "$err" "$out" 2>/dev/null | grep -vE '^[[:space:]]*$|^Note:' | head -n 3 | sed "s|$project_dir/||" | tr '\n' ' ' | tr -s ' ' | cut -c 1-300)"
        hint "Larastan failed (exit ${status}); run composer stan. ${reason}"
    fi
fi
rm -f "$out" "$err"
exit 0
