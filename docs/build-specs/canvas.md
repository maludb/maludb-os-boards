# Build spec: the canvas (slice 2 — THE FIRST EXEMPLAR)

What exists at the end: `/boards/{board}` opens the **canvas** — the TypeScript + Konva.js island mounted in the nxl page, with the top bar
(title, the level badge, Share, the board's menu), the left toolbar (Select · Hand · Sticky · Shape · Text — the other tools shown disabled
with their slice), the bottom bar (zoom −/+, Fit, the percentage, Undo/Redo) and **the right rail** (an HTMX panel beside the canvas: the
selection's **Properties** and the **Outline**; Comments and History show "Built by slice 6 / 7"). A person draws sticky notes (Miro's palette),
ten shapes with text and text items; edits text in a DOM overlay; selects, multi-selects and marquee-selects; moves, resizes and rotates (Konva's
Transformer); brings to front or sends to back; locks; copies, pastes and duplicates; undoes and redoes **their own** changes. Every drop is
**one batch** through `POST /boards/{board}/ops` → `bd_commit_ops()`; another person's changes arrive by **polling** `ops?after=` every
`poll_seconds` (the relay is slice 3's). The **outline** page reads the board as a list. The kernel's agents add, change, delete and undo
items through `/items/*` with a **server-side layout** (a grid of stickies in a frame, no arithmetic). The island posts a **thumbnail** for the
cards; opening a board logs `board.open` and keeps `board_recents`. **This slice is the exemplar of every canvas slice** (4 and 5 add tools,
renderers and item actions exactly this way; 9's `items_arrange` reuses the layout engine) and is built by the planning model. The
architecture is the build spec **`canvas-core.md`** — "The island and the page", "client/ — files and build", "The op protocol",
"Coordinates, frames and z", "The doors (PHP)", "Rendering and performance", "375 px and touch", "Exports", "Proving the canvas" — and this
spec names only what this slice builds of it.

Schema (db/006–013, never modified): `board_items`, `board_ops`, `bd_commit_ops()`, `bd_undo_ops()`, `bd_item_json()`, `bd_jsonb_merge()`,
`bd_ops_payload()`, `bd_region_items()` (db/007); `board_recents`, `bd_my_level()` (db/006); `attachments` (record_type `board_thumbnail`),
`boards.thumbnail_attachment_id` (db/008); `bd_require_level()` (db/011); `mcp_board_items`, `mcp_board_changes` (db/012); `bd_board_overview()`,
`bd_board_outline()`, `bd_region_svg()` (db/013). **The database is the referee**: the level and the lock, per-property last-writer-wins, the
idempotent batch, the cascades, the batch, cap and point limits, the inverse, undo of one's own batches only, the box, the count.

## Divergences from the design
1. **Polling only.** The island's `sync/socket.ts` exists as the interface canvas-core names, answering "unavailable", so the poll runs; slice 3
   makes it real. The top bar shows no presence avatars yet.
2. **Item types this slice draws:** `sticky_note`, `shape` (rectangle, round_rectangle, ellipse, triangle, diamond, parallelogram, hexagon, star,
   right_arrow, wedge_round_rectangle_callout — Miro's names) and `text`. A `frame`, `connector`, `drawing`, `image` or `document` that exists
   (an agent's, a later slice's fixture) renders as a grey placeholder box labelled with its type, selectable, movable and deletable — slices 4
   and 5 replace the renderers.
3. **Pasting outside text** makes one sticky per non-empty line (≤ 50), in a grid at the pointer; pasting an image is slice 5's.

## Who may do what
| Action / door | Level / rule |
|---|---|
| `board-view`, `board-outline`, `GET …/state`, `GET …/ops?after=` | `view` (below → 404) |
| `ops_commit` | `edit`, and `full` on a locked board; people only (the canvas's door; agents use `/items/*`) |
| `item_create_batch`, `item_update_batch` | `edit` |
| `item_delete_batch` | `edit`; pauses for an agent (`deletion`) |
| `ops_undo` | own batches on this board within the hour (`bd_undo_ops`) |
| `POST …/thumbnail` | `edit`; at most every five minutes a board (the door answers 429 "not yet" sooner) |

A viewer's or commenter's canvas has no drawing tools and no Transformer (the island reads `data-level`); their forged POST is refused by the
database (42501 → 403).

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `board-view` | `/boards/{board}?x=&y=&zoom=&item=&comment=` | the canvas page: the nxl layout with the sidebar collapsed, `#page-content` holding the top bar, the island's `<div id="board-canvas">` with the mount attributes of canvas-core "The island and the page" (board, level, last seq, member, client id, the CSRF token, the poll seconds, the soft budget, `data-test` only under the proofs), the toolbar, the bottom bar and the rail. `item=` selects and flies to an item; `x,y,zoom` opens there; else the board's start view; else fit the extent. Logs `board.open` once per render and upserts `board_recents`. 375 px: canvas-core "375 px and touch" |
| `board-outline` | `/boards/{board}/outline` | `bd_board_outline()` rendered as HTML (headings, lists; connectors as "A → B"; open comments) — the 375 px reader and the screen reader's view; each item line links to `/boards/{board}?item={id}`; a "Copy as Markdown" button |

The rail partials (`GET /boards/{board}/rail?panel=properties|outline|comments|history&items=`) are doors of this page, not screens: Properties shows
the selection's colour (the palette), text size (S M L XL = 14 18 24 36), alignment, the lock, front/back, and for one item its type, size and
"Set as the start view"; each control submits through the island (one batch), never a form post.

## Files (exactly these)
- `client/` — the sources canvas-core "client/ — files and build" lists for slice 2 (the model, the op protocol, sync's commit / catch-up /
  poll and the socket stub, the stage, culling and level of detail, the sticky, shape, text and placeholder renderers, the select, hand, sticky,
  shape and text tools, the Transformer, the text overlay, the undo stack, the clipboard, the toolbar, zoom and the rail bridge, the test hook),
  `client/package.json`, `client/package-lock.json`, `client/tsconfig.json`, `client/build.mjs`
- `html/assets/boards/boards.{hash}.js`, `html/assets/boards/boards.{hash}.css`, `html/assets/boards/manifest.json` (committed; built by `node client/build.mjs`)
- `bin/check_bundle.php` (the bundle's `source_hash` equals the hash of `client/src/**` + `package-lock.json`; non-zero when stale)
- `html/boards/{view,outline,state,ops,thumbnail,rail}.php`, `html/items/{create,update,delete,undo}.php`
- `app/features/canvas/{queries,present,write}.php`, `app/features/items/{layout,write,fractional}.php`
- `app/views/boards/{canvas,outline}.php` + `app/views/boards/partials/{topbar,toolbar,bottombar,rail,rail-properties,rail-outline,rail-pending}.php`
- `tests/phase3/slice2/{run.sh,lib.php,ops.php,items.php,undo.php,doors.php,json.php,browser.mjs,perf.mjs}`, `tests/fixtures/board-5000.json`

## The doors (canvas-core "The doors (PHP)" is normative; in short)
- `GET /boards/{board}/state` → `{board_id, level, last_seq, item_count, items: [bd_item_json …]}` (every live item; gzip by Apache).
- `GET /boards/{board}/ops?after={seq}` → `{batches: [{seq, member_id, client_id, source, ops, summary}], last_seq, resync}` — ≤ 500 batches;
  `resync: true` when `after` is older than the board's latest `compaction` version's `content_rev` (the client reloads `state`).
- `POST /boards/{board}/ops` (JSON body `{client_batch_id, client_id, ops}`, `X-CSRF-Token`) → `bd_commit_ops(board, me, 'web', …)` →
  `{seq, duplicate, versions, replaced, item_count, warnings}`; refusals by SQLSTATE: 42501 → 403 `not_allowed`, P0002 → 404 `not_found`,
  23514 / 22023 → 422 `refused` with the database's sentence, 23505 → 409 `conflict`. **One `log_activity()` per committed batch** named by its
  verbs: only creates `item.create`; only position fields (`x`, `y`, `parent_id`) `item.move`; only `data.content` `item.text`; only other
  updates `item.update`; only deletes `item.delete`; only restores `item.restore`; anything mixed `item.batch` — `after` = the summary's counts,
  types and box. A duplicate batch logs nothing.
- `POST /boards/{board}/thumbnail` (a PNG ≤ 512 kB, ≤ 480 × 300, from the island — canvas-core "The doors (PHP)") → an `attachments` row (`board_thumbnail`, `record_uuid` = the
  board) under `BD_STORAGE_DIR/thumbnails/`, the board's `thumbnail_attachment_id` replaced and the old file unlinked; served by slice 5's
  `/files/{id}` door — until then `html/boards/thumbnail.php` serves `GET` of the current one behind the wall.

## The agents' item actions (`/items/*`)
- `item_create_batch`: `texts[]` → one item each (`type` sticky_note | shape | text; an empty `texts` makes one empty item); `layout` grid
  (columns = `columns` or ⌈√n⌉) | column | row; sizes by type (sticky 200 × 200, shape 200 × 120, text 300 × 60 — or `width`/`height`); gap 20;
  **the origin**: inside `frame` → from the frame's top-left + 40 (frame-relative, `parent_id` set; **the frame grows in the same batch** when the block
  would run past its edge — canvas-core "The doors (PHP)"); else `x`,`y` = the first item's centre; else **empty space**: the block's top-left
  at (extent.x1, extent.y2 + 200) from `bd_board_overview().extent`, or (0, 0) on an empty board; `color` → `style.fillColor` (sticky, shape) or `style.textColor` (text); `shape` → `data.shape`. The ops are
  built by `items_layout()` and committed as ONE batch with `source` = `agent` under a run token, `command_bar` from the command bar, `web`
  otherwise. `did`: "Added 5 stickies in a grid in Went well". `record_id` = the seq.
- `item_update_batch`: the same fields to every listed item, except `text` and `x`/`y` (one item only — refused with the sentence otherwise);
  `frame` = an item id or `none` (to the canvas, absolute again); `front` yes → `z` after the board's highest, no → before its lowest;
  `color` as above; `locked`.
- `item_delete_batch`: the items (`keep_children` for frames) → one `delete` op; `did` names the count and that the inverse can undo it.
- `ops_undo`: my last `count` batches on this board within the hour that are not undo batches and not undone already → `bd_undo_ops`.
- Every item action: the board gate at `edit`, items must be on the board (P0002 → 404 with the id), `bd_guard()`, `log_activity()` with the
  manifest's event (`item.create`, `item.update`, `item.delete`, `ops.undo`), `bd_done()`.

## Query functions (signatures fixed)
- `board_state(PDO $pdo, string $boardId): array` · `ops_after(PDO $pdo, string $boardId, int $after, int $limit = 500): array{batches, last_seq, resync}`
- `board_for_canvas(PDO $pdo, string $boardId): ?array` (the `mcp_boards` row + the start view + the thumbnail's age) · `board_outline_html(PDO $pdo, string $boardId): ?string`
- `rail_selection(PDO $pdo, string $boardId, array $itemIds): array` (`mcp_board_items` rows)
- writes: `commit_ops(PDO $pdo, string $boardId, int $memberId, string $source, ?string $clientId, ?string $batchId, array $ops, ?int $runId, ?string $requestId): array` ·
  `undo_own(PDO $pdo, string $boardId, int $memberId, int $count, string $source, ?int $runId): array` ·
  `record_open(PDO $pdo, string $boardId, int $memberId): void` · `save_thumbnail(PDO $pdo, string $boardId, int $memberId, string $pngBytes): int`
- the layout engine: canvas-core's `bd_layout(string $layout, int $n, array $opts): array` (centres) and, over it, `items_layout(PDO $pdo, string $boardId, array $spec): array` (create ops) · `z_between(?string $a, ?string $b): string` and
  `z_after(PDO $pdo, string $boardId): string`, `z_before(PDO $pdo, string $boardId): string` (`fractional.php`, the same algorithm as the client's —
  canvas-core "Coordinates, frames and z") · `batch_event(array $summary, array $ops): string` (the log event's name)

## MCP tools this slice makes real
`board_overview`, `board_search`, `board_read_region`, `item_get`, `board_outline` (their SQL is db/012–013; the outline page renders
`bd_board_outline()`, the canvas's state is the view mcp_board_items).

## Manifest rows claimed
Screens (2): `board-view`, `board-outline`
Actions (5): `ops_commit`, `item_create_batch`, `item_update_batch`, `item_delete_batch`, `ops_undo`

## Activity log events
`board.open` (once per render of `board-view`, with the view it opened at), `item.create`, `item.update`, `item.move`, `item.text` (the item and
the first 200 characters of the new text), `item.delete`, `item.restore`, `item.batch`, `ops.undo` (the seqs undone), `board.thumbnail` (no
payload but the size), `screen.view` (`board-outline`). Every row carries `board_id`; drags, cursors and the poll are never logged.

## Out of scope for this slice
Live updates, presence and cursors (3); connectors, frames' drawing, the navigator and the minimap (4); pen, images and files (5); comments (6);
history and versions (7); exports and templates (8); `items_arrange` (9). The rail's Comments and History panels say "Built by slice 6 / 7".

## Proof (`tests/phase3/slice2/run.sh`: scratch database `bd_dev2`; php -S on 9101, the fake kernel on 9102, the fake MaluDB/MaluMail on 9103; then the user-level Apache on 9107; Playwright with real pointer events; `bin/check_bundle.php`; the registry `--check`)
1. The doors: `state` for an editor, a viewer, a stranger (404); a batch committed, retried (one seq, `duplicate`), refused below `edit` (403), on a
   locked board for an editor (403), malformed (422), with an id taken (409); `ops?after=` returns the batches after a seq in seq order and
   `resync` after a fixture compaction; one log row per batch with the right event name (eight fixtures, one per verb, and a mixed one).
2. The item actions with an agent's action token (JSON): five stickies in a grid in a frame (frame-relative, `parent_id` set, the summary's box
   inside the frame), in empty space below the extent, at x,y; update colour and front on three items; text on two refused; delete two
   (the registry marks `deletion`); undo my last two (an undo batch, the items back); another member's batch not undoable.
3. Playwright, Priya at 1280: pick Sticky, click → a sticky; type text (the overlay), click away → one `item.text`; drag it (real mouse down /
   move / up) → one `item.move` batch; resize and rotate with the Transformer handles; multi-select with shift and a marquee; Ctrl+D duplicates;
   copy/paste offsets by 20; front/back; lock (the Transformer disappears; a drag does nothing); Ctrl+Z / Ctrl+Shift+Z undo and redo my own
   batches; paste three lines of text → three stickies.
4. Two contexts (Priya and Bea, both edit): Bea's view shows Priya's sticky within `poll_seconds` + 0.5 s; both move the same sticky's x and
   colour at once → both survive (last-writer-wins per property); Bea overwrites Priya's newer text → the "You replaced Priya's edit — Undo"
   toast in Bea's view.
5. A viewer (Dana as commenter) sees no tools and no Transformer; the outline page renders at 375 with every item; `item=` flies to it.
6. The thumbnail: the island posts one after an edit; a second within five minutes 429; the card in `/boards` shows it.
7. `board.open` once per open, `board_recents` updated; the rail's Properties changes colour as one batch.
8. 375 × 740 with touch: pinch zoom, two-finger pan, tap the Sticky tool then tap the canvas → a sticky; long-press selects; no horizontal scroll.
9. Performance (`perf.mjs`, recorded in the run's summary): the 5,000-item fixture — `state` answers in < 1.5 s at the server, the first frame
   draws in < 4 s headless, and a 2-second pan's frames have a median ≤ 33 ms and none over 100 ms (`window.__boards.frames()`; canvas-core
   "Rendering and performance"); gates, measured on this VM's software Canvas2D.
10. `bin/check_bundle.php` passes, and fails after touching a source; no console errors at 375 and 1280; registry `--check` (2 screens, 5 actions built).

## Decisions taken here
1. Undo's "my last N batches" excludes undo batches and batches a later undo of mine already reversed.
2. `item_update_batch` refuses `text`, `x` and `y` with more than one item ("Give one item to set its text or its place").
3. (Reconciled with canvas-core by the planning model:) an agent's empty-space origin is below the extent — (extent.x1, extent.y2 + 200);
   a frame given but too small grows in the same batch.
4. `color` maps to `fillColor` for stickies and shapes, `textColor` for text.
5. The thumbnail is ≤ 480 × 300 and ≤ 512 kB (canvas-core), at most every five minutes a board; the door serves it until slice 5's file door.
6. The outline page's "Copy as Markdown" copies `bd_board_outline()` verbatim.
7. `resync` is decided by the latest compaction version's `content_rev`.

## Open questions
None.

## Built and proven (2026-10-11)
The canvas stands — THE FIRST EXEMPLAR, the one every canvas slice extends. **The page** (`/boards/{board}`): the nxl shell with the menu
collapsed and the footer gone, sized to the window between the header and the command bar; the top bar (back, the title, the level badge, Locked,
Outline, Share for `full`, the panel's toggle, the board's menu — Export, Duplicate and Save as template shown disabled for slice 8); the
island's mount with canvas-core's attributes; the left toolbar (Select · Hand · Sticky · Shape with Miro's ten · Text; the later tools disabled
with their slice) that becomes the bottom bar at 375 px with a "More"; the bottom bar (Undo/Redo, zoom −/+, the percentage, Fit); the right rail
— Item (the selection's Properties: Miro's sticky palette, fills and text colours, S M L XL, alignment, front/back, Lock/Unlock, Delete, and for
one item its size, centre and "Set as the start view"), Outline, Comments (slice 6), History (slice 7) — a bottom sheet at 375 px. An HTMX
navigation into a board is a full page load; leaving the canvas over HTMX takes the island down. Without JavaScript the page links the outline.
**The island** (`client/`, TypeScript 5.9.3 + Konva 9.3.22, built by esbuild 0.25.12 into `html/assets/boards/boards.<hash>.js/.css`, committed
with `manifest.json`): draw stickies, Miro's ten shapes and text; edit text in a DOM overlay (auto-fitting a sticky's words); select, shift-
select and marquee; move (a drop re-parents into the frame under the item's centre), resize and rotate with Konva's Transformer; front/back;
lock; Ctrl+C / Ctrl+V / Ctrl+X, Ctrl+D (24 further on), Delete; paste outside text as a sticky a line (≤ 50); undo/redo of MY batches (Ctrl+Z,
Ctrl+Shift+Z, the bar); the wheel pans and Ctrl+wheel zooms (0.05–8); one finger pans the empty canvas, two pinch and pan, a long press opens
the Item sheet, a double tap edits; every gesture ONE batch through `POST /boards/{board}/ops` with its local inverse kept for a refusal (the
database's sentence in a toast), a retry with the same `client_batch_id` after 1, 3, 10 s, then "Not saved — check your connection"; another
member's batches arrive by the poll (`ops?after=`, `poll_seconds`) with the per-property rule (a remote field gives way to my pending or later
write); "You replaced Priya's edit — Undo"; the soft budget's banner once a session; a viewer or commenter selects and reads, with no tool and no
Transformer. **The outline page** reads the board as a list (every line opens the canvas at its item; "Copy as Markdown" is `bd_board_outline()`).
**The doors**: the state (one statement, gzip), the catch-up (≤ 500 a page, `resync` after a compaction), the commit, the rail, the thumbnail (an
editor's 480 × 300 PNG at most every five minutes, the card in `/boards` showing it). **The agents' item actions** (`/items/create|update|delete|
undo.php`) place with the layout engine (`bd_layout()` + `items_layout()`, in a frame growing to hold the block, at x/y, or below the board), map
colours and Miro's shape names, commit ONE batch with source agent / command_bar / web, and answer the seq. The registry reads **13 screens and
23 actions built**.

**Proven** by `tests/phase3/slice2/run.sh` (scratch `bd_dev2`): **191 checks under php -S, 143 under a real Apache** — the bundle 2 (current;
stale after a source is touched; current again), doors 46, the commit 33 (refusals 403/404/409/422 in the database's words, the retry, per-property
last-writer-wins, `replaced`, one log row a batch by its verbs, the cap, and the z keys: PHP's and TypeScript's twins make the same 14 cases),
the agents' actions 31, undo 17, JSON 6, the browser 48 under php -S (real mouse, keyboard and CDP touch; two members, the poll within 1.7 s;
a commenter; 375 px; JavaScript off; no console error) and 6 under Apache (no test hook there: real input asserted on the database), the
performance gate 6 (php -S), the registry 2 — then slice 1's suite (188 under both) and Phase 2's (258 / 266) — both follow what is built. The
kit (87), the schema (167) and Phase 1's checks (66) green. **The performance gate on the 5,000-item fixture** (headless Chromium, software
rendering, this VM): the state in ~0.6 s, the first frame in ~1.7 s, and a 2,000 px pan at the opening view (all 5,000 at the low detail), at
100 % in a cluster and at 45 % across clusters (the most text) — **a median frame of 16.7 ms (60 fps) in every view**, the island's own work a
frame 3–9 ms (median).

**Decisions taken in the build:**
1. **Where the two specs disagreed, canvas-core won** (as reconciliation 1 already ruled): the layout's gap 24 (this spec said 20), a text cell
   200 × 60 (300 × 60), the paste and duplicate offset 24 (20), the rail's panel `item` with `ids=` (this spec said `properties` / `items=`; the
   manifest agrees with canvas-core). **One exception, this spec's door table:** the commit answers an item id another item holds **409
   conflict** (the island tells a collision from a rule); everywhere else 23505 stays the kit's 422.
2. **A block in a frame goes below what the frame already holds** (gap 24 from its left, its children's bottom + 24 — under the title band +
   24 when empty); canvas-core's "top-left + (gap, 60 + gap)" would have stacked a second call on the first. The frame grows to hold the block
   and a gap, its top-left fixed (its children are relative to it).
3. **An agent's batch id is md5 of the run's request id, the action's path and its parameters** (canvas-core decision 6, corrected): every call of
   one agent run carries the run's request id, so the request id alone would have answered a second, different call in the same turn
   "duplicate" and done nothing. A retried call (same parameters) still commits once and answers the first commit's items.
4. **`style` and `data` are always JSON objects at the database**: `commit_ops()` turns an empty or list PHP array into an object. Found by the
   proof — PHP decodes the island's `{}` into `[]`, and a text item made with no colour had `style: []`; the column's check would have refused
   both.
5. **The thumbnail is not logged** (canvas-core decision 10; this spec's event list named `board.thumbnail`). An editor's island also posts
   one a few seconds after opening a board whose preview is missing or older than five minutes, so boards changed by agents get one.
6. **How items are drawn** (canvas-core "Built in slice 2"): one draw function a type over the item's own box; batches paint what is not
   selected, a selected item has a node of its own whose scene function is the same draw function; text laid out by the island's own cached
   layout (Konva.Text is not used); the background is a CSS pattern; three canvases. Found by the performance gate — a Konva node an item cost
   42 ms a frame at 5,000 items; the batches cost 3–4 ms.
7. **The performance gate on this VM** (decision 9 read for a shared machine): an EMPTY animation-frame loop here shows single frames of
   100–330 ms now and then (other sessions' load), printed in every run. So the gate is the frame interval's median ≤ 33.4 ms (30 fps — intervals
   come in 16.7 ms steps), its 95th percentile ≤ 100 ms and the island's own work in every frame ≤ 100 ms, with up to three attempts a view
   (every attempt printed; a regression fails all three). The longest interval is printed beside the empty loop's. 60 fps in a real browser is
   the owner's check.
8. **"Set as the start view"** goes through the island as `board_update` (`start_view` "x y zoom", JSON) — the canvas stays open.
9. **A text item left empty when it was just made goes again** when its editor closes (one more batch, a delete). A sticky or a shape stays.
10. **Selected items are always drawn and held**: the cull keeps them, so the Transformer never loses its node off-screen.

**Found for the owner:** the kernel's actions server splits a repeated parameter on commas before posting it (`mcp/application_actions.py`,
`texts[]` → `split(",")`), so an agent's sticky text cannot contain a comma through `item_create_batch`. Boards takes the list as sent and never
dedupes it; the fix is the kernel's (a JSON list, or a separator agents are told of).
