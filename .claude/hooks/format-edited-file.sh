#!/usr/bin/env bash
# PostToolUse hook (Edit|MultiEdit|Write): format the file Claude just changed.
#   *.php           -> vendor/bin/pint <file>
#   *.vue|*.ts|*.tsx|*.js -> vite-plus formatter (vp fmt <file>, oxfmt; the repo has no Prettier)
# Never fails the tool call: missing vendor/ or node_modules/, a file outside the
# project, or a formatter error all end with exit 0.
set -u

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$project_dir" 2>/dev/null || exit 0

payload="$(cat)"
file=""
if command -v jq >/dev/null 2>&1; then
    file="$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty' 2>/dev/null)"
elif command -v node >/dev/null 2>&1; then
    file="$(printf '%s' "$payload" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{process.stdout.write(JSON.parse(s).tool_input?.file_path??"")}catch{}})' 2>/dev/null)"
fi

[ -n "$file" ] || exit 0
[ -f "$file" ] || exit 0

# Only files inside this project, never dependencies or generated code.
case "$file" in
    "$project_dir"/*) ;;
    /*) exit 0 ;;
    *) file="$project_dir/$file" ;;
esac
case "$file" in
    */vendor/* | */node_modules/* | */public/build/* | */bootstrap/ssr/*) exit 0 ;;
    */resources/js/actions/* | */resources/js/routes/* | */resources/js/wayfinder/*) exit 0 ;;
esac

case "$file" in
    *.php)
        if [ -x vendor/bin/pint ]; then
            vendor/bin/pint --quiet "$file" >/dev/null 2>&1 || true
        fi
        ;;
    *.vue | *.ts | *.tsx | *.js | *.mjs)
        if [ -x node_modules/.bin/vp ]; then
            node_modules/.bin/vp fmt --no-error-on-unmatched-pattern "$file" >/dev/null 2>&1 || true
        fi
        ;;
esac

exit 0
