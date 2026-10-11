# Build spec: the canvas core — the island, the op protocol, the doors (design §3.1–§3.5 of the decision record; design §6.2–§6.3, §8; D4, D5, D7, D8, D13)

The board screen is the one place Boards leaves HTMX: a TypeScript + Konva.js **island** mounted into one `<div>` of an ordinary nxl page.
Everything else on the page — the header, the share button, the right rail's panels — is PHP + HTMX. The island draws, edits and syncs; it
never decides a rule. **Every write it makes is a batch through `bd_commit_ops()`** (db/007), the same function the agents' item actions
call, so the wall, the cap, the locks, the cascades and last-writer-wins live in one place. Slice 2 (`canvas.md`) builds this spec's core
and is THE FIRST EXEMPLAR; slices 3, 4, 5, 6 and 8 extend the island in the places named below and nowhere else.

## The island and the page

`html/boards/view.php` (screen `board-view`) answers `GET /boards/{board}`: `bd_board_or_404($pdo, $board, 'view')` (walls-and-sharing.md),
an upsert of `board_recents (member_id, board_id, visited_at)`, **one** `log_activity($pdo, 'board.open', 'board', $board, ['board_id' =>
$board, 'screen' => 'board-view'])` per render (never per poll), then `render_screen()` with `app/views/boards/canvas.php`.

The page is the nxl shell with three regions: `#board-header` (title, `#board-presence` avatars, the level badge, Share for `full`, the
menu: Settings, History, Outline, Export, Duplicate, Save as template), the mount, and `<aside id="board-rail">`. **The mount** is exactly:

```html
<div id="board-canvas" data-board="{uuid}" data-title="{title}" data-level="view|comment|edit|full" data-locked="0|1"
     data-last-seq="{boards.last_seq}" data-member="{member id}" data-member-name="{display name}" data-client="{uuid v4, minted per render}"
     data-csrf="{csrf_token()}" data-ticket="{bd_live_ticket() — absent when live_enabled is false}" data-live-url="/live"
     data-state-url="/boards/{board}/state" data-ops-url="/boards/{board}/ops" data-ticket-url="/boards/{board}/live-ticket"
     data-thumbnail-url="/boards/{board}/thumbnail" data-export-url="/boards/{board}/export" data-upload-url="/files/upload.php"
     data-file-url="/files/" data-rail-url="/boards/{board}/rail" data-poll-seconds="{bd_settings.poll_seconds}"
     data-soft-budget="{item_soft_budget}" data-hard-cap="{item_hard_cap}" data-batch-max="{batch_max_ops}"
     data-start-view='{"x":…,"y":…,"zoom":…}' data-view="{x y zoom from ?x&y&zoom}" data-focus-item="{?item}" data-focus-comment="{?comment}"
     data-test="1"><noscript>…a link to the outline…</noscript></div>
<script src="/assets/boards/{manifest.json file}" defer></script>
```

`data-client` is a fresh uuid v4 per render (one per tab; `client_id` in every batch). `data-test="1"` is written **only** when
`tests/dev_router.php` defined the constant `BD_TEST_HOOKS` before it required the page and `!app_is_prod()`; nothing else ever sets it.

**Events the island fires** on the mount (they bubble; HTMX panels listen with `hx-trigger="… from:body"`), each with a JSON `detail`:

| Event | detail | Who listens |
|---|---|---|
| `boards:select` | `{ids, items: [{id, type}]}` | the rail's Item panel (`hx-get` `/boards/{board}/rail?panel=item&ids=…`) |
| `boards:open-comment` | `{comment_id}` or `{item_id}` or `{x, y}` (a new pin) | the rail's Comments panel (slice 6) |
| `boards:open-item` | `{id}` | the rail's Item panel (double-click on an image or a file) |
| `boards:changed` | `{seq, source, member_id, counts}` after every applied batch, local or remote | History and Comments panels, `throttle:2s` |
| `boards:level` | `{level}` when the relay pushes a new level | the header badge (an `hx-get` of `/boards/{board}/rail?panel=badge`) |
| `boards:live` | `{state: "live" | "polling" | "offline", peers: n}` | the header's presence and the "Live updates paused" banner |

