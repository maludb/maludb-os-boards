#!/usr/bin/env bash
# Slice 2's proofs (docs/build-specs/canvas.md "Proof") on a fresh SCRATCH database bd_dev2: tests/phase3/slice2/run.sh
#   BD_APP=apache tests/phase3/slice2/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh) —
#   there the browser proof drives real input without the test hook (only tests/dev_router.php puts it on a page) and the performance gate
#   is skipped (it reads the hook).
# Then slice 1's suite re-runs (and with it Phase 2's — both follow what is built). Ports 9101–9103, 9107. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev2} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev2.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev2-state} SHOTS=${SHOTS:-/tmp/bd-shots-s2}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9107; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS"; rm -rf "$BD_DEV_STATE"
cd "$ROOT"
status=0
echo "== the bundle"
php bin/check_bundle.php || status=1
main=client/src/main.ts
cp "$main" "$BD_DEV_STATE.main.ts.bak" 2>/dev/null || { mkdir -p "$(dirname "$BD_DEV_STATE")"; cp "$main" "$BD_DEV_STATE.main.ts.bak"; }
trap 'cp "$BD_DEV_STATE.main.ts.bak" "$ROOT/'"$main"'"' EXIT
printf '\n// touched by the proof\n' >> "$main"
if php bin/check_bundle.php >/dev/null 2>&1; then echo "  FAIL bin/check_bundle.php passed a touched source"; status=1; else echo "  ok   bin/check_bundle.php fails after a source is touched"; fi
cp "$BD_DEV_STATE.main.ts.bak" "$main"; trap - EXIT
php bin/check_bundle.php >/dev/null && echo "  ok   and passes again once it is restored" || { echo "  FAIL the bundle check after the restore"; status=1; }
client/node_modules/.bin/esbuild client/src/model/fractional.ts --format=esm --outfile="$BD_DEV_STATE.fractional.mjs" --log-level=warning 2>/dev/null \
  && export BD_FRACTIONAL_JS="$BD_DEV_STATE.fractional.mjs" || echo "  (esbuild not installed in client/: the z-key twin check will say so)"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in doors ops items undo json; do
  echo "== $p"; php "tests/phase3/slice2/$p.php" || status=1
done
php tests/phase3/slice2/seed.php > "$BD_DEV_STATE/seed.json" || { echo "the seed failed"; status=1; }
export BD_S2_SEED="$BD_DEV_STATE/seed.json"
echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1
if [ "${BD_APP:-php}" != "apache" ]; then echo "== performance"; node tests/phase3/slice2/perf.mjs || status=1; fi
echo "== registry"
reg=$(php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $s = ["board-view","board-outline"];
  $a = ["ops_commit","item_create_batch","item_update_batch","item_delete_batch","ops_undo"];
  $bs = count(array_filter($s, fn($k) => !empty($r["screens"][$k]["built"]))); $ba = count(array_filter($a, fn($k) => !empty($r["actions"][$k]["built"])));
  $ap = count(array_filter($a, fn($k) => ($r["actions"][$k]["approval"] ?? null) !== null)); echo "$bs $ba $ap";')
[ "$reg" = "2 5 1" ] && echo "  ok   the registry builds slice 2's 2 screens and 5 actions, 1 of them paused for an agent (item_delete_batch: deletion)" || { echo "  FAIL the registry reads $reg (screens built, actions built, paused)"; status=1; }
php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale"; status=1; }
trap - EXIT; "$ROOT/tests/phase2/servers.sh" stop
echo "== slice 1's suite (and Phase 2's within it — both follow what is built)"
out=$(BD_DEV_DB=bd_dev1 BD_DEV_ENV=/tmp/bd-dev1.env BD_DEV_STATE=/tmp/bd-dev1-state SHOTS=/tmp/bd-shots-s1 "$ROOT/tests/phase3/slice1/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
