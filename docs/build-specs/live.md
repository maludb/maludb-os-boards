# Build spec: live (slice 3 — THE SECOND EXEMPLAR)

What exists at the end: everyone on a board **sees each other** — the avatars of who is here in the top bar (people and, while they work,
agents), each person's **cursor** with their name, their **selection** outlined in their colour, the **drag and resize previews** while they
move something, "Maria is editing" on a sticky someone is typing in (the soft lock) — and **every committed batch arrives on every open canvas
within a few milliseconds** of its commit, whoever committed it: a person's drop, an agent's grid of stickies (with **the agent's cursor**
flying to where it worked), a restore. The **relay** (`live/relay.py`, the `boards-live` unit) holds rooms in memory, relays the ephemeral
traffic, fans out PostgreSQL's `LISTEN boards_ops` in commit order and never writes board data; a sign-out at the launcher or a removed share
**closes the socket within a minute**; when the socket is down the canvas **polls** and says so, and catches up exactly on reconnect. **This
slice is the second exemplar** (the estate's first live socket — Spaces will reuse the relay) and is built by the planning model. The build
spec **`live-relay.md`** is normative — "The wire", "Rooms, rate, levels", "LISTEN and order", "The minute check", "The agent's cursor", "The
client side", "Proving it" — and the realtime spike's measurements (`docs/research/tech-stack-decision.md` §6.1) are this slice's gates.

Schema (never modified): `bd_live_check()` (db/006 — the records role only), `bd_ops_payload()`, `bd_live_batch()`, the `board_ops_notify`
trigger (db/007), `bd_settings.room_max_sockets`, `live_enabled`, `live_check_seconds`, `poll_seconds` (db/005), `member_sessions` (db/001),
`bd_board_level()` (db/006). **The database is the referee**: who may be in a room and at what level (`bd_live_check`), what was committed and in
which order (NOTIFY at commit, the board's row lock first in `bd_commit_ops`).

## Divergences from the design
None. Two things the design left open are decided below (presence of viewers; the agent's presence window).

## Who may do what
A socket is opened with a ticket the board page mints for its signed-in member at their level (`view` and up). **Every level may send**
`cursor`, `select` and `view`; **only `edit` and `full`** may send `drag` and `editing` (the relay drops the rest, counted). Nothing on the socket
commits — commits are `ops_commit` and the item actions (slice 2). Agents never open sockets; their presence comes from their committed batches.

## Screens
None. The slice changes `board-view` (slice 2's) by adding the presence strip to the top bar, the peers' layer to the stage and the
"Live updates paused" banner; and adds two doors that are not screens: `POST /boards/{board}/live-ticket` (a fresh 60-second ticket — changes
nothing, never logged per call) and `wss://boards.<domain>/live?ticket=…&client=…` (the relay, behind Apache's `upgrade=websocket` line).

## Files (exactly these)
- `live/relay.py` (one file, ≤ 500 lines: the spike's relay hardened — config from `config/.env` (`BOARDS_LIVE_PORT`, `BOARDS_LIVE_KEY`, the
  records role's DSN from `MCP_RECORDS_DB_*`), every background task held in a list, the limits read from `bd_settings` once a minute, the
  stats on SIGUSR1 to the journal) — run by `deploy/boards-live.service` from `mcp/venv` (already in the repo; `websockets` and `asyncpg` are in
  `mcp/requirements.txt`)
- `app/live.php` (`bd_live_ticket()`), `html/boards/live-ticket.php`; `app/views/boards/partials/{presence,live-banner}.php`; `board-view`'s mount
  gains `data-live-url` and `data-ticket` (canvas-core "The island and the page")
- `client/` — the sources live-relay "The client side" and canvas-core "client/ — files and build" assign to slice 3 (the socket made real in
  place of slice 2's stub, presence, the peers' cursors, selections and previews, the soft lock, the agent's cursor), the bundle rebuilt
- `tests/phase3/slice3/{run.sh,lib.php,relay_test.py,browser.mjs,apache.conf.tmpl}`

## The ticket (live-relay "The wire" is normative)
`bd_live_ticket(int $memberId, string $boardId, string $level, int $ttl = 60): string` = `{member}.board:{uuid}.{level}.{exp}.{hmac}` with
HMAC-SHA256 over the first four parts under `BOARDS_LIVE_KEY`; minted by `board-view` into the page and by `live-ticket.php` on reconnect
(the gate at `view`; the level is `bd_my_level()` now). Never logged, never in a JSON answer but the ticket door's own.

## Query functions (signatures fixed)
PHP: `bd_live_ticket(int $memberId, string $boardId, string $level, int $ttl = 60): string` · `live_enabled(PDO $pdo): bool` (`bd_setting_bool('live_enabled')` —
false: no ticket, the island polls from the start).
Python (`live/relay.py`, the names live-relay fixes): `verify_ticket(ticket) -> (member, room, level) | None`, `handler(ws)`, `on_notify(...)`,
`fan_out_ops()`, `listen_forever()`, `check_forever()`, `main()`.

## MCP tools this slice makes real
None — nothing here is a question an agent asks; an agent's presence is the consequence of its committed batches.

## Manifest rows claimed
Screens (0):
Actions (0):

## Activity log events
None of its own: cursors, presence, drags, the soft lock and the ticket are never logged (design §6.6). The relay logs to the journal only:
start, stop, a LISTEN reconnect, a socket closed by the minute check (member, board, reason) — never a ticket, never a message's contents.

## Out of scope for this slice
"Follow me" and "bring everyone to me" (v2 — presence already carries the view); voting, the timer, reactions (v2); co-editing one text at once
(the soft lock is the v1 answer; CRDT text is Extended); comments' live arrival (slice 6 polls its rail).

## Proof (`tests/phase3/slice3/run.sh`: scratch database `bd_dev3`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; the relay on 9106 with `live_check_seconds = 5`; the user-level Apache on 9107 rendering `deploy/apache-boards.conf` (prefork + `libphp8.3.so` + `mod_proxy_http`, the real `/live` line); Playwright with two and three contexts)
The relay, scripted (`relay_test.py`, the spike's measurements as gates, through Apache on 9107):
1. Commit → the other tab: p95 < 50 ms over 200 commits (the spike measured 2.7 ms).
2. Ten clients × 20 Hz × 10 s: ≥ 99 % of cursor messages delivered, p95 < 50 ms; the relay's CPU and RSS recorded.
3. A tampered and an expired ticket closed 4401 before any message; a room past `room_max_sockets` (set to 3) closed 4429.
4. A viewer's `drag` dropped and its `cursor` relayed; 100 messages at once → 30 delivered.
5. A batch over 7,500 bytes arrives whole (fetched by seq); two concurrent commits on ONE board arrive in seq order (the board's row lock).
6. The level lowered → the socket told `level`; the member's sessions ended → closed 4403 within `live_check_seconds` + 2 s; the board trashed →
   closed 4403.
7. The relay's LISTEN backend killed → every room told `resync` within 2 s and the next commit delivered.
The canvas, Playwright (`browser.mjs`):
8. Priya and Bea on one board: Bea sees Priya's avatar, her cursor with her name within 500 ms of a move, her selection outline, the ghost of her drag
   while it lasts and the sticky's new place within 1 s of the drop — with the poll disabled (`poll_seconds` set to 60).
9. Priya types in a sticky → Bea sees "Priya is editing" on it; Bea's attempt to edit it shows the warning and still lets her (soft lock).
10. An agent's `item_create_batch` (action token, JSON) → both canvases show the stickies and the agent's avatar and cursor at the batch's box,
    gone after five seconds.
11. The relay stopped → both show "Live updates paused" within 10 s and keep editing by polling; the relay back → reconnect, catch up (no batch
    missed, none applied twice — `window.__boards.appliedSeqs()`), the banner gone.
12. A third context signed out at the fake kernel (the sign-out notice) → its socket closed and the page sent to the launcher on its next request.
13. 375 × 740: the presence strip collapses to "+3"; no console errors; `bin/check_bundle.php`.

## Decisions taken here
1. **Viewers are present**: everyone on the board — viewers and commenters too — shows in the presence strip and has a cursor (Miro shows them).
2. **An agent is present for five seconds** after each batch it commits (avatar + cursor at the batch's box), renewed by its next batch.
3. Peer colours: the relay's palette entry `member_id % 12` (live-relay "The wire"); the same person has the same colour on every board.
4. The client sends `cursor` at most 20 Hz (throttled on `pointermove`), `select` on change, `drag` at most 20 Hz while dragging, `editing` on
   overlay open / close, `ping` every 25 s (Apache's tunnel `timeout=120`).
5. Reconnect backoff 1, 2, 5, 10, 10 … s; after the second failed attempt the poll starts and the banner shows; the poll stops on reconnect.
6. On reconnect the client asks `ops?after=<last seq applied>` (exact: per board, seq order is commit order) and skips seqs it already applied.

## Open questions
None.

## Built and proven (2026-10-11)
Everyone on a board sees each other, and every committed batch reaches every open canvas within milliseconds — THE SECOND EXEMPLAR, the
estate's first live socket. **The relay** (`live/relay.py`, 413 lines, one self-contained file run from `mcp/venv` — asyncpg 0.32, websockets
15.0.1): rooms of sockets in memory keyed by `board:<uuid>`, admitted by a single-use 60-second ticket, named from the database
(`bd_live_member()`, db/014 — the client's own words are never used), each with its colour `member_id % 12`; cursor, select, view, drag and
editing relayed between the sockets of a room (drag and editing only from edit and full; every message typed and checked field by field —
anything else dropped and counted), a 30-a-second bucket per socket, 90 s idle → 4408, a full room → 4429; ONE asyncpg `LISTEN` on
`boards_ops` and `boards_presence` feeding ONE queue and ONE consumer (commit order kept; a batch over NOTIFY's 7,500 bytes completed by
`bd_live_batch(seq)`), the connection watched and re-made at 0.5 s → 5 s with `resync` to every room; the minute check (`bd_live_check()` once per
member and board: gone → 4403, a new level → `level`, live updates off → 4403) re-reading the settings and sweeping used tickets; every
background task held; the counters on SIGUSR1; the journal free of tickets, keys and messages. It connects as the records role and writes
nothing. **The ticket door** (`POST /boards/{board}/live-ticket`, people only) and the mount's `data-ticket` (absent when live updates are off).
**The island** (`client/src/sync/socket.ts` made real, `ui/presence.ts`): connected once the state is loaded; live → the poll stops and a catch-up
runs; down → reconnect after 1, 2, 5, 10 s with a fresh ticket, after the second failure "Live updates paused" and the poll, back to live without
a reload; a 4403 reloads the page (the wall decides), "off" polls; a level that changes what the page allows reloads it. Presence: the top
bar's avatars (two and "+N" at 375 px; an agent marked AI), each peer's cursor with their name, their selection in their colour, the ghost of
their drag or resize, "Maria is editing" on the item they type in and a warning when I open it anyway (last writer wins); an agent's batch flies
its avatar to the batch's box over 400 ms, flashes the items it touched, and stays five seconds — or while the worker says it is working.

**Proven** by `tests/phase3/slice3/run.sh` — **60 checks under php -S, 40 under a real Apache**, then every earlier suite — (scratch `bd_dev3`; the relay on 9106 with `live_check_seconds = 5` and the idle close at 8 s; under
php -S through a user-level Apache on 9107 carrying the deploy vhost's OWN `/live` line, and with `BD_APP=apache` through the whole deploy vhost
on 9101): the relay 32 — commit → the other tab **p50 1.0 ms, p95 1.4 ms over 200 commits** (gate 50); ten sockets × 20 Hz × 10 s **18,000 of
18,000 cursor messages, p95 ~15 ms, the relay a quarter of one core and 31 MB**; tampered, expired, reused and forged tickets 4401 before any
message; a full room 4429; a viewer's drag and editing dropped, its cursor relayed; 100 messages at once → 30; a 34 kB batch whole; concurrent
commits in seq order; a lowered level told in < 5 s; a trashed board and a kernel sign-out closed 4403 within the check; the LISTEN backend killed
→ `resync` in 0.55 s and the next commit delivered; `boards_presence` relayed; the idle close; live updates off → the door answers no ticket and the sockets
4403. The browser 25 under php -S (Priya's sticky on Bea's canvas within 1 s with the poll held at 60 s, her cursor within 500 ms, her selection,
her drag's ghost and the drop within 1 s; the soft lock; the Expert's `item_create_batch` on both canvases with its avatar at the batch's box,
gone after five seconds; the relay stopped → "Live updates paused" in ~1 s on both, editing by the poll, the relay back → live again with every
batch applied once; a room of five at 375 px → two avatars and "+3"; Dana signed out at the kernel → her socket closed and her page sent to the
launcher in ~5 s; no console error) and 5 under Apache (no test hook there: the page's DOM and the socket's frames). Then slice 2's suite, and with it
slice 1's and Phase 2's. The relay's journal: started, no key or ticket, no background task lost.

**Decisions taken in the build:**
1. **The ticket carries a nonce**: `{member}.board:{uuid}.{level}.{exp}.{nonce}.{hmac}` (16 hex characters, HMAC over the first five parts).
   Without it two tickets minted for one person in the same second were the same string, and the relay — which accepts a ticket once — refused
   the second tab, or a reconnect in the page's first second, as a reuse. Found by the proof.
2. **The relay's `resync` makes the island catch up (`ops?after=`), not reload the state** — exact, because per board seq order is commit order;
   when the server answers that the ops are gone (a compaction), the catch-up reloads. A 5,000-item board is not re-read for a LISTEN hiccup.
3. **An agent is present five seconds after each batch** (this spec's decision 2 and the consistency record; live-relay.md had said ten).
4. **The socket opens only after the first state is loaded**: a batch landing before the load would have been applied, wiped by the load and,
   marked applied, skipped by the catch-up. On `welcome` the island catches up once.
5. **The idle close may be lowered by `BOARDS_LIVE_IDLE_SECONDS`** (the proofs set 8 to see it); production keeps 90.
6. **A level change that changes what the page allows** (edit ↔ below edit) reloads the page after a notice; any other level change refreshes the
   badge only.
7. **Presence draws on its own layer and alone**: a peer's cursor redraws the presence layer, never the board (`requestPresence()`).
8. **At 375 px the canvas's top bar drops the level badge** to make room for the title and the avatars; the toolbar already shows what the level
   allows.
9. **Live updates off is a setting, not an error**: the ticket door answers `{"ticket": null, "live": false}` (not a 503 the browser would log),
   and an island without a ticket asks again once a minute. A scratch database has live updates off unless `BD_LIVE=1` — the suites without a
   relay prove the polling canvas.
10. **The test files are this spec's** (`relay_test.py`, `apache.conf.tmpl`); live-relay.md's `clients.py` / `httpd.conf` are the same things. A
   sign-out's page is proven by its 302 to the launcher: the browser's follow-up to the launcher's test name is not fetched.
