# Boards — tech-stack decision

Status: **accepted 2026-10-10** — the user approved every recommendation in section 7
Date: 2026-10-10
Evidence: [miro-and-whiteboard-landscape.md](miro-and-whiteboard-landscape.md)

Boards is a Miro-style infinite whiteboard for the MaluDB Business OS: people and OS agents on the same canvas, every
object in the record memory, every change in the activity memory.

## 1. The question

The default stack is Apache / PHP / Bootstrap 5.3 / HTMX over PostgreSQL 17 + MaluDB. HTMX swaps server-rendered HTML
fragments into the DOM. A whiteboard's main screen is the opposite: thousands of objects drawn on a `<canvas>` at
60 fps, dragged and zoomed continuously, with other people's cursors moving live. **HTMX cannot draw or drag a canvas,
and HTTP request/response cannot carry live cursors.** The plugin's own rules anticipate this
(`tech-stack.md`, "Upgrading the stack"): real-time collaboration → WebSockets; interactivity beyond partials → a
JavaScript layer *for the affected screens*.

So the question is not "HTMX or not". It is **which screens leave HTMX, what draws the canvas, and how do changes move
between people, agents and the database without breaking the OS contract.**

## 2. Decision in one picture

```
 Browser ─────────────────────────────────────────────────────────────────────────────────
  ┌─────────────────────────── board.php (PHP-rendered nxl shell, header, app switcher) ──┐
  │  ┌────────────── canvas island: boards.js (TypeScript → one committed bundle) ─────┐  │
  │  │  Konva.js stage · viewport culling · DOM-overlay text editor · local undo stack │  │
  │  └──────┬───────────────────────────────┬──────────────────────────────────────────┘  │
  │  HTMX side panels: comments, share, history, templates, card detail, AMA/command bar  │
  └─────────┼───────────────────────────────┼─────────────────────────────────────────────┘
     commits│ POST /boards/{b}/ops          │ wss /boards-live  (cursors, selection, drag
     (fetch,│ JSON, CSRF / X-Action-Token)  │   previews, presence ↔ ; committed ops ←)
            ▼                               ▼
 Apache ── PHP handlers ───────────── Python relay (asyncio, one systemd unit, 127.0.0.1)
            │ validate · rights · version   │ auth: short-lived ticket minted by PHP
            │ write board_items + board_ops │ rooms per board; ephemeral state in memory only
            │ log_activity()                │ LISTEN boards_ops ──► fan out to the room
            │ NOTIFY boards_ops ────────────┘
            ▼
     PostgreSQL 17 (<tenant>_boards)  ──ingest──►  MaluDB (tenant)  ◄── activity MCP
            ▲                                                            
      records MCP (Python FastMCP): board_overview · board_search · board_read_region (SVG) · find_board
            ▲
 kernel actions server ── POST the same PHP handlers ── OS agents' writes appear live for everyone
```

## 3. The choices

### 3.1 Screens: HTMX everywhere except the canvas

| Surface | Technology |
|---|---|
| Board list, folders, sharing, settings, templates gallery, trash, history, admin | **PHP + HTMX + Bootstrap 5.3 (nxl)** — unchanged plugin patterns, `new-screen` applies |
| Board screen shell: header, app switcher, toolbars, side panels (comments, card detail, share, history) | **PHP + HTMX**; panels are ordinary partials beside the canvas |
| The canvas itself | **TypeScript island** mounted into one `<div>`; talks to the panels through DOM events (`boards:select`, `boards:open-comment`) that HTMX can trigger on (`hx-trigger="boards:select from:body"`) |

No React, no SPA router. Pages stay server-rendered; the island is the only client-side application. This keeps the
design system, the command bar, SSO and the full-navigation rule for cross-application links exactly as every other OS
application has them.

### 3.2 Canvas renderer: **Konva.js** (MIT), Canvas2D

- A scene graph on Canvas2D: layers, hit-testing, drag, the `Transformer` for resize/rotate, node caching for
  level-of-detail. That is the hardest generic plumbing, and Konva supplies it.
- Canvas2D is what Miro itself shipped on. Miro's own guidance is "stay under 5,000 objects per board"; Konva with
  viewport culling and cached nodes is comfortable at that size [I].
- Text is edited Miro's way: on double-click, hide the node and overlay a positioned `<textarea>` / `contenteditable`,
  then redraw on blur.
- **Escape hatch:** the renderer sits behind one module (`render/`). If boards outgrow Canvas2D, PixiJS (MIT, WebGL)
  replaces that module without touching the model, sync or server.

**Rejected:**
- **tldraw**: the best UX, but it needs a paid annual commercial licence, and without a key it stops rendering after
  5 seconds. It also brings React and a Node sync server with no Postgres adapter. Keep it as a *buy* option for the
  user to weigh (§7, Q1), not the default.
- **Excalidraw** (MIT): React, a sketch aesthetic that fights nxl, its own element schema, and end-to-end-encrypted
  collaboration that hides boards from search, MCP and the activity memory.
