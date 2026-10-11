#!/usr/bin/env bash
# Slice 5's proofs (docs/build-specs/pen-files.md "Proof") on a fresh SCRATCH database bd_dev5 with a scratch store: tests/phase3/slice5/run.sh
#   BD_APP=apache tests/phase3/slice5/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh) — its
#   32 MB body limit and PHP limits; there the browser proof uploads and draws without the test hook and asserts on the database.
# Live updates are off here (no relay). Then slice 4's suite re-runs — and with it 3, 2, 1 and Phase 2 (BD_SKIP_EARLIER=1 skips that while
# working). Ports 9101–9103, 9107. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev5} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev5.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev5-state} SHOTS=${SHOTS:-/tmp/bd-shots-s5}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9107; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
command -v pdftoppm >/dev/null || { echo "ABORT: pdftoppm (poppler-utils) is needed for a PDF's first page"; exit 2; }
mkdir -p "$SHOTS"; rm -rf "$BD_DEV_STATE"
cd "$ROOT"
status=0
echo "== the bundle"; php bin/check_bundle.php || status=1
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
php tests/phase3/slice5/lib.php seed > "$BD_DEV_STATE/seed.json" || { echo "the seed failed"; exit 1; }
export BD_S5_SEED="$BD_DEV_STATE/seed.json"
echo "== upload"; php tests/phase3/slice5/upload.php || status=1
echo "== door"; php tests/phase3/slice5/door.php || status=1
echo "== worker"; php tests/phase3/slice5/worker.php || status=1
echo "== browser"; node tests/phase3/slice5/browser.mjs || status=1
echo "== registry"
reg=$(php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $a = ["file_upload"];
  echo count(array_filter($a, fn($k) => !empty($r["actions"][$k]["built"]))), " ", count(array_filter($a, fn($k) => ($r["actions"][$k]["approval"] ?? null) !== null));')
[ "$reg" = "1 0" ] && echo "  ok   the registry builds slice 5's 1 action (people only, never paused)" || { echo "  FAIL the registry reads $reg (actions built, paused)"; status=1; }
php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale"; status=1; }
trap - EXIT; "$ROOT/tests/phase2/servers.sh" stop
if [ "${BD_SKIP_EARLIER:-0}" = "1" ]; then echo "(the earlier slices' suites skipped: BD_SKIP_EARLIER=1)"; exit $status; fi
echo "== slice 4's suite (and 3's, 2's, 1's and Phase 2's within it)"
out=$(BD_DEV_DB=bd_dev4 BD_DEV_ENV=/tmp/bd-dev4.env BD_DEV_STATE=/tmp/bd-dev4-state SHOTS=/tmp/bd-shots-s4 "$ROOT/tests/phase3/slice4/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 5 PROOFS PASSED" || echo "SOME SLICE 5 PROOFS FAILED"
exit $status
