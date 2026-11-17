#!/usr/bin/env bash
# Stop hook: when PHP files under app/ changed since the last run, run `composer stan`
# (Larastan) with a 60 s budget and show a non-blocking hint if it reports errors.
# CI is the source of truth; this hook never blocks Claude from stopping (always exit 0).
set -u

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$project_dir" 2>/dev/null || exit 0
cat >/dev/null # discard the hook payload

command -v git >/dev/null 2>&1 || exit 0
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0
[ -d vendor ] || exit 0

# Fingerprint of uncommitted and untracked PHP changes under app/.
changes="$( { git diff HEAD -- 'app/*.php' 'app/**/*.php' 2>/dev/null; git ls-files --others --exclude-standard -- 'app/' 2>/dev/null | grep '\.php$' | while read -r f; do printf '%s\n' "$f"; cat "$f" 2>/dev/null; done; } )"
[ -n "$changes" ] || exit 0

if command -v shasum >/dev/null 2>&1; then
    fingerprint="$(printf '%s' "$changes" | shasum -a 256 | cut -d' ' -f1)"
else
    fingerprint="$(printf '%s' "$changes" | sha256sum | cut -d' ' -f1)"
fi
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

out="$(mktemp -t claude-stan.XXXXXX)"
"${cmd[@]}" >"$out" 2>&1 &
pid=$!
elapsed=0
while kill -0 "$pid" 2>/dev/null && [ "$elapsed" -lt 60 ]; do
    sleep 1
    elapsed=$((elapsed + 1))
done

if kill -0 "$pid" 2>/dev/null; then
    pkill -P "$pid" 2>/dev/null
    kill "$pid" 2>/dev/null
    rm -f "$out"
    hint "Larastan did not finish in 60 s; run composer stan before pushing."
    exit 0
fi

wait "$pid"
status=$?
if [ "$status" -ne 0 ]; then
    # PHPStan prints JSON when it detects an agent environment, raw lines otherwise.
    count=""
    first=""
    if command -v jq >/dev/null 2>&1 && jq -e '.error_details' "$out" >/dev/null 2>&1; then
        count="$(jq -r '.errors // empty' "$out")"
        first="$(jq -r --arg root "$project_dir/" '[.error_details | to_entries[] | .key as $f | .value[] | "\($f | ltrimstr($root)):\(.line) \(.message)"] | .[0:3] | join(" | ")' "$out")"
    else
        count="$(grep -c ':[0-9]*:' "$out" 2>/dev/null)" || true
        first="$(grep -m 3 ':[0-9]*:' "$out" 2>/dev/null | sed "s|$project_dir/||" | tr '\n' ' ')"
    fi
    hint "Larastan reported ${count:-some} issue(s); run composer stan. ${first}"
fi
rm -f "$out"
exit 0
