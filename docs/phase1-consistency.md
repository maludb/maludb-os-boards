# Boards — Phase 1 consistency record

2026-10-10 · **for the owner's checkpoint.** Phase 1 is the tool surface, the action manifest and its registry, three build specs and
eleven slice specs (the shell and ten slices), approved together before any feature PHP is written. This record lists what Phase 1
decided where the design was silent, what it changed in Phase 0's files, and how the documents are proven consistent.

## The documents

| Document | What it fixes |
|---|---|
| `docs/boards-mcp-tool-surface.md` | 27 records tools (5 resolvers, the K7 shares `boards_index` and `board_outline`, `app_roles`), 7 activity tools, the SVG dialect (normative) |
| `docs/boards-action-manifest.md` → `mcp/action_registry.json` | 26 screens, 45 actions; 12 pause for an agent (= `maludb-os.json` `approvals[]`), 6 people only |
| `docs/build-specs/canvas-core.md` | the island, `client/` and its committed bundle, the op protocol, coordinates, the PHP doors, rendering, 375 px, exports, proving a canvas headlessly |
| `docs/build-specs/live-relay.md` | the relay, the ticket, the wire, rooms, LISTEN and order, the minute check, the agent's cursor, the client's socket and fallback |
| `docs/build-specs/walls-and-sharing.md` | the wall's one rule and the PHP helper, the share page, agents and sharing, folders, the trash, what every slice must do |
| `docs/build-specs/{sso-shell, boards-folders, canvas, live, connectors-frames, pen-files, comments-notifications, history-versions, templates-export, agents-search, home-admin}.md` | the eleven slices, each claiming its manifest rows and naming its tools |
| `db/013_phase1_reads.sql` | the reads behind the tools that are more than a view: the SVG renderer, the overview, the outlines, the usage view |

## How consistency is proven — `tests/phase1/check_specs.sh`

- `bin/build_action_registry.php --check`: the registry is the manifest's; no parse warning; no log event shared by a paused and a free
  action.