- **PixiJS first**: faster at 50k objects, but we would build text, transforms and hit-testing ourselves before we
  need the speed.

### 3.3 Sync model: **server-authoritative rows, per-property last-writer-wins** — no CRDT in v1

- **Every board object is a row** in `board_items`, shaped like Miro's REST item: `id uuid, board_id, type,
  parent_id (frame), x, y, width, height, rotation, z, style jsonb, data jsonb, version bigint, created_by/at,
  updated_by/at, deleted_at`. Connectors carry `start_item_id`/`end_item_id` + snap.
- **A client commits an op batch** (`create`, `update {id, fields, base_version}`, `delete`, `reorder`) to a PHP
  handler. PHP checks rights, applies the fields per property (last writer wins per field, so a move and a recolour by
  two people both survive), bumps `version`, appends to `board_ops (board_id, seq bigserial, member_id, source, ops
  jsonb, at)`, calls `log_activity()`, and `NOTIFY`s inside the same transaction.
- **Ephemeral state never touches PHP or the database:** cursors, selections, live drag/resize previews, viewport for
  "follow me". These go client → relay → room at up to 20 Hz. Only the drop is committed.
- **Text inside one object** is last-writer-wins on the whole field, with a soft edit lock shown through presence
  ("Maria is editing"). This is the Miro behaviour.
- **Undo/redo** is a client stack of inverse op batches committed like any other edit. **History and restore** come
  from `board_ops` plus periodic `board_snapshots`.
- **Reconnect / no socket:** the client asks `GET /boards/{b}/ops?after=<seq>` and replays. If the relay is down,
  the same call is polled every 3 s, which is the Spaces pattern. The board degrades, but it never breaks.

Why not Yjs / CRDT: Miro and Figma don't use one; offline editing is not a requirement; a CRDT document is an opaque
blob that the records MCP, `search_index`, `activity_log` and the kernel's actions server cannot read without a second
projection. It would also need a Node (or AGPL y/hub) sidecar. **The door stays open:** if rich co-editing of one long
text is ever needed, a Yjs text per item can be added (`davidhemphill/yjs-php` is MIT, byte-compatible) without
changing the item model.

### 3.4 Realtime transport: **a small Python WebSocket relay**

- **One `boards-live.service` systemd unit:** Python 3 asyncio, the `websockets` library (or Starlette/uvicorn), and
  `psycopg` 3 async `LISTEN`. Bound to `127.0.0.1:${BOARDS_LIVE_PORT}`, in the same venv as the MCP servers.
  Estimated size: a few hundred lines.
- **It is a relay and does not own the data:**
  - it holds rooms and presence in memory;
  - it `LISTEN`s on `boards_ops` and pushes committed ops to the room;
  - it forwards ephemeral messages between members.
  - It **never writes** board data.
- **Auth:**
  - The board page mints a 60-second ticket `{member_id}.{board_id}.{exp}.{hmac}` with the application key. The relay
    verifies the ticket on connect.
  - The relay re-checks `member_sessions` every minute, so a kernel sign-out (`/sso/logout`) closes the socket.
  - A viewer's socket may send cursors but never commits.
- **Why Python:** the installer already builds a Python venv for every OS application. A Node sidecar would add a
  runtime the installer has no step for and that the plugin says not to ship twice. Mercure is a Go binary with the
  same problem.
- **Apache:** `ProxyPass /boards-live ws://127.0.0.1:{{BOARDS_LIVE_PORT}}/ upgrade=websocket` (Apache ≥ 2.4.47,
  `mod_proxy_wstunnel`; Ubuntu 24.04 ships 2.4.58).
- **NOTIFY payloads** are limited to 8 kB. Payloads that are larger carry `{board_id, seq}`, and the relay reads the batch
  through the `records_ro` role.

### 3.5 Client build

- TypeScript is bundled with **esbuild** into `html/assets/boards/boards.<hash>.js`, and the **built bundle is
  committed**, as `htmx.min.js` and `Sortable.min.js` already are. The installer has no npm step, and servers need no
  Node.
- `client/` holds the sources with a `package.json` for development only. A `bin/check_bundle.php` check, run in CI and
  in the proofs, fails if the bundle is stale.
- Konva (≈150 kB min) is vendored into the bundle. There are no CDNs.

### 3.6 Agents and the two memories

- **Record memory.** `board_items` is plain SQL, so `mcp_*` views and the records MCP follow the plugin with one twist
  borrowed from Miro's MCP:
  - `board_overview(board)`: frames, clusters, counts.
  - `board_search(board, text|area)`.
  - `board_read_region(board, bbox)`: returns a compact **SVG dialect**, with one element per item, ids,
    frame-relative coordinates and connector `data-start`/`data-end`.
  - **No full-board reads.** Agents reason well over spatial markup, and large boards stay out of the context window.