**Events the island listens for** (fired by panels on `document.body`): `boards:focus` `{id}` or `{x, y, zoom}` (fly there),
`boards:command` `{kind: "style" | "lock" | "front" | "back" | "delete" | "text-size", ids, value}` (the Item panel's buttons — the island
turns each into a batch so it is optimistic and undoable like any edit), `boards:reload` (after a version restore: re-read the state).

**The right rail** is `html/boards/rail.php` (`/boards/{board}/rail?panel=item|comments|history|outline|badge`, a partial door, `view`
level): tabs Item (slice 2), Comments (slice 6), History (slice 7), Outline (slice 2). On a desktop it sits beside the canvas (the
canvas narrows); at 375 px it is a bottom sheet. It is never a `.modal`.

## client/ — files and build

```
client/package.json        {"name": "boards-client", "private": true, "scripts": {"build": "node build.mjs", "check": "tsc --noEmit"},
                            "dependencies": {"konva": "<exact>"}, "devDependencies": {"esbuild": "<exact>", "typescript": "<exact>"}}
client/package-lock.json   committed
client/tsconfig.json       strict, target ES2020, module ESNext, noEmit (esbuild emits)
client/build.mjs           esbuild: src/main.ts → one minified IIFE, target es2020, no source map; sha256 of the output → the name
                           boards.<first 10 hex>.js in ../html/assets/boards/; older boards.*.js removed; manifest.json
                           {"file": "boards.<hash>.js", "source_hash": "<sha256>", "built_at": "<ISO>"}
client/src/main.ts                 reads the mount's data-*, builds the store, the stage, the tools, the sync; fires/listens the events
client/src/model/types.ts          Item, ItemType, Style, Data, Op, Batch, CommitAnswer, Level
client/src/model/store.ts          items by id, children by frame; applyOp() per property (style/data merged by key, null removes —
                                   bd_jsonb_merge's rule); inverseOf(op); snapshot(); subscribe()
client/src/model/fractional.ts     keys between two keys over [0-9A-Za-z] in BYTE order (0-9 < A-Z < a-z — the z column is COLLATE "C";
                                   JS string comparison of this alphabet is byte order)
client/src/model/geometry.ts       centre ↔ box, frame-relative ↔ absolute, the rotated bounding box (the server's formula), hit tests
client/src/sync/commit.ts          batches, client_batch_id, the in-flight queue, reconcile, roll back, the "replaced" toast
client/src/sync/catchup.ts         ops?after=, the applied-seq set (the last 2,000), resync → state reload
client/src/sync/socket.ts          the relay (live-relay.md "The client side") — slice 3
client/src/sync/poll.ts            the poll while not live
client/src/sync/undo.ts            the per-person undo/redo stack
client/src/render/stage.ts         Konva.Stage, pan, zoom (0.05–8), the camera, screen ↔ canvas
client/src/render/layers.ts        background (dots | grid | none from boards.background), frames, items, selection (Transformer),
                                   presence (cursors, drag ghosts, agents' avatars — slice 3)
client/src/render/items/sticky.ts, shape.ts, text.ts (slice 2), connector.ts, frame.ts (slice 4), drawing.ts, image.ts, document.ts (slice 5)
client/src/render/cull.ts          only nodes whose box meets the viewport + 25 % are in their layer
client/src/render/lod.ts           below zoom 0.4: cached flat nodes, no text; image size by on-screen width (slice 5 adds images)
client/src/tools/select.ts, hand.ts, sticky.ts, shape.ts, text.ts (slice 2); connector.ts, frame.ts (slice 4); pen.ts, highlighter.ts,
                 eraser.ts, image.ts (slice 5); comment.ts (slice 6)
client/src/ui/toolbar.ts, zoom.ts, textedit.ts (the DOM overlay editor), rail.ts (the event bridge), toast.ts (slice 2);
            presence.ts (slice 3); minimap.ts (slice 4); export.ts (slice 8)
```

