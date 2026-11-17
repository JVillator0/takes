#!/usr/bin/env bash
#
# Write the provider secrets into a dotenv file without printing them (D-004).
#
#   scripts/env-from-1password.sh <local|production> [--env-file <path>]
#
# Target: .env for local, .env.production for production (both git-ignored),
# or --env-file. It is created from .env.example when missing. Only the keys in
# SECRET_KEYS are written; existing lines are replaced in place, every other
# line is left untouched, and a key with no available value keeps its line.
#
# Source of the values, in this order:
#   1. TAKES_KEYSTORE_DIR set: read <dir>/<env>.env (production also accepts
#      prod.env), a plain KEY=value file. It is parsed, not executed.
#   2. Otherwise: 1Password through `op read`, with the secret references taken
#      from scripts/secrets.local.env (git-ignored; copy the .example next to
#      it). A pool worktree falls back to the main checkout's copy.
#      Reference names: <ENV>_<KEY>_REF, e.g. LOCAL_PIXELFORGE_API_KEY_REF.
#
# Vault names, item names, references and keystore paths never go into git.

set -euo pipefail

SECRET_KEYS="PIXELFORGE_API_KEY PIXELFORGE_WEBHOOK_SECRET ANTHROPIC_API_KEY"

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

usage() {
    sed -n '3,5p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

info() { printf '  %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*" >&2; }

# Replace KEY=... in place (first occurrence; later duplicates are dropped) or append it.
# The value travels through the environment, never through argv.
set_env_key() {
    local file=$1 key=$2 value=$3 tmp
    tmp=$(mktemp "$file.XXXXXX")
    KEY=$key VALUE=$value awk '
        BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; done = 0 }
        $0 ~ "^[[:space:]]*(export[[:space:]]+)?" key "=" {
            if (!done) { print key "=" value; done = 1 }
            next
        }
        { print }
        END { if (!done) print key "=" value }
    ' "$file" >"$tmp"
    mv "$tmp" "$file"
}

# Print the value of KEY from a dotenv file (last occurrence; surrounding quotes removed).
# Exits 1 when the key is absent.
read_dotenv_value() {
    KEY=$2 awk '
        {
            line = $0
            sub(/\r$/, "", line)
            sub(/^[[:space:]]*export[[:space:]]+/, "", line)
            prefix = ENVIRON["KEY"] "="
            if (index(line, prefix) == 1) { value = substr(line, length(prefix) + 1); found = 1 }
        }
        END {
            if (!found) exit 1
            if (value ~ /^".*"$/ || value ~ /^\x27.*\x27$/) value = substr(value, 2, length(value) - 2)
            printf "%s", value
        }
    ' "$1"
}

# Quote a value so phpdotenv reads it back literally.
dotenv_quote() {
    local value=$1
    case $value in
        *[!A-Za-z0-9_./:+=@-]*) ;;
        *) printf '%s' "$value"; return ;;
    esac
    if [[ $value != *"'"* ]]; then
        printf "'%s'" "$value"
        return
    fi
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//\$/\\\$}
    printf '"%s"' "$value"
}

env_name=""
env_file=""
while (($#)); do
    case $1 in
        --env-file)
            env_file=${2:?--env-file needs a path}
            shift
            ;;
        -h | --help) usage; exit 0 ;;
        -*) warn "Unknown option: $1"; usage; exit 64 ;;
        *)
            if [[ -n $env_name ]]; then usage; exit 64; fi
            env_name=$1
            ;;
    esac
    shift
done

case $env_name in
    local) default_target="$ROOT/.env" ;;
    production) default_target="$ROOT/.env.production" ;;
    *) usage; exit 64 ;;
esac
target=${env_file:-$default_target}
env_prefix=$(printf '%s' "$env_name" | tr '[:lower:]' '[:upper:]')

# Resolve the source before touching the target.
if [[ -n ${TAKES_KEYSTORE_DIR:-} ]]; then
    source_mode=keystore
    keystore_file="$TAKES_KEYSTORE_DIR/$env_name.env"
    if [[ ! -f $keystore_file && $env_name == production ]]; then
        keystore_file="$TAKES_KEYSTORE_DIR/prod.env"
    fi
    if [[ ! -f $keystore_file ]]; then
        echo "No $env_name keystore file in TAKES_KEYSTORE_DIR." >&2
        exit 1
    fi
    echo "Secrets for $env_name from the keystore directory -> $target"
else
    source_mode=op
    refs_file="$ROOT/scripts/secrets.local.env"
    if [[ ! -f $refs_file ]]; then
        main_root=$(git -C "$ROOT" worktree list --porcelain 2>/dev/null | sed -n '1s/^worktree //p' || true)
        if [[ -n $main_root && -f "$main_root/scripts/secrets.local.env" ]]; then
            refs_file="$main_root/scripts/secrets.local.env"
        fi
    fi
    if [[ ! -f $refs_file ]]; then
        echo "scripts/secrets.local.env not found: copy scripts/secrets.local.env.example and fill in the references," >&2
        echo "or set TAKES_KEYSTORE_DIR." >&2
        exit 1
    fi
    if ! command -v op >/dev/null; then
        echo "The 1Password CLI (op) is not installed." >&2
        exit 1
    fi
    echo "Secrets for $env_name from 1Password -> $target"
fi

if [[ ! -f $target ]]; then
    cp "$ROOT/.env.example" "$target"
    info "created $(basename "$target") from .env.example"
fi
chmod 600 "$target"

written=0
for key in $SECRET_KEYS; do
    value=""
    if [[ $source_mode == keystore ]]; then
        value=$(read_dotenv_value "$keystore_file" "$key" || true)
    else
        ref=$(read_dotenv_value "$refs_file" "${env_prefix}_${key}_REF" || true)
        if [[ -z $ref || $ref == *"<"* ]]; then
            warn "$key skipped: ${env_prefix}_${key}_REF is not set"
            continue
        fi
        if ! value=$(op read --no-newline "$ref"); then
            echo "op read failed for $key (signed in to 1Password?)." >&2
            exit 1
        fi
    fi
    if [[ -z $value ]]; then
        warn "$key skipped: no value available"
        continue
    fi
    set_env_key "$target" "$key" "$(dotenv_quote "$value")"
    info "$key written"
    written=$((written + 1))
done
chmod 600 "$target"

echo "$written secret(s) written; values are never printed."