- **Actions.** Every op handler is in the 8-column action manifest:
  - `board.create`, `item.create_batch` ("add these 12 stickies in a grid in frame X"), `item.update_batch`,
    `item.delete_batch` (Confirm), `comment.create`, `board.share`, and so on.
  - The kernel's actions server POSTs to the same handlers the canvas uses. **An agent's sticky notes therefore appear
    live on everyone's screen** through the same NOTIFY path. That is the OS's answer to Miro Sidekicks, with no model
    key in Boards.
- **Activity memory.** One `log_activity()` per committed batch, not per drag frame, as `item.create`, `item.move`,
  `item.edit`, `board.share`, …. Text edits are coalesced per item per editing session. Ephemeral traffic is never
  logged.

### 3.7 Reused from the estate (survey per `shared-schema.md`)

| Table | Disposition |
|---|---|
| `members`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | reuse (kernel contract) |
| `activity_log`, `activity_ingest_state`, `mcp_access_tokens`, `app_roles` catalogue, `app_settings` | reuse |
| `attachments` (Spaces' UUID variant with `width`, `height`, `thumbnail_path`) | reuse, for images and files on the canvas |
| `comments`, `comment_mentions` (Spaces' threaded, resolvable, UUID-keyed shape) | reuse, anchored to an item or a canvas point |
| `notifications`, `notification_prefs`, `notification_outbox`, `search_index` | reuse |
| `boards`, `board_folders`, `board_members` (roles owner/editor/commenter/viewer), `board_items`, `board_ops`, `board_snapshots`, `board_templates` | **new** (and these become canonical) |
| Projects issues, Help Desk tickets, CRM deals as `app_card` items | **read** through K7, store provider id + display fields only |

## 4. Final stack

| Layer | Choice | Change from default |
|---|---|---|
| System of record | PostgreSQL 17 `<tenant>_boards` | — |
| Activity memory | Tenant MaluDB via the ingest bridge | — |
| Web / app | Apache + PHP, kernel SSO, no login form | + `mod_proxy_wstunnel` route |
| UI | Bootstrap 5.3 nxl + HTMX for every screen and panel | — |
| Canvas | **TypeScript + Konva.js island**, esbuild, committed bundle | **new** |
| Realtime | **Python asyncio WebSocket relay** + Postgres `LISTEN/NOTIFY` | **new** (same runtime as MCP) |
| Sync model | Server-authoritative rows, per-property LWW, `board_ops` sequence | **new pattern** |
| MCP | Python FastMCP records + activity; SVG region reads | — (plus the read shape) |
| Assistant | The kernel's expert + Boards skill | — |

## 5. Gaps in the OS contract to raise upstream (`maludb-os-integration`)

1. **Endpoint kind for a socket.** For now, declare the relay in `services[]` and in the vhost template, and keep it out
   of `endpoints[]`. Propose a `realtime` endpoint kind (`agent_reachable: false`).
2. **Port allocation.** `BOARDS_LIVE_PORT` must be one more env key that the installer assigns from the application's
   block, as it does `MCP_*_PORT`. Confirm that the installer assigns arbitrary `*_PORT` keys listed in `env.required`.
3. **The vhost template** needs the `upgrade=websocket` line. Confirm that the reverse proxy in front of the kernel
   passes `Upgrade`.
4. **Committed JS bundles** are a convention to write down. Spaces will reuse it when it adds co-editing.
5. **Session revocation for long-lived connections.** The relay's minute check against `member_sessions` should become
   the documented pattern.

## 6. Risks

| Risk | Mitigation |
|---|---|
| Building canvas UX ourselves is the bulk of the work (selection, snapping, connectors routing, text) | MVP object set kept to Miro's core eight; renderer isolated; tldraw kept as a costed alternative |
| Large boards (pen strokes, images) | Culling + Konva caching + image thumbnails from `attachments.thumbnail_path`; document a 5,000-object budget like Miro |
| Relay down | Polling fallback via `ops?after=`; the board stays editable |
| PHP latency on commits | Batches, optimistic local apply, one round-trip per drop; PHP-FPM/opcache |
| Activity log flood | One entry per committed batch; coalesced text edits; ephemeral traffic never logged |

## 7. Checkpoint — decided 2026-10-10 (recommended option taken on each)

1. **Build or buy the canvas UX?**
   - **Recommended:** build on Konva (MIT, full control, matches nxl, no licence).
   - Alternative: license tldraw. That is faster to a polished UX, but it is an annual commercial licence and adds a
     React + Node stack.
2. **Realtime ambition for v1.**
   - **Recommended:** live cursors, presence and live edits through the Python relay.
   - Alternative: polling only (Spaces-style, 1–3 s) first, adding the relay in a later slice.
3. **Catalog key and scope.** Proposed: `boards`, scope kind `none`, with access per board through `board_members`
   and department grants.
4. **v1 object set.** Proposed: sticky, shape, text, connector, frame, pen, image/file, comment pins. Cards linked to
   Projects issues would come in v2.
