# shellcheck shell=bash
#
# Dotenv helpers shared by scripts/worktree-env.sh and scripts/env-from-1password.sh.
# Source it; do not execute it. Compatible with macOS's bash 3.2.
#
# Installs an EXIT trap that removes a half-written temp copy of the target
# file (it may hold secrets) when the script fails or is interrupted.

DOTENV_TMP=""
trap 'if [[ -n $DOTENV_TMP ]]; then rm -f "$DOTENV_TMP"; fi' EXIT
trap 'exit 130' INT TERM

# Replace KEY=... in place (first occurrence; later duplicates are dropped) or append it.
# The value travels through the environment, never through argv.
set_env_key() {
    local file=$1 key=$2 value=$3
    DOTENV_TMP=$(mktemp "$file.XXXXXX")
    KEY=$key VALUE=$value awk '
        BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; done = 0 }
        $0 ~ "^[[:space:]]*(export[[:space:]]+)?" key "=" {
            if (!done) { print key "=" value; done = 1 }
            next
        }
        { print }
        END { if (!done) print key "=" value }
    ' "$file" >"$DOTENV_TMP"
    mv "$DOTENV_TMP" "$file"
    DOTENV_TMP=""
}

# Make sure the file ends with a newline, so a later `>>` append starts on its own line.
ensure_trailing_newline() {
    if [[ -s $1 && -n $(tail -c 1 "$1") ]]; then
        printf '\n' >>"$1"
    fi
}
