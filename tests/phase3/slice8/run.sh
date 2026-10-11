#!/usr/bin/env bash
# Slice 8's proofs (docs/build-specs/templates-export.md "Proof") on a fresh SCRATCH database bd_dev8: tests/phase3/slice8/run.sh
#   BD_APP=apache tests/phase3/slice8/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh) — the PNGs are made and downloaded by the real island either way.
# Live updates are off here (no relay). Then slice 7's suite re-runs — and with it 6, 5, 4, 3, 2, 1 and Phase 2 (BD_SKIP_EARLIER=1 skips that while working). Ports 9101–9103, 9107. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev8} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev8.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev8-state} SHOTS=${SHOTS:-/tmp/bd-shots-s8}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9107; do for _ in $(seq 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done; (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done  # a port the previous suite just freed gets 15 s
mkdir -p "$SHOTS"; rm -rf "$BD_DEV_STATE"
cd "$ROOT"
status=0
echo "== the bundle"; php bin/check_bundle.php || status=1
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json" FAKE_MALUMAIL_LOG="$BD_DEV_STATE/malumail.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
php tests/phase3/slice8/seed.php > "$BD_DEV_STATE/seed.json" || { echo "the seed failed"; exit 1; }
export BD_S8_SEED="$BD_DEV_STATE/seed.json"
for p in templates duplicate export json; do
  echo "== $p"; php "tests/phase3/slice8/$p.php" || status=1
done
echo "== browser"; node tests/phase3/slice8/browser.mjs || status=1
echo "== registry"
reg=$(php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true);
  $a = ["board_duplicate", "template_use", "template_save", "template_publish"];
  $s = ["templates", "template-view"];
  echo count(array_filter($a, fn($k) => !empty($r["actions"][$k]["built"]))), " ", count(array_filter($a, fn($k) => ($r["actions"][$k]["approval"] ?? null) !== null)), " ", count(array_filter($s, fn($k) => !empty($r["screens"][$k]["built"]))), " ", count(array_filter($s, fn($k) => !empty($r["screens"][$k]["stub"])));')
[ "$reg" = "4 1 2 0" ] && echo "  ok   the registry builds slice 8's 4 actions (one pauses: template_publish) and 2 screens, no placeholder left" || { echo "  FAIL the registry reads $reg (actions built, paused, screens built, stub)"; status=1; }
php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale"; status=1; }
trap - EXIT; "$ROOT/tests/phase2/servers.sh" stop
if [ "${BD_SKIP_EARLIER:-0}" = "1" ]; then echo "(the earlier slices' suites skipped: BD_SKIP_EARLIER=1)"; exit $status; fi
echo "== slice 7's suite (and 6's, 5's, 4's, 3's, 2's, 1's and Phase 2's within it)"
out=$(BD_DEV_DB=bd_dev7 BD_DEV_ENV=/tmp/bd-dev7.env BD_DEV_STATE=/tmp/bd-dev7-state SHOTS=/tmp/bd-shots-s7 "$ROOT/tests/phase3/slice7/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|ABORT|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
