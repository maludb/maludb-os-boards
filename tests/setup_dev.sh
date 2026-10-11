#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres` — the owner's standing exception for bd_* databases and the three boards_*
#                                  roles only, 2026-10-10; recreates $BD_DEV_DB, default bd_dev0)
# 1. drops and creates the scratch database; 2. applies db/[0-9]*.sql in order as postgres (the same files the installer applies) —
#    BD_MIGRATIONS_UPTO=005 (or BD_DEV_UPTO) stops after the kit's db/001–005 while the schema's later files are being written;
#    3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch
#    password (the kernel's installer sets fresh ones at `apply`); 4. makes a scratch attachment store ($BD_DEV_STORAGE, default
#    /tmp/<db>-storage: files/ thumbs/ tmp/); 5. writes the proofs' environment to $BD_DEV_ENV (default /tmp/bd-dev0.env, mode 600) —
#    app/bootstrap.php reads that file instead of config/.env when BD_DEV_ENV is set (never in production). Nothing here is committed
#    or installed. Ports 9101 (the app), 9102 (the fake kernel), 9103 (the fake MaluDB + MaluMail), 9104–9105 (the MCP servers, Phase 4),
#    9106 (the relay, slice 3), 9107 (a user-level Apache for the live door's proof).
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${BD_DEV_DB:-bd_dev0}
case "$DB" in bd_*) ;; *) echo "refused: the scratch database must be named bd_* (got $DB)"; exit 2 ;; esac
ENVF=${BD_DEV_ENV:-/tmp/bd-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${BD_MIGRATIONS_UPTO:-${BD_DEV_UPTO:-999}}
for f in "$ROOT"/db/[0-9]*.sql; do n=$(basename "$f" | cut -c1-3); [ "$((10#$n))" -le "$((10#$UPTO))" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in boards_rw boards_records_ro boards_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
# live updates: the relay runs only in slice 3's proofs (and later ones that set BD_LIVE=1); every other suite proves the canvas as it polls —
# without a relay the page would carry a ticket and the browser would log its failed WebSocket handshake
[ "${BD_LIVE:-0}" = "1" ] || $PSQL -d "$DB" -c "UPDATE bd_settings SET live_enabled = false" >/dev/null
STORE=${BD_DEV_STORAGE:-/tmp/${DB}-storage}          # the attachment store: a scratch one, emptied
rm -rf "$STORE"; mkdir -p "$STORE"/{files,thumbs,tmp}
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Boards"
APP_KEY=boards
APP_URL=http://127.0.0.1:9101
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=boards_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=boards_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=boards_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=9101
MCP_RECORDS_PORT=9104
MCP_ACTIVITY_PORT=9105
BOARDS_LIVE_PORT=9106
BOARDS_LIVE_KEY=$(openssl rand -hex 32)
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:9102
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:9103
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:9103
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=boards@example.invalid
MAIL_FROM_NAME="Boards"
BD_STORAGE_DIR=$STORE
ENV
echo "scratch database $DB ready; environment in $ENVF"
