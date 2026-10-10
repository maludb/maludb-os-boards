# Boards — dev server survey (read-only)

Date: 2026-10-10, the first session on the Ubuntu dev server (the build server that holds the kernel at `/var/www` and
every sibling application under `/srv/apps`). Step 2 of [../HANDOFF.md](../HANDOFF.md). Nothing was changed on the
server to answer these questions.

## Host

| Fact | Value | What it means for Boards |
|---|---|---|
| OS | Ubuntu 24.04, kernel 6.8 | the standard stack |
| CPU / RAM / disk | 12 vCPU "QEMU Virtual CPU 2.5+" (x86-64-v2, **no AVX**), 15 GiB shared with ~16 applications, 447 GB free on the root LV | the relay is small; any new Python wheel is smoke-tested before it is relied on (the Data Lake's finding: default Polars crashes on this CPU) — `websockets` ships a plain C speed-up and a pure-Python fallback |
| PHP | 8.3 with `gd`, `exif`, `fileinfo`, `sodium`, `pdo_pgsql`, `zip`; **no `imagick`**; php.ini `upload_max_filesize 2M`, `post_max_size 8M` | thumbnails with GD; the vhost raises the upload limits per application with `php_admin_value` as Spaces and DocCloud do |
| `pdftoppm` | present (`/usr/bin/pdftoppm`) | a PDF's first page as a thumbnail for `document` items |
| Python | 3.12.3; the siblings' `mcp/venv`: `mcp` 1.30, `starlette` 1.7.0, `uvicorn` 0.54.0, `asyncpg`, `httpx` — **no `websockets`, no `wsproto`, no `psycopg`** | the relay uses **asyncpg's `add_listener`** for `LISTEN` (already in every venv) instead of psycopg 3, and adds `websockets` to `mcp/requirements.txt`. The installer builds ONE venv per application (`mcp/venv`, the Data Lake's K39 finding), so the relay lives in it |
| Node | **v24.21.0**, npm 11.19.0 already installed (the handoff expected to install 22) | nothing to install; Node stays a development tool for `client/` — esbuild is pinned in `client/package.json`, never global |

## Apache and what is in front of it

- **Apache 2.4.58** (≥ 2.4.47, so `ProxyPass … upgrade=websocket` exists).
- Enabled: `proxy`, `proxy_http`, `proxy_http2`, `rewrite`. **Not enabled:** `proxy_wstunnel` (available), `headers`, `ssl`.
- Since 2.4.47 **`mod_proxy_http` tunnels the upgrade itself** when the backend is written `http://…` with
  `upgrade=websocket`; `mod_proxy_wstunnel` is needed only for a `ws://` backend. The vhost line is therefore
  `ProxyPass /live http://127.0.0.1:{{BOARDS_LIVE_PORT}}/ upgrade=websocket` and **no module needs enabling** — the
  realtime spike proves it. `mod_headers` is off on this host (Sales CRM's finding): the vhost guards its `Header` lines
  with `<IfModule>` and `ROOT_STEPS.sh` enables it, as Sales CRM's does.
- Every application is an Apache vhost on a loopback port (`127.0.0.1:81xx`) behind the public `*:80` name vhosts.
- **In front of Apache:** an **OpenResty** proxy at 67.211.209.250 terminates TLS (`server: openresty` and the
  `x-served-by` header — Nginx Proxy Manager's signature). NPM forwards `Upgrade`/`Connection` only for a proxy host whose
  **"Websockets Support"** switch is on. That is the owner's entry for `boards.<domain>` at Phase 5 (contract gap 3), beside
  DNS: `boards.subello.com` does not resolve yet.

## PostgreSQL and MaluDB

- **PostgreSQL 17.11.** Each application has its own database `subello_<key>` with the three roles; the kernel's is
  `certstudy`. `LISTEN/NOTIFY` is per database, so the relay listens on `subello_boards` only.
- **MaluDB API** on `http://127.0.0.1:8000` (`/health` ok); the tenant's one memory is `certstudy_memory`; an application
  reaches it with `MALUDB_API_URL` from the kernel's env, which the installer writes into the application's `config/.env`.

## The kernel

- `/var/www` at **74bb850** (2026-10-10); highest migration **db/179** (K36, the Data Lake's catalog row). Boards' catalog row
  is therefore **K40 = db/180** (K37–K39 are the Data Lake's).
- Category `communication` is already admitted by both checks (Spaces uses it), and the business area is `Operations` (Spaces,
  Knowledge and DocCloud are there too). So the catalog row widens nothing.
- **Contract gap 2 answered** (`bin/app_install.php`, step 4 "ports"): the installer assigns a port to
  `APP_INTERNAL_PORT` (or `vhost.internal_port_env`) and to **every `endpoints[].port_env`** — and to nothing else. An
  arbitrary `*_PORT` listed in `env.required` is **not** assigned. Every port is kept when `config/.env` already carries it,
  and the estate pins its ports in `deploy/ROOT_STEPS.sh` before the first `apply` (Help Desk's lesson of 2026-10-02).
  Templates are filled from **any** env key. So `BOARDS_LIVE_PORT` is pinned by `ROOT_STEPS.sh` beside the other three and
  rendered into the vhost and the unit as `{{BOARDS_LIVE_PORT}}`. An installer that also assigns `services[].port_env` is an
  optional kernel item (K41).
- `ENDPOINT_KINDS` = `mcp, http_api, database, filesystem, smtp, imap, ssh, ui, webhook` — no `realtime` kind (contract gap 1).
  The relay is declared in `services[]` and the vhost only, never in `endpoints[]`, until the kernel has a kind for it (K42).

## Ports

| Held (listening) | Who |
|---|---|
| 8080, 8811–8816 | the kernel (PHP, its MCP servers, the runner) |
| 8100–8110 | Reservations, txtSchedules, Pro Appointments (internal + MCP) |
| 8180–8182, 8184, 8186, 8189, 8190 | HR, Projects, Help Desk, Cidery, Spaces, ProcessCore, DocCloud |
| 8821–8842 | the applications' MCP servers |
| 8601–8603 | Inventory's scratch servers |

Claimed by a design but not yet listening: 8185 (Consultant Tracking), 8187 (Knowledge), 8188 (Inventory), 8191 (Sales CRM),
8192 (Shareholder Intelligence), 8193 (Data Lake), MCP 8831–8848. **8194–8197 are txtSchedules' proof servers**
(`tests/phase2/servers.sh`, CLAUDE.md), so Boards takes the next clean block:

| Key | Port |
|---|---|
| `APP_INTERNAL_PORT` | **8198** |
| `MCP_RECORDS_PORT` / `MCP_ACTIVITY_PORT` | **8849 / 8850** |
| `BOARDS_LIVE_PORT` | **8851** |
| scratch (proofs, the spike) | **9101–9107** |

## The plugins

Claude Code on this server registers only `htmx-php-builder` 0.4.1 (project scope), so `os-application` cannot be loaded by
name. The current process is in the local clones: `~/htmx-php-builder` (0.9.0) and `~/maludb-os-integration` (0.9.0). This
session read the skills from there. Step 1 of the handoff, installing the plugins from the org marketplaces, is the owner's
call: it changes the Claude Code configuration that every session on this server shares.

## The five contract gaps, as far as this server answers them

| # | Gap | Answer here |
|---|---|---|
| 1 | No endpoint kind for a socket | confirmed (`ENDPOINT_KINDS`); the relay goes in `services[]` and the vhost; K42 proposes `realtime` |
| 2 | Port allocation | the installer assigns only `APP_INTERNAL_PORT` and `endpoints[].port_env`; pin `BOARDS_LIVE_PORT` in `ROOT_STEPS.sh` (the estate's practice anyway); K41 optional |
| 3 | The vhost line and the front proxy | `mod_proxy_http` carries `upgrade=websocket` with no module change; NPM's "Websockets Support" switch on the boards proxy host is the owner's |
| 4 | Committed JS bundles | no sibling has one; Boards writes the convention (Phase 1 `canvas-core` spec) for the plugin to adopt |
| 5 | Session revocation for long-lived connections | the relay re-checks the member's sessions and the board role every minute through a `SECURITY DEFINER` function readable by the records role; written up after the spike for the plugin |
