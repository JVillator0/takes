#!/usr/bin/env bash
#
# Prepare one checkout of the worktree pool (spec 1-04): .env, databases, Valet.
#
#   scripts/worktree-env.sh <a|b|c|d|e|main> [--with-secrets] [--no-db] [--no-valet] [--force]
#
# Slot   URL                    DB / test DB                 Reverb  Vite
# main   https://takes.test     takes / takes_testing          8080  5173
# a..e   https://takes-<l>.test takes_<l> / takes_testing_<l>  8080+i 5173+i  (a=1 ... e=5)
#
# Idempotent: keeps the existing .env (APP_KEY, secrets) and only rewrites the
# slot-derived keys; databases, Valet links and certificates are created only
# when missing. It never copies secrets from another checkout; --with-secrets
# runs scripts/env-from-1password.sh local (D-004). It refuses to run when the
# checkout directory does not match the slot (takes, takes-<l>) unless --force.
#
# Environment overrides: TAKES_PG_PORT (default 5417), TAKES_PG_BIN (default:
# Homebrew postgresql@17, then PATH), VALET_HOME (default ~/.config/valet).

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=scripts/lib/dotenv.sh
. "$ROOT/scripts/lib/dotenv.sh"
PG_PORT=${TAKES_PG_PORT:-5417}
PG_BIN=${TAKES_PG_BIN:-/opt/homebrew/opt/postgresql@17/bin}
VALET_HOME=${VALET_HOME:-$HOME/.config/valet}

usage() {
    sed -n '3,5p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

info() { printf '  %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*" >&2; }

get_env_key() {
    sed -n "s/^$2=//p" "$1" | tail -n 1
}

pg_tool() {
    if [[ -x "$PG_BIN/$1" ]]; then
        printf '%s' "$PG_BIN/$1"
    else
        command -v "$1" || true
    fi
}

slot=""
with_secrets=0
do_db=1
do_valet=1
force=0
while (($#)); do
    case $1 in
        --with-secrets) with_secrets=1 ;;
        --no-db) do_db=0 ;;
        --no-valet) do_valet=0 ;;
        --force) force=1 ;;
        -h | --help) usage; exit 0 ;;
        -*) warn "Unknown option: $1"; usage; exit 64 ;;
        *)
            if [[ -n $slot ]]; then usage; exit 64; fi
            slot=$1
            ;;
    esac
    shift
done

case $slot in
    main)
        index=0
        site=takes
        db=takes
        test_db=takes_testing
        ;;
    a | b | c | d | e)
        index=$(($(printf '%d' "'$slot") - 96))
        site="takes-$slot"
        db="takes_$slot"
        test_db="takes_testing_$slot"
        ;;
    *)
        usage
        exit 64
        ;;
esac

vite_port=$((5173 + index))
reverb_port=$((8080 + index))
app_url="https://$site.test"

echo "Slot $slot -> $app_url (checkout: $ROOT)"
if [[ $(basename "$ROOT") != "$site" ]]; then
    if ((!force)); then
        warn "This checkout is '$(basename "$ROOT")', but slot '$slot' expects '$site'. Use --force to continue."
        exit 1
    fi
    warn "This checkout is '$(basename "$ROOT")', but slot '$slot' expects '$site'. Continuing (--force)."
fi

cd "$ROOT"

# 1. .env -------------------------------------------------------------------
echo ".env"
if [[ ! -f .env ]]; then
    cp .env.example .env
    info "created from .env.example"
fi

# Keys added to .env.example since this .env was created are appended with their defaults.
ensure_trailing_newline .env
while IFS= read -r line; do
    key=${line%%=*}
    if ! grep -q "^$key=" .env; then
        printf '%s\n' "$line" >>.env
        info "added $key from .env.example"
    fi
done < <(grep -E '^[A-Z][A-Z0-9_]*=' .env.example)

set_env_key .env APP_URL "$app_url"
set_env_key .env DB_CONNECTION pgsql
set_env_key .env DB_HOST 127.0.0.1
set_env_key .env DB_PORT "$PG_PORT"
set_env_key .env DB_DATABASE "$db"
set_env_key .env DB_USERNAME "$(whoami)"
set_env_key .env DB_PASSWORD ""
set_env_key .env VITE_PORT "$vite_port"
# Reverb binds on REVERB_SERVER_PORT; REVERB_PORT is the port clients connect to.
# Locally there is no proxy in front of Reverb, so both are the slot's port.
set_env_key .env REVERB_SERVER_PORT "$reverb_port"
set_env_key .env REVERB_PORT "$reverb_port"
info "APP_URL=$app_url DB_DATABASE=$db DB_PORT=$PG_PORT VITE_PORT=$vite_port REVERB_SERVER_PORT=REVERB_PORT=$reverb_port"

if [[ -z $(get_env_key .env APP_KEY) ]]; then
    if command -v php >/dev/null; then
        app_key=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
    else
        app_key="base64:$(openssl rand -base64 32)"
    fi
    set_env_key .env APP_KEY "$app_key"
    info "generated APP_KEY"
fi
chmod 600 .env

# 2. Databases --------------------------------------------------------------
if ((do_db)); then
    echo "Databases (Postgres on port $PG_PORT)"
    psql_bin=$(pg_tool psql)
    createdb_bin=$(pg_tool createdb)
    if [[ -z $psql_bin || -z $createdb_bin ]]; then
        warn "psql/createdb not found; skipping. Install Postgres 17 or set TAKES_PG_BIN."
    elif ! "$psql_bin" -p "$PG_PORT" -d postgres -tAc 'select 1' >/dev/null 2>&1; then
        warn "Postgres is not reachable on port $PG_PORT; skipping. Start it or set TAKES_PG_PORT."
    else
        for name in "$db" "$test_db"; do
            exists=$("$psql_bin" -p "$PG_PORT" -d postgres -tAc "select 1 from pg_database where datname = '$name'")
            if [[ $exists == 1 ]]; then
                info "$name exists"
            else
                "$createdb_bin" -p "$PG_PORT" "$name"
                info "$name created"
            fi
        done
    fi
fi

# 3. Valet ------------------------------------------------------------------
if ((do_valet)); then
    echo "Valet"
    if ! command -v valet >/dev/null; then
        info "valet not installed; skipping (serve with 'php artisan serve' or Sail)"
    else
        if [[ $(readlink "$VALET_HOME/Sites/$site" 2>/dev/null || true) == "$ROOT" ]]; then
            info "$site already linked"
        else
            valet link "$site"
        fi
        if [[ -f "$VALET_HOME/Certificates/$site.test.crt" ]]; then
            info "$site.test already secured"
        else
            valet secure "$site"
        fi
    fi
fi

# 4. Secrets (opt-in) -------------------------------------------------------
if ((with_secrets)); then
    echo "Secrets"
    "$ROOT/scripts/env-from-1password.sh" local
fi

cat <<EOF

Next steps in $ROOT:
  composer install && npm ci
  php artisan migrate --seed
  npm run dev -- --port $vite_port --strictPort
  scripts/test.sh                      # Pest against $test_db
  open $app_url
Lease this worktree before working in it (see .claude/worktree-pool.md):
  git worktree lock --reason "<agent>: <issue> ($(date +%F))" .
EOF
if ((!with_secrets)); then
    echo "Provider keys are not set; re-run with --with-secrets only if this checkout needs the real API."
fi