- `bin/sync_approvals.php --check`: `maludb-os.json` `approvals[]` is the manifest's Agent approval column.
- `tests/phase1/check_specs.php` (ported from the Data Lake's):
  - every screen and action of the registry is claimed by exactly one slice spec;
  - every tool of the surface is named by a slice, and every tool a slice names is in the surface;
  - every tool and action an agent is granted exists;
  - **each agent's job description names exactly its grants** (new for Boards);
  - the three build specs exist.
- `tests/phase0/schema.sh`: the schema proof, now **167 checks** with the Phase 1 reads.

## Decisions Phase 1 took (★ = the owner may want to look)

1. ★ **The canvas's raw door is people-only.** `ops_commit` takes the island's JSON batch, and only a person's session reaches it. An
   agent acts through **one endpoint per verb** under `/items/`: create, update, delete, connect, frame, arrange and undo. So a paused
   delete never shares a log event with a free edit (the kernel's approval policy matches the log event), and no agent writes raw ops.
2. ★ **Agents never do coordinate arithmetic.**
   - `item_create_batch` takes texts and a layout (grid, column or row), placed in a frame or in empty space found from the board's
     extent.
   - `items_arrange` re-lays existing items.
   - `connector_create` joins two items by id.
   - `frame_create` wraps items it names.
3. **Coordinates in the SVG dialect:** `x`/`y` are SVG's top-left, frame-relative inside a frame's group. `data-cx`/`data-cy` are
   **always the absolute centre**, which every action takes.
4. **Export is a door, not an action.** The SVG download and the PNG log are `GET`/`POST /boards/{board}/export`. `export_svg` left the
   Expert's grants: an agent reads a board in the dialect instead.
5. **Three tools beyond design §7:**
   - `item_get` — one item whole, since the dialect cuts text at 500 characters;
   - `version_outline` — "what did it look like on Friday";
   - `trash_list` — the trash screen's question.

   The Expert holds `item_get` and `version_outline`, the Facilitator `item_get`.
6. **`ops_undo` takes a count** (my last 1–10 batches on this board within the hour), not seqs — what "undo that" means by voice.
   The database's `bd_undo_ops()` still takes the seqs; the handler finds them.
7. **The slices own their screens:**
   - home → slice 10;
   - notifications and preferences → slice 6;
   - search → slice 9.

   The shell builds placeholders, the bell and the search box.
8. `board_transfer` cannot be undone; the new owner transfers it back. `folder_create` needs only `boards.view`: a Viewer may keep
   folders of what is shared with them. `file_upload` and `dispatch_retry` are people only.
9. ★ **Found: `board_items.z` must sort byte-wise.** It is a fractional-index key, and under this server's `en_US.UTF-8` collation
   `'B0' > 'a0'`. The column is now `COLLATE "C"`; db/007 was changed in place, because nothing is installed. The reused `position`
   columns (favourites, folder entries) keep Spaces' shape, and their queries order with `COLLATE "C"`.
10. ★ **The canvas is proven headlessly on this server.** Playwright and headless Chromium are installed (the kernel's `web/node_modules`,
    used read-only as Consultant Tracking's browser proofs do). So slices 2–5 prove drawing, dragging and two people's live edits with
    real pointer events in two browser contexts. The island exposes a test hook (`window.__boards`) only on the dev router's page. The
    owner's own two-browser check of the *feel* stays at slice 3 and Phase 5.

(The forks' decisions on the specs follow below, as reconciled by the planning model.)

## Decisions the specs took (each spec's "Decisions taken here"; confirmed by the planning model)

### canvas-core
- **The right rail:** one partial door, `GET /boards/{board}/rail?panel=…`.
- **Undo:** the canvas's answer carries the batch's inverse. A person's undo posts it as a new batch; `ops_undo` is the agents'.
- **Retries:** an agent's retried call commits once, its `client_batch_id` the md5 of the request id.
- **The test hook:** `data-test="1"` and `window.__boards` exist only on the dev router.
- **The thumbnail:** ≤ 480 × 300, ≤ 512 kB, one per board per 5 minutes, not logged.
- ★ **The headless performance gate:** a median frame ≤ 33 ms, none over 100 ms, on the 5,000-item fixture. 60 fps is the owner's check in a
  real browser.

### live-relay
- **Presence names come from the database** (`bd_live_member()`, db/014, slice 3), never from a client.
- ★ **An agent's "working" state:** while a dispatch's turn runs, it travels on a second channel `boards_presence` that the worker
  notifies; its cursor comes from its batches' box.
- **Tickets:** single-use within their 60 seconds.
- **Idle sockets:** closed after 90 s (4408); the client pings every 25 s.
- **Colours:** `member_id % 12`.
- **When live updates are off:** no ticket is rendered and sockets close (4403).

### walls-and-sharing
- **Picker sources:** principals (people and agents, not the owner), departments, new owners.
- **Default levels:** a person or an agent at edit, a department at view.
- **Purge:** unlinks only under `BD_STORAGE_DIR`.

### sso-shell
- **The kit's write guard:** `bd_guard()` learns 42501 → 403 and 22023 → 422, which db/006–013 raise.
- **The phone's tab bar:** Home · Boards · Search · Me.
- **No API keys** in v1, only MCP tokens.
- ★ **The proofs run through a user-level Apache rendering the real vhost:** prefork + `libphp8.3.so`, no root.

### Boards and folders (slice 1)
- **No new database function** for folders and favourites: plain statements under the ownership rule.
- **Widening the everyone level asks first;** narrowing does not.
- **A team folder needs `boards.create`.**
- **A new board is blank.**
- **Sharing tells the person:** a person shared by name gets a `share` notice (reconciled from slice 6).

### The canvas (slice 2)
- **Polling only:** slice 3 makes the socket real.
- **Shapes:** Miro's ten.
- **Pasting text:** one sticky per line, up to 50.
- **Resync:** decided by the latest compaction version.
- **An agent's undo** skips undo batches.

### Live (slice 3)
- **Viewers and commenters** have cursors and presence.
- **An agent stays present** 5 s after each batch it commits.
- **Reconnects** back off 1, 2, 5, 10 s, then fall back to polling.

### Connectors and frames (slice 4)
- **Arrowheads and lines:** three arrowheads, widths 1–8, three line styles.
- **Routes:** elbow routing with at most two bends; routes are never stored.
- **Joining a frame:** an item joins a frame by its centre on drop.
- **`frame_create`** pads its contents by 40.
- **Deleting a frame on the canvas** asks whether its contents go too.

### Pen, images and files (slice 5)
- ★ **The upload door mints the item's id.** Uploads never placed on the canvas are purged after 24 h.
- **The file door** is `/files/{id}?size=`.
- **Types** are decided by `finfo`.
- **The eraser** removes whole strokes.
- **A PDF's first page** comes from `pdftoppm`, within 20 s.
- **An SVG** is always sandboxed.

### Comments and notifications (slice 6)
- **The composer:** plain text with @mentions, turned into rich text on the server.
- ★ **Comment pins on the canvas refresh by a 15-second poll.** The relay carries no comments in v1.
- **The digest:** 07:00 in each person's time zone, at most 30 notices.
- **The bell:** polled every 60 s.
- **The outbox:** Spaces' rules.

### History and versions (slice 7)
- **A version's preview is its outline:** the canvas never shows an old state.
- **When the worker runs it:** interval versions every pass; compaction and the item purge nightly at 03:xx in the business's time zone.

### Templates and export (slice 8)
- **The six shipped templates** are fixed item by item (ids `7e…`), seeded by `db/016_seed_templates.sql`.
- **A template's page** is its outline.
- **PNG export** is made by the client.

### Agents and search (slice 9)
- **The dispatch's context sentence** ends "Never mention an agent".
- **Retries:** backoff 2, 4, 8, 16 minutes over five attempts; a long run is polled up to 10 minutes.
- **`items_arrange`:** `cluster` groups by fill colour.
- **Search** leaves templates out.
- **The shares' SQL** is fixed and proven as the consumer's agent.
- **The functions:** `db/018_dispatch_loop.sql`.

### Home and admin (slice 10)
- **Purge** lives on the admin's page.
- **The settings form** holds exactly the manifest's fields.
- **Home:** 8 cards a section; a first-run state with the six templates.
- **The budget bar:** amber past the soft budget, red within 10 % of the hard cap.

## Reconciled by the planning model (the specs disagreed or left a gap)

1. **An agent's items with no position** go **below** the board (extent.x1, extent.y2 + 200), and a target frame **grows** in the same
   batch. Slice 2's spec had said "right of the extent" and "not resized"; canvas-core wins.
2. **Thumbnail size:** canvas-core's 480 × 300 (the slice had 640 × 400).
3. **Peer colours:** `member_id % 12`.
4. **Performance:** canvas-core's frame-time gate, plus slice 2's load-time gates.
5. **The layout engine is two layers:** `bd_layout()` gives centres (canvas-core), and `items_layout()` turns them into create ops
   (slice 2). Slice 9's arrange builds its update ops over `bd_layout()`.
6. **The SVG export door is slice 8's.** A whole board over 400 items is **refused 422** ("export a frame, or a PNG, instead"), never
   truncated into a file that passes for the whole board. Slice 8's draft had truncated.
7. **The worker's `trash` pass** was left by slice 1 to slice 10 and by slice 10 to slice 1. **Slice 10 owns it**, beside its
   `trash_purge`.
8. **A person shared by name is notified** (`share`) by slice 1's `board_share` handler. Slice 6 had assumed it, and slice 1 had not
   written it.
9. **Migrations are numbered in build order:** db/014 `live` (slice 3), db/015 `interval_versions_not_templates` (slice 7), db/016 `seed_templates` and db/017
   `shipped_templates_view_only` (slice 8), db/018 `dispatch_loop` (slice 9). Two drafts both claimed 014.
10. **The manifest's doors** gained the rail and the file door's `?size=`, and its log events `item.restore`.

11. **Found in Phase 2:** the shell spec and walls-and-sharing named different colours for the level badge, and different files for the
    board gate. walls-and-sharing wins both (full `dark`, edit `success`; `app/features/boards/wall.php`); sso-shell.md is corrected.

12. **Found in slice 1:** boards-folders.md counted five approvals for its actions; there are six (`folder_delete` pauses too) — the manifest
    and `maludb-os.json` were right. And a Boards admin's "own or boards.admin" on a folder needed a path past `mcp_folders`, which lists a
    person's own folder only to them (boards-folders.md "Built and proven" 2).

13. **Found in slice 2:** canvas.md and canvas-core.md disagreed on the layout's gap (20 / 24), a text cell (300 × 60 / 200 × 60), the paste
    offset (20 / 24) and the rail's panel name (`properties` / `item`) — canvas-core won, as reconciliation 1 had ruled; the commit's answer to
    an item id taken follows canvas.md's door table (409). canvas-core's decision 6 is corrected: an agent's batch id is md5 of the run's request id,
    the action's path and its parameters — the run's request id alone made a second call in the same turn a "duplicate". A block placed in a frame
    goes below what the frame holds, not on top of it. canvas.md named a `board.thumbnail` event; canvas-core (not logged) won.
14. **Found in slice 2, the performance gate:** "no frame over 100 ms" is not measurable as a raw frame interval on this shared VM (an empty
    animation-frame loop shows 100–330 ms frames now and then); the gate reads the interval's median and 95th percentile and the island's own
    work a frame, three attempts a view (canvas.md "Built and proven" 7). The rendering became batches with nodes for the selection only
    (canvas-core "Built in slice 2").

15. **Found in slice 3:** the live ticket needed a nonce — two tickets minted for one person in the same second were the same string, and the
    relay (single use) refused the second as a reuse (`{member}.{room}.{level}.{exp}.{nonce}.{hmac}` now). live-relay.md said an agent's avatar
    fades after 10 s; live.md and the record above say 5 — 5 it is. The relay's `resync` makes the island catch up (`ops?after=`), not reload a
    whole board. The socket opens after the first state load, so no batch can fall between the two.

16. **Found in slice 4:** connectors-frames.md placed an agent's frame with no position "right of the extent"; reconciliation 1 had ruled
    below for every agent's item — below it is, and the spec says so now. Prev / Next and the minimap hide below 992 px (the spec said 375).
    Slice 2's 375 proof counted tools hidden behind More; after its synthetic CDP pinch no Playwright `tap()` becomes a click, so it opens
    More with a click.

17. **Found in slice 5:** pen-files.md and history-versions.md both purged items deleted past op_retention_days with their files — slice 5's
    `thumbnails` pass does it (`purge_deleted_items()`), slice 7's `compaction_pass` keeps `bd_compact_ops()` only, slice 10's trash purge uses
    slice 5's `unlink_paths()`. An SVG has two URLs (`/files/{id}` always an attachment, `?inline=1` the canvas's `<img>`): a browser's cached
    inline copy answered a person who opened the file. The item's `data` carries `mime_type`, `byte_size` and `preview` beside `attachment_id`
    and `title`.

18. **Found in slice 6:** comments-notifications.md said the Comment tool is `C`, which slice 4 gave the connector — Shift+C it is. It said "the pins:
    `[{id, item_id, x, y, …}]`" without saying what an item's `x, y` are (the item's top-right corner plus the stored offset; `comments.anchor_x/y`
    are that offset for a thread on an item) and the kit wraps a screen's JSON in `data` (`{data: {board_id, pins}}`). A notice's `record_uuid` is the
    REPLY's id, so `?comment=` may name any comment of a thread and the island flies to the thread's root. The shell's bell was already `#bell` /
    `#bell-count` (sso-shell.md) — `#header-bell` wraps it and polls. A mention needs the person's full display name.

19. **Found in slice 7:** `bd_interval_versions()` (db/011) would have saved a version of a shipped template, which the history spec says never happens → db/015 adds the condition. `summary.counts` holds
    only the op verbs (create, update, delete, restore), so history says "changed", not "moved". The handlers ask the right (`boards.edit`) before the board, so an external with no editing right is told 403, not 404.
    The compaction pass records the local date it ran (`on`) because `worker_passes.started_at` is the real clock even under `BD_WORKER_NOW`. The rail's History panel stays slice 7's placeholder until a slice that owns `rail.php` touches it.

## State

**Phase 1 is written, the checks are green** (`tests/phase1/check_specs.sh`: 66 ok, 0 FAIL; the schema proof 167), and **the owner
approved it as written on 2026-10-10**, the ★ decisions with it. **Phase 2 and slice 1 are built and proven** (2026-10-10); **slice 2, the canvas — the
first exemplar —, slice 3, live — the second —, slice 4, connectors and frames, and slice 5, pen, images and files, are built and proven**
(2026-10-11) — the planning model's slices are done; **slice 6, comments, mentions and notifications, is built and proven** (2026-10-11, a Sonnet 5.5 worker, verified by the planning model); **slice 7, history, versions and restore, is built and proven** (2026-10-11, a Sonnet 5.5 worker); **slice 8, templates, duplicate and export, is built and proven** (2026-10-11, a Sonnet 5.5 worker, db/016).
Next: slices 9–10 and Phase 4, Sonnet 5.5 workers (D16).
