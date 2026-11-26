#!/bin/sh
# One low-priority worker; the durable database cursor survives process termination.
set -eu
import_php=${1:?Pass the server PHP interpreter path}
import_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$import_root"
mkdir -p storage/framework/imports
exec 9>storage/framework/imports/worker.lock
flock -n 9 || exit 0
import_child=''
trap 'if [ -n "$import_child" ]; then kill "$import_child" 2>/dev/null || true; fi; exit 0' INT TERM
while :; do
    XDEBUG_MODE=off PGCONNECT_TIMEOUT=3 PGOPTIONS='-c statement_timeout=5000 -c lock_timeout=1000' \
        timeout 30 nice -n 10 "$import_php" -d memory_limit=128M -d zend.exception_ignore_args=1 \
        codex sis:imports:run --limit=2 &
    import_child=$!
    wait "$import_child" || true
    import_child=''
    sleep 15 &
    import_child=$!
    wait "$import_child" || true
    import_child=''
done
