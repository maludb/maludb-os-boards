# Build spec: the live relay — rooms, presence, cursors and committed ops on a socket (decision record §3.4, §6.1; design §3, §8; D7)

Boards is the estate's first application with a live socket. The relay carries what must be live — other people's cursors, selections,
drag previews, who is here, and every committed batch the moment it commits — and **nothing that must be kept**. Slice 3 (`live.md`)
builds it and is THE SECOND EXEMPLAR. The realtime spike of 2026-10-10 proved the chain on this server (record §6.1): commit → other tab
1.8 ms at p50 through Apache; 18,000 of 18,000 cursor messages at 4 ms; a killed `LISTEN` → `resync` in 0.6 s.

## What the relay is and is not

- **Is**: one Python asyncio process (`boards-live.service`) holding rooms of WebSockets in memory; it fans committed batches out from
  PostgreSQL's `LISTEN boards_ops`, relays ephemeral messages between the sockets of one room, and re-checks every socket's right to be
  there every `live_check_seconds`.
- **Is not**: a writer. It holds no write grant anywhere — it connects as the **records role** (`boards_records_ro`) and calls two
  `SECURITY DEFINER` reads, `bd_live_check()` and `bd_live_batch()` (plus `bd_live_member()`, below). Every edit is still a POST to a PHP
  handler and `bd_commit_ops()`. If a design step tempts anyone to let the relay write `board_items`, stop: that would be a write path the
  kernel's actions server cannot reach, and it would bypass `log_activity()`.
- **Generic**: rooms are strings (`board:<uuid>`); the transport knows nothing of boards. The room prefix, the two channel names and the
  check function are the only Boards-specific parts, read from the top of `relay.py` — so Spaces can run the same relay for co-editing.
- **Optional**: with the relay down, or `live_enabled` false, every canvas polls `ops?after=` every `poll_seconds` and says "Live updates
  paused". The board stays editable; nothing is lost.

## Files