Versions are **exact** (no `^`, no `~`): slice 2 runs `npm install --save-exact konva@9 esbuild@0.25 typescript@5` once and commits the
lock; a later upgrade is its own commit with the bundle rebuilt. Node is a development tool: the bundle is committed, the installer never
runs npm. **`bin/check_bundle.php`** computes the same `source_hash` — sha256 over every file of `client/src/` sorted by path (each as
`path NUL content NUL`) followed by `client/package-lock.json` — and exits 1 when `html/assets/boards/manifest.json`'s `source_hash`
differs or its `file` is missing. Every slice's proof runs it; `view.php` reads `manifest.json` for the script tag.

## The op protocol

`POST /boards/{board}/ops` (JSON, `X-CSRF-Token`), the body:

```json
{"client_batch_id": "<uuid v4>", "client_id": "<data-client>", "ops": [
  {"op": "create", "item": {"id": "<uuid v4, minted by the client>", "type": "sticky_note", "parent_id": null, "x": 0, "y": 0,
                            "width": 200, "height": 200, "rotation": 0, "z": "a3", "style": {"fillColor": "light_yellow"},
                            "data": {"content": [{"type": "text", "text": {"content": "Idea", "link": null}, "plain_text": "Idea"}]},
                            "start_item_id": null, "end_item_id": null, "locked": false}},
  {"op": "update", "id": "<uuid>", "fields": {"x": 120, "y": 40}, "base_version": 7},
  {"op": "delete", "id": "<uuid>"},  {"op": "delete", "ids": ["…"], "keep_children": true},
  {"op": "restore", "ids": ["…"]}]}
```

