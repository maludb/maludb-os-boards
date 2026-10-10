# Build spec: connectors and frames (slice 4)

What exists at the end: **connectors** that stay attached — drawn from an item's side handle to another item (or to a free point), snapped
to a side (top, right, bottom, left) or `auto` (the nearest side, re-chosen as the ends move), routed **straight, elbowed or curved**, with
arrowheads at either end and one **caption** edited in place; when an end moves the connector follows on every canvas. **Frames** — drawn as a
rectangle, titled on their top-left, renamed in place — that **hold their children**: dropping an item inside sets its frame (and its
frame-relative position), dragging the frame moves everything in it as one row, resizing a frame never moves its children, deleting a frame
asks whether its contents go too. The **navigator** (the rail's Frames panel and Prev / Next in the bottom bar: frames in reading order, a
click flies there and fits it) and the **minimap** (the board's extent and the viewport, a click to jump). The agents' `connector_create`
(two items, a shape, a caption, an arrow) and `frame_create` (a titled frame, optionally **around** items it takes in). Built by the planning
model (design D16), **replicating slice 2's patterns exactly**: a tool, a renderer, a Transformer rule and an item action per thing, every
change one batch through `bd_commit_ops()`; the architecture is canvas-core ("Coordinates, frames and z", "The op protocol", "Rendering and
performance").

Schema (never modified): `board_items` (`parent_id`, `start_item_id`, `end_item_id`, the frame checks, the refs trigger, the box triggers — a
frame's move re-boxes its children, an end's move re-boxes its connectors) and `bd_commit_ops()` (the frame delete's `keep_children`, the
connector cascade) (db/007); `bd_region_svg()` (frame mode), `bd_board_overview()` (frames in reading order) (db/013); `mcp_board_items` (db/012).
**The database is the referee**: frames neither nest nor rotate, a parent is a live frame on the same board, a connector joins live
non-connector items on its own board, deleting an item deletes its connectors.

## Divergences from the design
None. The data shapes the design left at "Miro's names" are fixed below.

## The data (Miro's names; canvas-core "Coordinates, frames and z" is the coordinate rule)
- **Connector** (`type = connector`, no `parent_id`): `start_item_id` / `end_item_id` (or NULL for a free end); `data` = `{shape: straight |
  elbowed | curved, startSnap: auto | top | right | bottom | left, endSnap: …, startPoint: {x, y}, endPoint: {x, y} (absolute, only for a free
  end), captions: [{content, position: 0.5}]}`; `style` = `{strokeColor, strokeWidth (1–8, default 2), strokeStyle: normal | dashed | dotted,
  startStrokeCap: none | arrow | filled_triangle, endStrokeCap: …}` (default `none` / `arrow`). Its own `x, y, width, height` are 0 (the box is the
  trigger's, from its ends).
- **Routing** (client, render only — never stored): straight = the segment between the two snap points; elbowed = Manhattan with at most two
  bends, leaving each snap side perpendicularly by 20, choosing the route with fewer bends then the shorter; curved = a cubic Bézier whose control
  points leave each side perpendicularly by a third of the distance. `auto` = the side facing the other end's centre, re-chosen on every render.
- **Frame** (`type = frame`): `data` = `{title}`; `style` = `{fillColor}` (default `#ffffff`, the theme's 8 tints offered); `rotation` 0. A
  child's `x, y` are its centre relative to the frame's top-left (slice 2's rule); entering a frame converts absolute → relative in the same
  batch (`update {parent_id, x, y}`), leaving converts back.
- **Membership on drop**: an item (not a frame, not a connector) dropped with its centre inside a frame's box becomes its child; dragged out, it
  leaves; dragging a frame never adopts items it passes over. A frame drawn **around** existing items adopts those whose centre falls inside.

## Who may do what
| Action | Level / rule |
|---|---|
| `connector_create`, `frame_create` | `edit` (and `full` on a locked board) |
| drawing and editing connectors and frames on the canvas | `edit` — through slice 2's `ops_commit` |
| the navigator, the minimap, `frame_read`, `item_connections` | `view` |

## Screens
None new. `board-view` gains the Connector and Frame tools in the toolbar, the frame title's in-place editor, the connector's caption editor,
the side handles on a hovered item (four dots), the Properties panel's connector section (shape, line style, width, colour, arrowheads) and
frame section (title, fill, "Fit to contents"), the rail's **Frames** panel (`GET /boards/{board}/rail?panel=frames` — the frames of
`bd_board_overview()` in reading order with their counts; a click flies), Prev / Next frame in the bottom bar, and the **minimap** bottom-right
(160 × 120 at 1280, hidden at 375 where the Frames panel is the way around).

## Files (exactly these)
- `client/` — the sources canvas-core assigns to slice 4 (the connector and frame tools, the connector renderer with the routing, the frame
  renderer and its title editor, the caption editor, membership on drop, the navigator and the minimap), the bundle rebuilt
- `html/items/{connect,frame}.php`; `app/features/items/{connectors,frames}.php` (the ops builders); `app/views/boards/partials/{rail-frames,minimap}.php`;
  `html/boards/rail.php` gains the `frames` panel
- `tests/phase3/slice4/{run.sh,lib.php,actions.php,browser.mjs}`

## The agents' actions
- `connector_create`: `from` and `to` must be live non-connector items on the board (P0002 / 23514 with the database's sentence); `shape`
  default elbowed; `arrow` end (default) | start | both | none → the two caps; `caption` → `captions[0]`; snaps `auto`. One create op, `source`
  agent / command_bar / web. `did`: "Connected Shipped late → Retro actions".
- `frame_create`: `title` required (120). With `around[]`: the frame's box = the union of the items' boxes + 40 on every side + 40 more on top for
  the title; items already in another frame leave it; a listed frame is refused ("Frames do not nest"); ONE batch = the frame's create then each
  item's `update {parent_id, x, y}` (frame-relative). Without `around`: `x, y` (centre) and `width, height` (default 1200 × 800), else empty
  space right of the extent (slice 2's rule). `did`: "Made the frame Went well around 6 items".
- Both: the board gate at `edit`, `bd_guard()`, `log_activity()` (`item.connect`, `item.frame`), `bd_done()` with `record_id` = the seq.

## Query functions (signatures fixed)
- `connector_ops(PDO $pdo, string $boardId, string $from, string $to, array $opts): array` (one create op) ·
  `frame_ops(PDO $pdo, string $boardId, string $title, array $around, ?array $box): array` (the create + the adoptions)
- `frames_in_order(PDO $pdo, string $boardId): array` (the `frames` of `bd_board_overview()`) · `item_connections(PDO $pdo, string $itemId): array{incoming, outgoing}` (over `mcp_board_items`)

## MCP tools this slice makes real
`frame_read` (`bd_region_svg(…, p_frame_id)`), `item_connections`.

## Manifest rows claimed
Screens (0):
Actions (2): `connector_create`, `frame_create`

## Activity log events
`item.connect` and `item.frame` (the agents' actions: the ids, the frame's title, the count adopted); the canvas's drawing of connectors and
frames logs through slice 2's door (`item.create`, `item.move`, `item.update`, `item.batch`). Every row carries `board_id`.

## Out of scope for this slice
Nested frames and rotated frames (refused by the schema); frames as slides, presentation mode and frame export (Extended / slice 8's PNG of a
frame); connector waypoints a person drags (v2 — the routing is automatic); mind maps (Later); swim lanes and Kanban frames (v2).

## Proof (`tests/phase3/slice4/run.sh`: scratch database `bd_dev4`; php -S on 9101 and the fakes; the user-level Apache on 9107; Playwright; the registry `--check`)
1. Draw a connector from a sticky's right handle to a shape (real pointer events) → one batch; move the shape → the connector's rendered end follows
   (`window.__boards.route(connectorId)`), on a second context too; the elbowed route has only horizontal and vertical segments; switch to curved
   and straight in Properties; arrowheads; a caption typed in place.
2. A free end: drag from a handle to empty space → `endPoint` stored; attaching it later to an item clears `endPoint`.
3. Delete the shape → its connector goes in the same batch; undo brings both back.
4. Draw a frame, drop a sticky in → `parent_id` and a frame-relative position; drag the frame → the sticky's absolute box moves, one row in the batch;
   resize the frame → the sticky does not move; drag the sticky out → absolute again.
5. Delete the frame → "Delete its contents too?": keep → the children stay where they stood; delete → they go; both undoable.
6. The Frames panel lists frames top-to-bottom, left-to-right with counts; a click flies and fits; Prev / Next walk them; the minimap shows the
   viewport and a click jumps there.
7. Agents (action token, JSON): `connector_create` with a caption and `arrow both`; to a connector refused; across boards refused; `frame_create`
   around three stickies (two from another frame) wraps them and takes them in; around a frame refused "Frames do not nest"; without `around` in
   empty space.
8. `frame_read`'s SQL: the frame's children frame-relative with their absolute `data-cx`; `item_connections` for the sticky.
9. 375: the Frames panel as the sheet, frames reachable without the minimap; no console errors; `bin/check_bundle.php`; registry `--check`.

## Decisions taken here
1. The connector caps offered are none, arrow and filled_triangle; widths 1–8; styles normal, dashed, dotted.
2. Elbowed routing: at most two bends, 20 units off each side; curved: a cubic Bézier with the controls a third of the distance out.
3. Membership by the item's centre on drop; a moving frame never adopts what it passes over.
4. `frame_create` pads 40 on every side and 40 more on top for the title; default size without `around` 1200 × 800.
5. Deleting a frame on the canvas asks; the agents' `item_delete_batch` takes the children unless `keep_children` (slice 2's rule).
6. The minimap is hidden at 375; the Frames panel is the way around a board on a phone.

## Open questions
None.
