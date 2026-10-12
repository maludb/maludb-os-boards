#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop|venv — the two MCP servers on SCRATCH loopback ports from the scratch environment ($BD_DEV_ENV):
# records :9104, activity :9105 (never the installed application's ports 8849/8850 or its services). Pid files live in $BD_DEV_STATE.
# The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt; deploy/ROOT_STEPS.sh does it for the installed copy),
# else $BD_MCP_VENV, else a sibling application's that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
# The records server is started WITHOUT the MaluDB token in its environment (it never needs it).
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${BD_DEV_ENV:-/tmp/bd-dev40.env}
STATE=${BD_DEV_STATE:-/tmp/bd-dev40-state}
mkdir -p "$STATE"
VENV=
for c in "${BD_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/spaces/mcp/venv /srv/apps/shareholder_intelligence/mcp/venv /srv/apps/knowledge/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(9104|9105) ' || break; sleep 0.2; done
  fuser -k -n tcp 9104 >/dev/null 2>&1 || true; fuser -k -n tcp 9105 >/dev/null 2>&1 || true      # by port, never by a command-line pattern
}
case "${1:-}" in
  stop) stop ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    ( cd "$ROOT/mcp" && exec env -u MALUDB_API_TOKEN -u MALUDB_API_URL MCP_RECORDS_PORT=9104 MCP_ACTIVITY_PORT=9105 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-records.log" 2>&1 ) & echo $! > "$STATE/mcp-records.pid"
    ( cd "$ROOT/mcp" && exec env MCP_RECORDS_PORT=9104 MCP_ACTIVITY_PORT=9105 "$PY" "$ROOT/mcp/activity_server.py" >"$STATE/mcp-activity.log" 2>&1 ) & echo $! > "$STATE/mcp-activity.pid"
    for p in 9104 9105; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :9104, activity :9105; python $VENV)" ;;
  *) echo "usage: $0 start|stop|venv" >&2; exit 2 ;;
esac
