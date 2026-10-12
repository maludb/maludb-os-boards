#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the two shares, app_roles, the gate, the walls, the SVG dialect, the registry: tests/phase4/run.sh
#   BD_APP=apache tests/phase4/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh) — then the proofs also reach the servers through the vhost's own /mcp/records and /mcp/activity lines.
# A fresh SCRATCH database (bd_dev40 — never the installed one), the application on :9101 (php -S, or the Apache vhost; :9107 is then the internal one), a fake kernel (:9102 — run-facts, the chat door), a fake MaluDB + MaluMail (:9103),
# and the servers under test on :9104 (records) and :9105 (activity). The fail-closed proofs stop the fake kernel for a moment. NOTHING REAL IS TOUCHED: no live service, Apache, unit, database or role password but the three scratch-database roles'.
#   BD_PROOFS="gate walls" tests/phase4/run.sh   runs some, in order (the world is built by the first that needs it).  BD_KEEP=1 leaves the servers running; BD_STOP=1 stops them.
# Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh). Never run it beside another suite (they share 9101–9107). Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev40} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev40.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev40-state}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
stop_all() { "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; }
if [ "${BD_STOP:-0}" = 1 ]; then export BD_DEV_ENV BD_DEV_STATE; stop_all; echo stopped; exit 0; fi
for port in 9101 9102 9103 9104 9105 9107; do for _ in $(seq 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done; (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
rm -rf "$BD_DEV_STATE"; mkdir -p "$BD_DEV_STATE"
cd "$ROOT"
status=0
echo "== the bundle"; php bin/check_bundle.php || status=1
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
cleanup() {
  [ "${BD_KEEP:-0}" = 1 ] && { echo "== left running (BD_KEEP=1): BD_STOP=1 bash $0 stops them"; return; }
  stop_all
  sudo -n -u postgres dropdb --if-exists "$BD_DEV_DB" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json" FAKE_MALUMAIL_LOG="$BD_DEV_STATE/malumail.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
echo "== world"; php tests/phase4/world.php || status=1
# after servers.sh (its start stops every pid file in the state directory): the MCP servers start on the finished world
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
: > "$BD_DEV_STATE/p4-called.txt"
proofs=${BD_PROOFS-gate walls tools shares activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
logs=$(ls "$BD_DEV_STATE/app.log" "$BD_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
if grep -q -i 'traceback' "$BD_DEV_STATE"/mcp-*.log 2>/dev/null; then echo "  FAIL a server logged a traceback:"; grep -h -i -m3 -B2 -A8 'traceback' "$BD_DEV_STATE"/mcp-*.log | head -30; status=1; else echo "  ok   no traceback in the servers' logs"; fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
