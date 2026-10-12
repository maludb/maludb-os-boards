#!/usr/bin/env bash
# Slice 10's proofs (docs/build-specs/home-admin.md "Proof") on a fresh SCRATCH database bd_dev10: tests/phase3/slice10/run.sh
#   BD_APP=apache tests/phase3/slice10/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh).
# The worker's trash pass is run as the real bin/worker.php (and once at a fake clock, BD_WORKER_NOW). Live updates are off here (no relay) except where the settings proof turns them on and off.
# Then slice 9's suite re-runs — and with it 8, 7, 6, 5, 4, 3, 2, 1 and Phase 2 (BD_SKIP_EARLIER=1 skips that while working). Ports 9101–9103, 9107. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev10} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev10.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev10-state} SHOTS=${SHOTS:-/tmp/bd-shots-s10}
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
for p in home admin settings purge json; do
  echo "== $p"; php "tests/phase3/slice10/$p.php" || status=1
done
php tests/phase3/slice10/seed.php > "$BD_DEV_STATE/seed.json" || { echo "the seed failed"; exit 1; }   # the browser's own world, made after the PHP proofs have had theirs
export BD_S10_SEED="$BD_DEV_STATE/seed.json"
echo "== browser"; node tests/phase3/slice10/browser.mjs || status=1
echo "== registry"
reg=$(php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true);
  $a = ["settings_save", "trash_purge"];
  $s = ["home", "admin", "admin-usage", "admin-settings"];
  $all = array_filter($r["screens"], fn($x) => !empty($x["built"]));
  $allA = array_filter($r["actions"], fn($x) => !empty($x["built"]));
  echo count(array_filter($a, fn($k) => !empty($r["actions"][$k]["built"]))), " ", count(array_filter($s, fn($k) => !empty($r["screens"][$k]["built"]))), " ", count(array_filter($r["screens"], fn($x) => !empty($x["stub"]))), " ", count($all) . "/" . count($r["screens"]), " ", count($allA) . "/" . count($r["actions"]);')
[ "$reg" = "2 4 0 26/26 45/45" ] && echo "  ok   the registry builds slice 10's 2 actions and 4 screens — every screen (26/26) and action (45/45) of the manifest is built, no placeholder left" || { echo "  FAIL the registry reads $reg (actions built, screens built, stub, screens, actions)"; status=1; }
php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale"; status=1; }
trap - EXIT; "$ROOT/tests/phase2/servers.sh" stop
if [ "${BD_SKIP_EARLIER:-0}" = "1" ]; then echo "(the earlier slices' suites skipped: BD_SKIP_EARLIER=1)"; exit $status; fi
echo "== slice 9's suite (and 8's, 7's, 6's, 5's, 4's, 3's, 2's, 1's and Phase 2's within it)"
out=$(BD_DEV_DB=bd_dev9 BD_DEV_ENV=/tmp/bd-dev9.env BD_DEV_STATE=/tmp/bd-dev9-state SHOTS=/tmp/bd-shots-s9 "$ROOT/tests/phase3/slice9/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|ABORT|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 10 PROOFS PASSED" || echo "SOME SLICE 10 PROOFS FAILED"
exit $status
