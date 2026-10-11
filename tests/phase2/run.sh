#!/usr/bin/env bash
# Runs every Phase 2 proof (docs/build-specs/sso-shell.md "Proof") on a fresh SCRATCH database (never an installed one): tests/phase2/run.sh
#   BD_APP=apache tests/phase2/run.sh   serves the app from a real Apache (as you, prefork + mod_php) with the rendered deploy vhost instead of php -S
#   needs: `sudo -n -u postgres` (the owner's standing exception: scratch bd_* databases and the boards_* roles), php, node with the kernel's
#   web/node_modules (Playwright + its Chromium, read only). Screenshots go to $SHOTS (default /tmp/bd-shots). Ends with one line per proof
#   and exit status 0 only if all passed. Ports 9101 (the app), 9102 (the fake kernel), 9103 (the fake MaluDB + MaluMail), 9107 (Apache's
#   internal vhost). Phase 0's kit proof uses the same ports: run them one after the other.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev_p2} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev-p2.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev-p2-state} SHOTS=${SHOTS:-/tmp/bd-shots}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9107; do for _ in $(seq 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done; (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done  # a port the previous suite just freed gets 15 s
mkdir -p "$SHOTS"
rm -rf "$BD_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
echo "== the schema (db/proof/phase0_proof.sql on the scratch database, rolled back)"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$BD_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
if echo "$summary" | grep -qE ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$BD_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$BD_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
echo "== browser"; node tests/phase2/browser.mjs || status=1
for p in shell tokens guard picker switcher vhost; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
echo "== the installer"
plan=$(env -i PATH="$PATH" HOME="$HOME" php /var/www/bin/app_install.php plan "$ROOT" 2>&1); rc=$?      # the kernel's own environment, never the scratch one
if [ $rc -eq 0 ] && ! echo "$plan" | grep -qiE '^(error|refused|fail|warn)'; then echo "  ok   the kernel's installer plans Boards cleanly ($(echo "$plan" | grep -E '^Plan: ' | head -1))"; else echo "  FAIL the installer's plan: $(echo "$plan" | grep -iE '^(error|refused|fail|warn)' | head -3)"; status=1; fi
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