These are exactly `bd_commit_ops()`'s ops (db/007): `update.fields` ⊂ {x, y, width, height, rotation, z, parent_id, style, data,
start_item_id, end_item_id, locked}; style and data merge by top-level key, a JSON null removes a key. The answer (200) is the function's
JSON plus the batch's inverse: `{"ok": true, "seq", "duplicate", "versions": {id: version}, "replaced": [{id, by}], "item_count",
"warnings": [], "inverse": [...]}` — `inverse` read from `board_ops` after the commit. A refusal is the kit's JSON error with the
database's sentence (the mapping in "The doors").

**On the client:**
- **Optimistic.** A gesture builds one batch, computes its local inverse from the store, applies it, then POSTs. One batch is in flight per
  tab; later gestures queue behind it, in order. On 200 the store takes the answer's `versions`; the batch's `seq` joins the applied set.
- **Refused** (4xx): the local inverse is applied (the gesture rolls back) and `toast.ts` shows the answer's sentence.
  **Unreachable** (network, 5xx): retried with the **same** `client_batch_id` after 1, 3 and 10 s (the server answers a retry's first
  seq, `duplicate: true`), then rolled back with "Not saved — check your connection".
- **Remote batches** (the socket's `ops`, or the poll's `batches`) are applied **in arrival order**: skipped when `client_id` is mine
  (the echo — its seq is already applied from the POST's answer) or when the seq is in the applied set; otherwise each op is applied to the
  store and the seq recorded; `data-last-seq` = the highest. A remote change to an item I am dragging lands in the store; my drop wins
  afterwards (last writer).
- **Catch-up** `GET /boards/{board}/ops?after=<last_seq>` → `{"batches": [{seq, member_id, client_id, source, ops, summary, at}],
  "last_seq": n, "resync": false}` (≤ 500 batches a page; `"more": true` asks again). Because `bd_commit_ops()` takes the board's row lock
  first, seq order IS commit order within a board, so `after=<last_seq>` is exact. `"resync": true` (the seq is older than the retained
  ops, D6) — or the relay's `resync` — reloads `GET /boards/{board}/state` and rebuilds.
- **Gestures commit once:** a drag commits on drop (one update per moved item, one batch); resize and rotate on transform end; text on blur
  (one `update` of `data.content` — and `height` when a sticky's text auto-fits — per editing session); paste and duplicate (Ctrl+D) are
  one batch of creates with new ids offset by 24; Delete is one batch.
- **"You replaced Maria's edit — Undo"**: when the answer's `replaced` names an item, the toast names `by` (from the state's `people`
  map; "someone" when absent) and its Undo applies that batch's inverse.
- **Undo/redo** (Ctrl+Z, Ctrl+Shift+Z, the toolbar): a per-tab stack of the answers' `{seq, inverse}` (100 deep). Undo posts the inverse
  as a new batch (a new `client_batch_id`) and pushes its own answer's inverse onto redo; any new gesture clears redo. It never touches
  another person's batch: only batches this tab committed are on the stack.

**`GET /boards/{board}/state`** → `{"board": {id, title, level, is_locked, background, start_view, item_count}, "items": [bd_item_json …],
"last_seq": n, "people": {member_id: {name, kind}}, "server_time"}` — items and `last_seq` from ONE statement (one snapshot); `people` =
every member named by the items' `created_by`/`updated_by` and the board's permission rows.

## Coordinates, frames and z

- **x, y are the centre**; under a frame (`parent_id`) they are relative to the frame's top-left. The server keeps the absolute `bbox`
  (rotation included); the client computes the same box only for culling and hit tests.
- **Frames are top-level and unrotated**: the Transformer offers no rotation handle on a frame and a frame is never dropped into a frame.
  A frame is a `Konva.Group` on the frames layer, below every other item; its children render inside it, so a frame's move is one
  `update` and the children follow.
- **Into and out of a frame**: when a dragged item's centre is released inside a frame's box (the topmost frame under it), its update
  carries `parent_id` = that frame and frame-relative x, y; released outside every frame, `parent_id: null` and absolute x, y. The frame
  tool dragged around items creates the frame and re-parents what its box holds, in one batch.
- **z** is a fractional key: a new item takes a key after the board's highest; Bring to front / Send to back take a key after the highest
  / before the lowest; render order sorts by z (frames among frames, items among items).

## The doors (PHP)

| File | Door | Level | What it does |
|---|---|---|---|
| `html/boards/view.php` | screen `board-view` | view | above |
| `html/boards/state.php` | `GET …/state` | view | the state, gzip-encoded (`ob_gzhandler`) when the browser accepts it |
| `html/boards/ops.php` | `GET …/ops?after=` | view | the catch-up page |
| `html/boards/ops.php` | `POST …/ops` = action `ops_commit` | edit (the function's) | `bd_handler_begin(); require_human();` the JSON body; `bd_guard($pdo, fn() => bd_commit_ops(board, member, 'web', client_id, client_batch_id, ops))`; the inverse; **one** `log_activity()` |
| `html/boards/rail.php` | `GET …/rail?panel=` | view | the rail's partials |
| `html/boards/thumbnail.php` | `POST …/thumbnail` | edit | a PNG ≤ 512 kB, at most 480 × 300, at most one per board per 5 minutes (else 429); stored as an attachment (`record_type 'board_thumbnail'`, `record_uuid` = the board) replacing the previous (its file unlinked); `boards.thumbnail_attachment_id` set; not logged (a cache) |
| `html/boards/export.php` (slice 8) | `GET …/export[?frame=]` | view | the SVG dialect: `bd_region_svg()` over a frame, or over the board's extent (from `bd_board_overview()`) when the board holds ≤ 400 items — above that, 422 "Export a frame instead"; `Content-Type: image/svg+xml`, `Content-Disposition: attachment`; logs `board.export` `{format: svg, frame}` |
| `html/boards/export.php` | `POST …/export` | view | `{format: "png", scope: "view" | "frame" | "selection"}` from the client: logs `board.export`; 204 |

**The canvas's log event** is named by the batch's verb: only creates → `item.create`; only updates whose fields ⊂ {x, y, parent_id, z}
→ `item.move`; only updates touching `data.content` → `item.text` (the first 200 characters of the new text in `after`); other updates
→ `item.update`; only deletes → `item.delete`; only restores → `item.restore`; anything mixed → `item.batch`. `entity_type 'board'`,
`board_id` = the board, `after` = `{seq, counts, types, box}` (the batch's `summary`).

**Refusals** — `bd_guard()` turns the database's SQLSTATE into the answer, with the function's own sentence: `P0002` → 404, `42501` → 403,
`23514`, `23505`, `22023`, `P0001`, `23503` → 422. The kit's `bd_guard()` reads P0001/23514/23503/23505/P0002 today; **`42501` (→ 403) and
`22023` (→ 422) are added to it in Phase 2** (sso-shell) because `bd_commit_ops()` and every `bd_*` function of db/011 raise them.

**The agents' item actions** (`html/items/*.php`, manifest "The canvas", "Connectors and frames", "Agents"): `bd_handler_begin()`, the
params read with the kit's readers (`req_val`, `request_list`, `bd_choice`, `bd_int`), turned into ops by
`app/features/items/ops.php`, and committed by `bd_commit_ops()` with **source** `'agent'` (an action token carrying a run id),
`'command_bar'` (a person's action token), or `'web'`. **`client_batch_id`** = the uuid made from md5 of the request id
(`agent_run_request_id() ?? request_id()`), so a retried agent call commits once. `record_id` = the batch's seq; the log event is the
manifest's (`item.create`, `item.update`, `item.delete`, `ops.undo`, `item.connect`, `item.frame`, `item.move`).
- `create.php` — `type`, `texts[]` → one create per text (`data.content` = one plain run, `app/features/items/richtext.php`
  `bd_rich_text_runs()`), `color` → `style.fillColor` (sticky, shape) or `style.textColor` (text), `shape` → `data.shape`; positions from
  the layout engine; z keys after the board's highest (`app/features/items/fractional.php`, the PHP twin of `fractional.ts`).
- `update.php` — `text`, `x`, `y` only with one item; `color` as above; `frame` → `parent_id` with the coordinates converted (the item's
  absolute centre from its `bbox` minus the frame's top-left); `front`/`back` → a z key; `locked`.
- `delete.php` — one `delete` op with `ids` and `keep_children`. `undo.php` — the caller's last `count` batches on the board within the
  hour that no later batch undoes (`undoes_seq`), through `bd_undo_ops()`.

**The layout engine** `app/features/items/layout.php` — `bd_layout(string $layout, int $n, array $opts): array` of centres. Cells are the
item's size (sticky 200 × 200; text 200 × 60; shape 200 × 120; or `width`/`height`), gap 24 (`cluster`: 12). `grid` has `columns` or
`ceil(sqrt(n))` columns; `row` one row; `column` one column; `cluster` a tight grid. **Origin:** `x`, `y` (the first item's centre) when
given; else inside `frame`, from its top-left + (gap, 60 + gap) — the 60 is the frame's title band — growing the frame's width and height
in the same batch when the block would run past its edge; else **empty space**: the block's top-left at (extent.x1, extent.y2 + 200) from
`bd_board_overview()`'s extent, or (0, 0) on an empty board.

## Rendering and performance

- **The budget**: the answer's `warnings` (past `item_soft_budget`, 4,000) show once per session as a banner; past `item_hard_cap`
  (10,000) a create is refused by the database and rolled back like any refusal. Miro's own guidance is 5,000; the canvas is built for it.
- **Culling** (`cull.ts`): on every camera change (`requestAnimationFrame`-throttled) and store change, only nodes whose box meets the
  viewport plus 25 % are attached to their layer.
- **Level of detail** (`lod.ts`): below zoom 0.4 stickies and shapes are cached flat rectangles without text; text is drawn only when
  its on-screen size is ≥ 6 px; drawings are cached bitmaps once drawn; an image loads `/files/{id}/256`, `/1024` or `/2048` by its
  on-screen width (the original only from the Item panel's Download).
- **The target** is 60 fps panning a 5,000-item board on an ordinary laptop. The proof measures headless Chromium (software rendering)
  on this host with the fixture `tests/fixtures/board-5000.json`: a 2,000 px pan over 60 frames, frame times from `window.__boards.perf()`
  — median ≤ 33 ms and no frame over 100 ms; the numbers are printed. The 60 fps check is the owner's, in a real browser.

## 375 px and touch

- **One finger** on empty canvas pans; on a selected item moves it; a **tap** selects; a **long-press** (500 ms) selects and opens the
  Item panel; **two fingers** pinch-zoom about their midpoint and pan; a **double-tap** on a sticky, shape or text opens the text editor
  overlay, kept above the on-screen keyboard (`visualViewport`).
- The toolbar collapses into a bottom bar — select, sticky, shape (menu), text, comment, More (connector, frame, pen, image) — with 44 px
  targets; the zoom control and undo/redo sit above it on the right.
- The rail is a bottom sheet (40 % / 90 %, a drag handle); Outline is always a header link at 375 px.
- The canvas is fixed inside the content area with `touch-action: none` on the canvas alone: no horizontal page scroll, the page itself
  never zooms.

## Exports

- **PNG** (`ui/export.ts`, slice 8): `stage.toDataURL({pixelRatio: 2, …})` over the view, a frame's box or the selection's box, rendered
  with culling and LOD off for that box; downloaded as `{board title} – {frame title}.png`; then `POST /boards/{board}/export` for the log.
- **SVG**: the door above. It is **the SVG dialect** — the board's structure, what an agent reads — not a styled picture; the menu calls it
  "SVG (structure)". A styled SVG is Extended.

## Proving the canvas

- Playwright from the kernel's read-only install, exactly as `/srv/apps/consultant_tracking/tests/phase2/browser.mjs`:
  `createRequire('/var/www/web/node_modules/')`, `chromium.launch()` (headless Chromium is installed), sessions opened by a hand-off
  minted the kernel's way with the scratch `ACTION_TOKEN_KEY` and `bin/dev_directory.json`'s claims, under `php -S` with
  `tests/dev_router.php` on the slice's port.
- **`window.__boards`** exists only when the mount has `data-test="1"`: `snapshot()` (the store's items), `lastSeq()`, `level()`,
  `applied()` (the applied seqs), `waitFor(seq)` (a promise), `screenOf(id)` (an item's centre and its Transformer handles in page pixels),
  `perf()` (frame statistics), `commit(ops)` (setup only — the same path as a gesture). Nothing else reads it.
- **Real input**: `page.mouse` down/move/up for drags and the Transformer's handles (resize, rotate); `page.touchscreen.tap` and CDP
  `Input.dispatchTouchEvent` with two touch points for pinch; `page.keyboard` for text and shortcuts. Assertions read `snapshot()` and,
  independently, the database (`psql` on the scratch DB).
- **A second browser context** (another member) opens the same board and waits for the first context's seq — by the poll in slice 2, by
  the socket in slice 3 — then asserts the item's position, colour and text.
- Every slice that touches the island also proves: `bin/check_bundle.php` green, `php bin/build_action_registry.php --check`, no console
  error, no horizontal scroll at 375 × 740, and the canvas page's JavaScript-off fallback (the outline link).

## Decisions taken here

1. The rail is one partial door, `GET /boards/{board}/rail?panel=` (`html/boards/rail.php`), not in the manifest's door list yet — add it
   there with `export`'s.
2. A restore-only canvas batch logs `item.restore` (the manifest's convention line lists item.create/update/move/delete/text/batch).
3. `ops_commit`'s answer carries the batch's `inverse` (read from `board_ops`); a person's undo is that inverse posted as a new batch —
   `bd_undo_ops()` is the agents' (`ops_undo`).
4. `bd_guard()` gains `42501 → 403` and `22023 → 422` in Phase 2 (the shell owns the kit).
5. The SVG export takes the whole board only up to 400 items (`bd_region_svg()`'s cap); above, a frame. PNG has no such limit.
6. An agent's retried call is idempotent: `client_batch_id` = a uuid from md5 of the request id. **Corrected in slice 2:** md5 of the run's
   request id, the action's path and its parameters — every call of a run carries the run's request id, so a second, different call in the same
   turn would have answered "duplicate".
7. The layout engine grows a target frame to fit, and places a block with no position below everything else on the board.
8. `data-test="1"` is set only under `tests/dev_router.php` (`BD_TEST_HOOKS`) and never in production.
9. Headless performance passes at median ≤ 33 ms a frame (30 fps) on this host; 60 fps is checked by the owner in a real browser.
10. The thumbnail door accepts at most one PNG per board per 5 minutes; it is not logged.

## Open questions

- (none)

## Built in slice 2 — notes for slices 3, 4 and 5 (2026-10-11)

What the exemplar became, where it differs from the text above or adds to it. canvas.md "Built and proven" holds the decisions.

- **How an item is drawn** (`render/layers.ts`): each type has `draw<Type>(ctx, item, zoom)` over its own box `[0, w] × [0, h]`
  (`render/items/sticky.ts`, `shape.ts`, `text.ts`, `placeholder.ts`). What is not selected is painted by **batch shapes**: a `Konva.Shape` walks a
  run of items in z order and calls the draw functions on the canvas directly. A selected item gets a node of its own, a `Konva.Shape` at its centre,
  rotated, whose scene function is the same draw function. The Transformer needs a node, and the runs split around it so the z order holds. Below
  zoom 0.4 a run is flat rectangles in one pass (`lod.ts drawFlats`). **Slices 4 and 5 add a type by adding its draw function to `drawItem()`.**
  `placeholder.ts` stands in until then, and is replaced the same way.
- **Text** is laid out by the island (`render/items/text.ts`: `layoutText()`, cached per text, size and width; `fitFontSize()` searches the size
  ladder by halves). The editor's overlay uses the same sizes and padding. Konva.Text is not used.
- **Layers:** the background is a CSS pattern on the stage's element, moved with the camera. Frames and items are two groups of one canvas layer.
  The selection layer (Transformer, marquee, lock outlines) and the presence layer (slice 3: cursors, agents) draw only while they hold
  something. That is three canvases in all, and every canvas costs a clear and a composite a frame on software rendering.
- **The context** every tool and UI part shares is `client/src/board.ts` (`Board`): the store, the view, the renderer, the committer, the undo
  stack, the selection, the toasts, the level, `commit(ops)` (applied now, undoable, the poll kicked), `setTool`, `editText`, `frameAt`.
- **Input** is one router (`tools/input.ts`, pointer events on the stage's element). One pointer drives the active tool's gesture
  (`down → move → up | cancel`). Two fingers pinch and pan, cancelling the gesture. The wheel pans, and Ctrl+wheel zooms at the pointer. The
  middle button and Space+drag pan. A press on the Transformer's handles is Konva's. Double-click or double-tap calls the tool's `dbl`.
  **A new tool** (slice 4's connector and frame, slice 5's pen, highlighter, eraser and image, slice 6's comment) is a class with
  `name`, `cursor`, `down(p) → Gesture | null` and an optional `dbl`. It is registered in `main.ts`'s `tools` and gets a `data-tool` button in
  `app/views/boards/partials/toolbar.php`.
- **Files beyond the list above:** `board.ts`, `tools/input.ts`, `ui/clipboard.ts`, `ui/thumbnail.ts`, `ui/testhook.ts`,
  `render/items/placeholder.ts`, `boards.css` (built into `boards.<hash>.css`; `manifest.json` names both, and `bin/check_bundle.php` checks both).
- **The sync's last-writer rule** (`sync/commit.ts`). `pendingKeys` holds my unanswered writes by `id|field`, with style and data by key
  (`style.fillColor`). `ackedKeys` holds the seq of my last answered write of each. A remote op's field gives way to either, so a remote batch
  older than my write never overwrites it in my view. `catchup.lastSeq` moves only with remote batches; my own seqs join the applied set.
- **Slice 3 replaces `sync/socket.ts`'s body and keeps its shape:** `connectLive(cfg, {onBatch, onState, onResync, onLevel})` answering
  `{state, close()}`. `main.ts` already stops the poll when the state is `live` and starts it otherwise.
- **The test hook** (`window.__boards`, only under `tests/dev_router.php`) adds to the list above: `ready()`, `selection()`, `camera()`,
  `setCamera(x, y, zoom)`, `pending()`, `thumbnail()`, `resetPerf()`, and `panFrames(dx, dy, frames)`, the performance gate's pan (one step a
  frame, drawn in that frame).
- **The performance gate** (`tests/phase3/slice2/perf.mjs`) measures three views (the opening one, 100 %, 45 %). Its reading for a shared VM is
  canvas.md "Built and proven" 7.


## Built in slice 4 — what connectors and frames added to the island (2026-10-11)
- **A connector is drawn absolute**, not in a box of its own: `drawConnector(c, item, zoom, boxOf, flat?)` (`render/items/connector.ts`) takes
  `renderer.boxOf`, the ends' boxes *as they are drawn* (a drag's offset included), and computes the route every frame — never stored. A batch
  run calls it in place of `drawItem()`; a selected connector's node is placed at (0, 0) with no size. Its hit test is the distance to its
  route (`distanceTo`), not a box.
- **The Transformer never holds a connector** (`Selection.refresh()`); its end handles are drawn in the selection layer and a press on one is
  the select tool's (`endpointGesture`).
- **`Tool.hover?(p)`** is optional and new: `tools/input.ts` calls it on a mouse move with no button down (the select tool's side handles).
- **One-line editors** for a frame's title and a connector's caption are `ui/inline.ts` (an `<input>` over the canvas, placed again on every
  camera change; Enter or a click away commits one update, Escape leaves it); the keyboard's shortcuts are off while it is open.
- **A frame is drawn by `render/items/frame.ts`** (its title above the top-left at a constant 13 px, `titleBox()` its hit area); the frames group
  stays below the items group. `ui/navigator.ts` (reading order, `fit(id)`, `step(±1)`) and `ui/minimap.ts` are the rail's and the bottom bar's.
- `ui/toast.ts` gained `ask(message, answers, id)` — a question that stays until answered (deleting a frame), never a modal.

## Built in slice 5 — what drawings, images and files added to the island (2026-10-11)
- **Three draw functions of slice 2's shape**: `drawDrawing` (the tension curve computed here — Konva's control points — so a batch, a node
  and the pen's preview agree; scaled when resized; a plain polyline below the low zoom), `drawImageItem` and `drawDocument`. Below the low
  zoom only stickies, shapes and text are flat (`isFlat()` in `render/layers.ts`); frames, drawings, images and files are drawn, rotated.
- **Bitmaps load outside the frame**: `render/items/image.ts` keeps one `<img>` per attachment and size, asks the door for the size the item's
  width on screen deserves, draws the best size already loaded meanwhile, and calls `renderer.request()` when one arrives
  (`configureImages()` in `main.ts`). `window.__boards.imageSizes()` is what each item last asked for.
- **A drawing is hit on its line** (`drawingDistanceAt()`, rotation undone) in the select tool's `hitTest` and the eraser.
- **Uploads** are `tools/image.ts`'s `Uploader` (the toolbar's Image button, a drop on the stage, files in a paste — `ui/clipboard.ts` hands them
  over first); the door mints the item's id and the island commits the create with it.
- **The status notices on the canvas take no presses** (`pointer-events: none`), and a click without a drag on one of several selected items
  selects that one alone (`tools/select.ts`).
