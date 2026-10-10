#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers, all from the scratch environment ($BD_DEV_ENV):
#   the application on :9101, a fake kernel on :9102, the fake MaluDB + MaluMail on :9103. The application is `php -S` with
#   tests/dev_router.php by default; with BD_APP=apache it is a REAL Apache (as your user, prefork + mod_php — tests/phase2/apache.conf.tmpl)
#   serving deploy/apache-boards.conf rendered the way the kernel's installer renders it (the public vhost moved to :9101, the internal one on
#   :9107, the relay's /live at :9106 and the MCP servers at :9104/:9105 — none of them running until slice 3 and Phase 4) plus one
#   proof-only Alias (/__proof/ → tests/phase2/routes/, never in the deploy file) — the proofs then exercise the vhost's own rewrites.
#   State: $BD_DEV_STATE (default /tmp/bd-dev-p2-state): the pid files, the fake kernel's state file, the logs.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${BD_DEV_ENV:-/tmp/bd-dev-p2.env}
STATE=${BD_DEV_STATE:-/tmp/bd-dev-p2-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  # the built-in server's forked workers outlive their parent: stop whatever still listens on the proofs' ports, by pid (never pkill -f)
  for port in 9101 9102 9103; do
    for pid in $(ss -ltnpH "sport = :$port" 2>/dev/null | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u); do kill "$pid" 2>/dev/null || true; done
  done
  sleep 0.4
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; rm -f "$FAKE_KERNEL_STATE".{chat,apps}
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:9102 "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:9103 "$ROOT/tests/fake_maludb.php" >"$STATE/fake.log" 2>&1 & echo $! > "$STATE/fake.pid"
    if [ "${BD_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import os, sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-boards.conf').read()
for k, val in {'APP_FQDN': 'boards.subello.com', 'APP_DIR': root, 'APP_INTERNAL_PORT': '9107', 'MCP_RECORDS_PORT': '9104', 'MCP_ACTIVITY_PORT': '9105',
               'BOARDS_LIVE_PORT': '9106'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:9101>')
# the proof-only door (never in the deploy file): the gate's fixture route
proof = ('\tAlias /__proof/ %s/tests/phase2/routes/\n\t<Directory %s/tests/phase2/routes>\n\t\tRequire all granted\n\t</Directory>\n' % (root, root))
v = v.replace('\tRewriteEngine On\n\tRewriteRule ^/live(/|$) - [L]', '\tRewriteEngine On\n\tRewriteRule ^/__proof/ - [L]\n\tRewriteRule ^/live(/|$) - [L]', 1)
assert '^/__proof/' in v, 'the proof door was not placed'
v = v.replace('</VirtualHost>', proof + '</VirtualHost>', 1)
open(out + '/site.conf', 'w').write(v)
mods = [m for m in ('headers', 'rewrite', 'proxy', 'proxy_http', 'alias') if not os.path.exists('/etc/apache2/mods-enabled/%s.load' % m)]
main = open(root + '/tests/phase2/apache.conf.tmpl').read().replace('{{STATE}}', out)
main = main.replace('{{EXTRA_MODULES}}', ''.join('LoadModule %s_module /usr/lib/apache2/modules/mod_%s.so\n' % (m, m) for m in mods))
open(out + '/main.conf', 'w').write(main)
PY
      # mod_php reads the application's environment from the process: the scratch .env is exported above, so the vhost needs no SetEnv.
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=26M -d post_max_size=32M -S 127.0.0.1:9101 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:9101/api/v1/health && break; sleep 0.25; done
    echo "servers up (app :9101 ${BD_APP:-php}, fake kernel :9102, fake MaluDB + MaluMail :9103)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
