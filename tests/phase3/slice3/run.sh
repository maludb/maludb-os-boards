#!/usr/bin/env bash
# Slice 3's proofs (docs/build-specs/live.md "Proof") on a fresh SCRATCH database bd_dev3: tests/phase3/slice3/run.sh
#   php -S on 9101 behind a user-level Apache on 9107 carrying the deploy vhost's own /live line (apache.conf.tmpl);
#   BD_APP=apache tests/phase3/slice3/run.sh — the whole deploy vhost rendered on 9101 by tests/phase2/servers.sh, /live included.
# The relay (live/relay.py from mcp/venv) on 9106 with live_check_seconds = 5 and the idle close at 8 s; the fake kernel on 9102, the fake
# MaluDB/MaluMail on 9103. Then slice 2's suite re-runs (and with it slice 1's and Phase 2's) — BD_SKIP_EARLIER=1 skips that while working,
# BD_RELAY_ONLY=1 runs the relay's proofs alone. Exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export BD_DEV_DB=${BD_DEV_DB:-bd_dev3} BD_DEV_ENV=${BD_DEV_ENV:-/tmp/bd-dev3.env} BD_DEV_STATE=${BD_DEV_STATE:-/tmp/bd-dev3-state} SHOTS=${SHOTS:-/tmp/bd-shots-s3}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 9101 9102 9103 9106 9107; do for _ in $(seq 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done; (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done  # a port the previous suite just freed gets 15 s
[ -x "$ROOT/mcp/venv/bin/python" ] || { echo "ABORT: mcp/venv is missing (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt)"; exit 2; }
mkdir -p "$SHOTS"; rm -rf "$BD_DEV_STATE"; mkdir -p "$BD_DEV_STATE"
cd "$ROOT"
status=0
echo "== the bundle"; php bin/check_bundle.php || status=1
BD_LIVE=1 "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sudo -n -u postgres psql -X -q -d "$BD_DEV_DB" -c "UPDATE bd_settings SET live_check_seconds = 5, live_enabled = true" >/dev/null
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
stop_all() {
  [ -f "$BD_DEV_STATE/relay.pid" ] && kill "$(cat "$BD_DEV_STATE/relay.pid")" 2>/dev/null
  [ -f "$BD_DEV_STATE/front.conf" ] && apache2 -f "$BD_DEV_STATE/front.conf" -k stop 2>/dev/null
  "$ROOT/tests/phase2/servers.sh" stop
}
trap stop_all EXIT
set -a; . "$BD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$BD_DEV_STATE/kernel.json" BOARDS_LIVE_IDLE_SECONDS=8
start_relay() {
  "$ROOT/mcp/venv/bin/python" -I "$ROOT/live/relay.py" >>"$BD_DEV_STATE/relay.log" 2>&1 & echo $! > "$BD_DEV_STATE/relay.pid"
  for i in $(seq 1 40); do (exec 3<>/dev/tcp/127.0.0.1/9106) 2>/dev/null && return 0; sleep 0.25; done; echo "the relay did not start"; return 1
}
start_relay || exit 1
export BD_RELAY_PID=$(cat "$BD_DEV_STATE/relay.pid")
if [ "${BD_APP:-php}" = "apache" ]; then
  export BD_BASE=http://127.0.0.1:9101
else
  python3 - "$ROOT" "$BD_DEV_STATE" <<'PY'
import os, re, sys
root, out = sys.argv[1], sys.argv[2]
vhost = open(root + '/deploy/apache-boards.conf').read()
line = next(l for l in vhost.splitlines() if re.match(r'\s*ProxyPass\s+/live\s', l)).replace('{{BOARDS_LIVE_PORT}}', '9106')
mods = [m for m in ('rewrite', 'proxy', 'proxy_http') if not os.path.exists('/etc/apache2/mods-enabled/%s.load' % m)]
conf = open(root + '/tests/phase3/slice3/apache.conf.tmpl').read().replace('{{STATE}}', out).replace('{{LIVE_LINE}}', line)
conf = conf.replace('{{EXTRA_MODULES}}', ''.join('LoadModule %s_module /usr/lib/apache2/modules/mod_%s.so\n' % (m, m) for m in mods))
open(out + '/front.conf', 'w').write(conf)
PY
  apache2 -f "$BD_DEV_STATE/front.conf" -k start || { echo "the front Apache did not start"; exit 1; }
  export BD_BASE=http://127.0.0.1:9107
  for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:9107/api/v1/health && break; sleep 0.25; done
fi
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
php tests/phase3/slice3/lib.php seed > "$BD_DEV_STATE/seed.json" || { echo "the seed failed"; exit 1; }
export BD_S3_SEED="$BD_DEV_STATE/seed.json"
echo "== the relay (through $BD_BASE)"; "$ROOT/mcp/venv/bin/python" -I tests/phase3/slice3/relay_test.py || status=1
if [ "${BD_RELAY_ONLY:-0}" != "1" ]; then echo "== browser"; node tests/phase3/slice3/browser.mjs || status=1; fi
echo "== the relay's journal"
grep -q "listening on 127.0.0.1:9106" "$BD_DEV_STATE/relay.log" && echo "  ok   the relay logged its start" || { echo "  FAIL the relay's start is not in its log"; status=1; }
if grep -qE "$(grep -m1 '^BOARDS_LIVE_KEY=' "$BD_DEV_ENV" | cut -d= -f2)|ticket=|password" "$BD_DEV_STATE/relay.log"; then echo "  FAIL the relay's log holds a key, a ticket or a password"; status=1; else echo "  ok   the relay's log holds no key, ticket or password"; fi
grep -q "Task was destroyed" "$BD_DEV_STATE/relay.log" && { echo "  FAIL a background task was garbage-collected"; status=1; } || echo "  ok   no background task lost"
trap - EXIT; stop_all
if [ "${BD_SKIP_EARLIER:-0}" = "1" ]; then echo "(the earlier slices' suites skipped: BD_SKIP_EARLIER=1)"; exit $status; fi
echo "== slice 2's suite (and slice 1's and Phase 2's within it)"
out=$(BD_DEV_DB=bd_dev2 BD_DEV_ENV=/tmp/bd-dev2.env BD_DEV_STATE=/tmp/bd-dev2-state SHOTS=/tmp/bd-shots-s2 "$ROOT/tests/phase3/slice2/run.sh" 2>&1); rc=$?
echo "$out" | grep -E 'FAIL|PASSED|FAILED|ABORT|all [0-9]+ passed' | sed 's/^/  /'
[ $rc -eq 0 ] || status=1
[ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
