#!/usr/bin/env bash
# Boards — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-10);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S, a user-level Apache
# and fixtures, and never touch an installed application, Apache, systemd or the MaluDB API.
#   bash deploy/ROOT_STEPS.sh              prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them: 0, 0b and 2b (the ports and the live key, Apache's
#                                          modules, the storage directory)
# Steps 1, 2, 3 and 4 are printed and never run: they are the installer's or the owner's.
set -euo pipefail
APP=/srv/apps/boards
KERNEL=/var/www
MODE=${1:-plan}
STORAGE=${BD_STORAGE_DIR:-$APP/storage}   # images and files on the canvas, thumbnails: under the install by default, a mount when named
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS AND MINT THE LIVE KEY before the installer's first apply (Help Desk's lesson of 2026-10-02). The installer assigns a
#    port only to APP_INTERNAL_PORT and the endpoints' port_env (dev-server survey, 2026-10-10) — BOARDS_LIVE_PORT is not an endpoint
#    (the kernel has no `realtime` kind, K42), so it is pinned here with the other three. BOARDS_LIVE_KEY signs the relay's 60-second
#    tickets (design §8); it never leaves this file. A config/.env holding ONLY these: the installer reads them, fills every other
#    required key itself, and sets fresh role passwords because DB_PASSWORD is absent.
echo "== 0. Pin the ports and mint the live key (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "install -d -m 755 '$APP/config' && printf 'APP_INTERNAL_PORT=8198\nMCP_RECORDS_PORT=8849\nMCP_ACTIVITY_PORT=8850\nBOARDS_LIVE_PORT=8851\nBOARDS_LIVE_KEY=%s\n' \"\$(openssl rand -hex 32)\" > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
  if [ "$STORAGE" != "$APP/storage" ]; then run bash -c "echo 'BD_STORAGE_DIR=$STORAGE' >> '$APP/config/.env'"; fi
else
  echo "   (config/.env exists — leaving it alone; check it holds BOARDS_LIVE_PORT and BOARDS_LIVE_KEY)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost|services)'   -> no {{...}} unfilled

# 0b. APACHE'S MODULES (ROOT; idempotent). rewrite, proxy, proxy_http (the MCP servers AND the live socket: since 2.4.47 mod_proxy_http
#     tunnels `upgrade=websocket` itself — proxy_wstunnel is not needed, proven by the realtime spike on this host's 2.4.58), headers
#     (this host had it disabled — Sales CRM's finding; the vhost guards its Header lines so a missing module never fails the config test).
echo "== 0b. Apache's modules (ROOT): rewrite, proxy, proxy_http, headers"
run a2enmod -q rewrite proxy proxy_http headers

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost, the units
#    (the relay among them), the registration (catalog kind ours — the row K40 seeded, kernel db/180 —, /sso and /sso/logout, scope_kind
#    none), the application token, skills imported, approval policies, the installing super-admin's admin grant. --grant-standing-departments
#    gives every standing department the Member role (key user); --hire-agents hires the Boards Expert and the Facilitator. Run it twice:
#    the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 2b. THE STORAGE DIRECTORY (ROOT; idempotent; before the first image). Images and files on the canvas and their thumbnails live under the
#     install ($APP/storage) or on the mount BD_STORAGE_DIR names. Owned by www-data, mode 750 — never under html/.
echo "== 2b. The storage directory $STORAGE (ROOT)"
run install -d -o www-data -g www-data -m 750 "$STORAGE"
for d in attachments thumbnails; do run install -d -o www-data -g www-data -m 750 "$STORAGE/$d"; done

# ------------------------------------------------------------------------------------------------------------------
# 3. The relay (ROOT, after apply). apply renders and enables boards-live.service with the others; to see it:
echo "== 3. The live relay"
echo "   systemctl status boards-live.service      # listening on 127.0.0.1:8851"
echo "   curl -s -o /dev/null -w '%{http_code}\n' -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' http://boards.subello.com/live   # 101 or 4401-closed, never 404"

# ------------------------------------------------------------------------------------------------------------------
# 4. The owner's (any time after 2):
echo "== 4. The owner's"
echo "   - DNS and TLS for boards.subello.com (until then /etc/hosts maps it to this host)"
echo "   - IN NGINX PROXY MANAGER: the boards.subello.com proxy host with 'Websockets Support' ON — without it the canvas still works, polling"
echo "     every 3 s, and says 'Live updates paused' (design §8)"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (notifications and the digest) — the installer's mail step writes them from ~/.malumail when present"
echo "   - the connections a super-admin approves (bin/app_connection.php): Spaces, Knowledge, Projects … reading boards_index and board_outline"
echo "   - the timers are rendered and enabled by apply; by hand:  sudo systemctl enable --now boards-worker.timer boards-directory-sync.timer boards-activity-ingest.timer boards-live.service"