| File | What |
|---|---|
| `live/relay.py` | the relay, one file, self-contained (its own `config/.env` reader: `python -I` puts neither the script's directory nor `mcp/` on `sys.path`); asyncpg + `websockets` 15 from `mcp/venv` (`mcp/requirements.txt` already pins `websockets>=15,<16`) |
| `app/live.php` | `bd_live_ticket(int $member, string $board, string $level): string` and `bd_live_enabled(PDO $pdo): bool` |
| `html/boards/live-ticket.php` | `POST /boards/{board}/live-ticket` (a door; `require_login()`, `verify_csrf()`, `bd_board_or_404($pdo, $board, 'view')`): `{"ticket": "…", "expires_in": 60}`; not logged — it changes nothing |
| `db/014_live.sql` | `bd_live_member(p_member_id bigint) RETURNS TABLE (name text, kind text)` — `SECURITY DEFINER`, granted to `boards_records_ro` alone: a socket's display name and kind for presence, never trusted from the client |
| `deploy/boards-live.service` | already written (Phase 0): `ExecStart={{APP_DIR}}/mcp/venv/bin/python -I {{APP_DIR}}/live/relay.py`, `Restart=always`, `ProtectSystem=strict`, `MemoryMax=256M`, `IPAddressDeny=any` + `IPAddressAllow=localhost` |
| `client/src/sync/socket.ts`, `client/src/ui/presence.ts` | the island's side (below) |

**The ticket** is `{member}.{room}.{level}.{exp}.{hmac}`: `room` = `board:<uuid>`, `level` = the caller's level now, `exp` = unix time + 60,
`hmac` = lowercase hex HMAC-SHA256 of `{member}.{room}.{level}.{exp}` with the key `BOARDS_LIVE_KEY` (the 64-hex-character string itself,
as bytes). `view.php` embeds one in the mount (`data-ticket`); the island asks the ticket door for a fresh one on every reconnect. The
relay accepts a ticket **once**: it keeps the ticket strings it accepted until their `exp`, and refuses a second use with 4401.

## Configuration

- `config/.env` (written by `deploy/ROOT_STEPS.sh` step 0 and the installer): `BOARDS_LIVE_PORT` (8851; the relay binds `127.0.0.1`
  only), `BOARDS_LIVE_KEY`, and the records role's DSN from `DB_HOST`, `DB_PORT`, `DB_NAME`, `MCP_RECORDS_DB_USER`,
  `MCP_RECORDS_DB_PASSWORD`. The relay logs to stderr (the journal); it never logs a ticket, a key or a DSN.
- `bd_settings`, read through `bd_setting_int()` / `bd_setting_bool()` at start and at every minute check: `room_max_sockets` (100),
  `live_check_seconds` (60), `live_enabled` (true). With `live_enabled` false the page renders no ticket, and the relay closes every socket
  at its next check (4403, "Live updates are off").

## The wire

JSON text frames, ≤ 64 kB (`max_size`). **Client → relay:**

| Message | When | Who may send |
|---|---|---|
| `{"t": "cursor", "x": 512.5, "y": -40}` | ≤ 20 Hz (the client throttles to 50 ms) | every level |
| `{"t": "select", "ids": ["…"]}` | on a selection change | every level |
| `{"t": "view", "x": …, "y": …, "zoom": 1.25}` | ≤ 2 Hz while the camera moves (the minimap shows each peer's viewport) | every level |
| `{"t": "drag", "items": [{"id", "x", "y", "width", "height", "rotation"}]}` (≤ 50 items) or `{"t": "drag", "ids": [...], "dx", "dy"}` | during a move, resize or rotate, ≤ 20 Hz | edit, full |
| `{"t": "editing", "id": "…" | null}` | text editing starts / stops — the soft lock | edit, full |
| `{"t": "ping"}` | every 25 s | every level |

**Relay → client:**

| Message | Meaning |
|---|---|
| `{"t": "welcome", "you": {"member_id", "name", "kind", "colour", "client"}, "level", "room", "peers": [{"member_id", "name", "kind", "colour", "client", "editing"}]}` | first, after the ticket is accepted |
| `{"t": "join", "peer": {…}}` · `{"t": "leave", "member_id", "client"}` | presence |
| `{"t": "peer", "k": "cursor" | "select" | "view" | "drag" | "editing", "member_id", "client", "d": {…}}` | another socket's message, as sent |
| `{"t": "ops", "seq", "member_id", "source", "client_id", "summary": {counts, types, items, bbox}, "ops": [...]}` | a committed batch; **without `ops`** when it was over 7,500 bytes and the relay could not read it — the client then asks `ops?after=` |
| `{"t": "presence", "member_id", "name", "kind": "agent", "state": "working" | "idle", "dispatch_id"}` | an agent started or finished a turn on this board (below) |
| `{"t": "resync"}` | the relay missed notifications: re-read the state |
| `{"t": "level", "level": "comment"}` | the caller's level changed |
| `{"t": "pong"}` | the answer to ping |
| `{"t": "closing", "code": 4403, "reason": "…"}` | sent just before the relay closes with that code |

`client` is the tab's `data-client`, sent once as a query parameter on connect (`wss://boards.<domain>/live?ticket=…&client=…`).
`colour` is `#` + the palette entry `member_id % 12` of a fixed 12-colour list in `relay.py` (the island draws a cursor, a selection and
a drag ghost in it). `name` and `kind` come from `bd_live_member()`.

## Rooms, rate, levels

- A room is created by its first socket and dropped with its last. At `room_max_sockets` the next socket is closed with **4429** (room
  full).
- Each socket has a 30-message-per-second token bucket; a message beyond it is dropped (counted, never answered).
- A socket at `view` or `comment` may send `cursor`, `select`, `view` and `ping`; anything else from it is dropped. The level is the
  ticket's, then whatever the minute check last found.
- A socket silent for 90 s (not even a ping) is closed with **4408** (idle). A bad, expired or reused ticket: **4401**. A member signed
  out, a board trashed, a level gone to none, or live updates switched off: **4403**.

## LISTEN and order

- One asyncpg connection `LISTEN`s on `boards_ops` (and `boards_presence`); the callback only puts the payload on **one** `asyncio.Queue`,
  and one consumer task fans the queue out — so batches reach a room in **commit order** (the order PostgreSQL delivers notifications).
  Within a board seq order is commit order too, because `bd_commit_ops()` takes the board's row lock first.
- A payload without `ops` (the batch was over 7,500 bytes, `bd_ops_payload()`) is completed with `bd_live_batch(seq)` by the consumer
  before it is sent, so order is kept; if that read fails the message goes out without `ops`.
- The LISTEN connection's termination listener resolves a future; the relay reconnects (0.5 s, then backoff to 5 s) and sends `resync`
  to every room — anything committed while it was deaf is caught up by the clients.
- **Hold every background task**: `asyncio.create_task()` keeps only a weak reference — the spike's first relay lost its LISTEN task to
  the garbage collector ("Task was destroyed but it is pending!") and delivered nothing. `main()` keeps its tasks (listen, consume, check,
  sweep) in a list and `gather`s them; a task that ends raises and systemd restarts the process.

## The minute check

Every `live_check_seconds`, for each distinct (member, board) among the open sockets, the relay calls `bd_live_check(member, board)` once:
NULL (no open session — a kernel sign-out ends them —, the board trashed, the level gone) → `closing` + close 4403; a level different
from the socket's → `{"t": "level", …}` and the socket's level is replaced. The same pass re-reads the three settings and sweeps expired
tickets from the used set.

## The agent's cursor

- **Where an agent works** needs no new channel: every batch an agent commits arrives as `ops` with `source: "agent"`, its `member_id` and
  `summary.bbox`. The island animates that agent's avatar (its colour, its initials, an "AI" mark) to the box's centre over 400 ms,
  flashes the new and changed items, and fades the avatar after 10 s without another batch.
- **That an agent is working** (a mention's turn may run longer than a batch): the worker, when it starts a dispatch's chat turn and when
  the turn ends (answered, refused, failed or paused), runs `SELECT pg_notify('boards_presence', json)` with `{room, member_id, name,
  kind: "agent", state: "working" | "idle", dispatch_id}`. The relay fans it out as `presence` on the same queue; the header shows
  "Facilitator — working" until `idle`. Without the relay the Comments panel's placeholder ("Facilitator is working…") says the same.

## The client side

`client/src/sync/socket.ts` (slice 3):
- connects with the mount's `data-ticket` and `data-client`; on `welcome` fires `boards:live {state: "live"}` and asks `ops?after=` once
  (a batch committed between the page's render and the socket's open);
- sends `cursor` (throttled 50 ms), `select`, `view` (500 ms), `drag` while dragging (50 ms), `editing`, and `ping` every 25 s;
- on close: re-mints through `data-ticket-url` and reconnects after 1, 2, 5, then 10 s (and 10 s thereafter); after **2** failed
  attempts, or when the mount has no ticket, or after a 4403 for "Live updates are off", it fires `boards:live {state: "polling"}` and
  `poll.ts` asks `ops?after=` every `data-poll-seconds` while `document.visibilityState === 'visible'` — the banner "Live updates paused";
  it keeps trying the socket every 30 s and returns to live on success, catching up first;
- on 4403 for any other reason it reloads the page (the wall decides what the person sees now); on 4429 it polls;
- `presence.ts` draws peers' cursors (name label), selections (outlined in the peer's colour), drag ghosts, the soft lock ("Maria is
  editing" on the item; the editor still opens — last writer wins — but warns), the header avatars, and the agents' avatars.

## Apache and the proxy

The vhost (`deploy/apache-boards.conf`) carries `ProxyPass /live http://127.0.0.1:{{BOARDS_LIVE_PORT}}/ upgrade=websocket timeout=120`
and `RewriteRule ^/live(/|$) - [L]`. Apache 2.4.47+ `mod_proxy_http` tunnels the upgrade itself — `proxy_wstunnel` stays off (proven by
the spike on this host's 2.4.58). The ping every 25 s keeps a tunnel under the 120 s timeout. In front, Nginx Proxy Manager forwards
`Upgrade` only with **Websockets Support** on for `boards.<domain>` — the owner's switch (ROOT_STEPS step 4). `tests/dev_router.php`
answers 404 for `/live` (it is never PHP).

## Proving it

`tests/phase3/slice3/run.sh` on a scratch database `bd_dev3`: the app under `php -S 127.0.0.1:9101` with `tests/dev_router.php`, the
fake kernel on 9102, the relay on **9106** (`BOARDS_LIVE_PORT=9106`, `live_check_seconds` lowered to 5 in the scratch `bd_settings`), a
**user-level Apache on 9107** (`apache2 -f tests/phase3/slice3/httpd.conf -k start` — `mod_proxy_http`, `mod_rewrite`, the vhost's `/live`
line, `ProxyPass /` to 9101; no root). Scripted clients (`tests/phase3/slice3/clients.py`, the spike's `measure.py` made checks):

| Check | Passes when |
|---|---|
| commit → the other tab, through Apache | 200 commits; p95 ≤ 50 ms from the commit's answer to the other socket's `ops` |
| cursors | 10 sockets × 20 Hz × 10 s through Apache: every message delivered, p95 ≤ 50 ms |
| a large batch | an 11 kB batch arrives with all its ops (read by seq) |
| tickets | a tampered, an expired and a reused ticket each closed 4401 before any message |
| levels | a viewer's `drag` and `editing` never reach a peer; its `cursor` does |
| rate | 100 messages at once → 30 delivered |
| revocation | a level lowered → `level` within 6 s; the member's sessions ended → 4403 within 6 s; the board trashed → 4403 |
| LISTEN | the relay's LISTEN backend terminated → `resync` to every room within 2 s; the next commit delivered |
| order | two batches on one board from two connections arrive in seq order |
| presence | `boards_presence` notified → `presence` in the room; `bd_live_member()` names the socket; the client's own name is never trusted |
| down | the relay stopped → the canvas polls and shows "Live updates paused"; started → back to live without a reload |

Then Playwright (canvas-core.md "Proving the canvas") with two contexts through the user-level Apache: B sees A's cursor, A's drag ghost
while dragging, and A's drop within one second; A signs out at the fake kernel → B's peer leaves; an agent's batch posted through
`/items/create.php` with an action token moves the agent's avatar to the new stickies.

## Decisions taken here

1. `bd_live_member()` (db/014, slice 3) gives presence its names; the relay reads nothing else.
2. Agent "working" presence travels on a second channel, `boards_presence`, notified by the worker at a dispatch turn's start and end.
3. A ticket is single-use within its 60 s; every reconnect mints a new one at the ticket door.
4. Idle sockets close at 90 s (4408); the client pings every 25 s.
5. `colour` is chosen by the relay from a fixed 12-colour palette by `member_id % 12`.
6. `view` messages (≤ 2 Hz) feed the minimap's peer viewports in v1; "follow me" stays v2.
7. The relay is one self-contained file reading `config/.env` itself.

## Open questions

- (none)
