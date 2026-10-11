#!/usr/bin/env bash
# Slice 1's proofs (docs/build-specs/boards-folders.md "Proof") on a fresh SCRATCH database bd_dev1: tests/phase3/slice1/run.sh
#   BD_APP=apache tests/phase3/slice1/run.sh   the same under a user-level Apache rendering the deploy vhost (tests/phase2/servers.sh)
# Then Phase 2's suite re-runs (it follows the placeholders still owed). Ports 9101–9103, 9107. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev1} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev1.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev1-state} SHOTS=${SHOTS:-/tmp/bd-shots-s1}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9107; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS"; rm -rf "$BD_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in boards sharing folders trash walls json; do
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1
echo "== registry"
reg=$(php -r '$r = json_decode(file_get_contents("mcp/action_registry.json"), true); $s = ["boards-list","board-add","board-settings","board-share","folders","folder-view","folder-add","folder-edit","trash"];
  $a = ["board_create","board_update","board_lock","board_delete","board_restore","board_share","board_unshare","board_access_set","board_transfer","board_favorite","board_unfavorite","folder_create","folder_update","folder_delete","folder_add_board","folder_remove_board"];
  $bs = count(array_filter($s, fn($k) => !empty($r["screens"][$k]["built"]))); $ba = count(array_filter($a, fn($k) => !empty($r["actions"][$k]["built"])));
  $ap = count(array_filter($a, fn($k) => ($r["actions"][$k]["approval"] ?? null) !== null)); echo "$bs $ba $ap";')
[ "$reg" = "9 16 6" ] && echo "  ok   the registry builds slice 1's 9 screens and 16 actions, 6 of them paused for an agent" || { echo "  FAIL the registry reads $reg (screens built, actions built, paused)"; status=1; }
php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale"; status=1; }
trap - EXIT; "$ROOT/tests/phase2/servers.sh" stop
echo "== Phase 2's suite (the shell, with the placeholders still owed)"
out=$(BD_DEV_DB=bd_dev_p2 BD_DEV_ENV=/tmp/bd-dev-p2.env BD_DEV_STATE=/tmp/bd-dev-p2-state SHOTS=/tmp/bd-shots "$ROOT/tests/phase2/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
