#!/usr/bin/env bash
#
# Run Pest against this checkout's own test database (spec 1-04).
#
#   scripts/test.sh [pest arguments...]
#
# takes-<l>  ->  DB_DATABASE=takes_testing_<l>
# takes      ->  DB_DATABASE=takes_testing (same as phpunit.xml and CI)
# other      ->  phpunit.xml's default (takes_testing), e.g. a clone under another name
#
# The exported DB_DATABASE overrides phpunit.xml's default, so pool checkouts
# never share a test database. Only one full suite at a time across the pool.

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
checkout=$(basename "$ROOT")

case $checkout in
    takes) test_db=takes_testing ;;
    takes-[a-z]) test_db="takes_testing_${checkout#takes-}" ;;
    *) test_db="" ;;
esac

cd "$ROOT"
if [[ -n $test_db ]]; then
    export DB_DATABASE=$test_db
    echo "Pest on $DB_DATABASE" >&2
else
    echo "Checkout '$checkout' is not in the pool; Pest uses phpunit.xml's database." >&2
fi
exec vendor/bin/pest "$@"
