---
name: boards-basics
description: What Boards is and which tool answers what — boards, frames and the eight item types, the coordinates (the centre, frame-relative), the SVG dialect a region read answers in, search-then-read-a-region (never the whole board), levels and who sees what, the item budget, batches. Load before any Boards work.
---
# Boards — the basics

**A board** is an infinite canvas with an owner, a title and a start view. **Levels**: `view` (look), `comment` (look and
comment), `edit` (draw), `full` (owner: share, delete, restore). A person's level comes from the owner, their own row, their
department's row, the board's `everyone` row, or a Boards admin; a Viewer is never above `comment`. A board nobody shared with
you does not exist for you. **A mention never grants access.**

**Items** (one row each, Miro's shape): `sticky_note`, `shape` (`data.shape`: rectangle, round_rectangle, ellipse, triangle,
diamond, parallelogram, hexagon, star, arrow, callout), `text`, `connector`, `frame` (`data.title`), `drawing` (pen and
highlighter), `image`, `document` (a file; a PDF shows its first page).
- **Coordinates**: `x`, `y` are the item's **centre**. Inside a frame they are relative to the **frame's top-left**; on the canvas
  they are absolute. `width`, `height`, `rotation` (degrees). y grows downward.
- **Frames** hold items; moving a frame moves its children. Frames do not nest and do not rotate.
- **z** is a fractional-index string (`a0`, `a1`, `a0V`…): larger draws on top.
- **Text** (`data.content`) is the estate's rich-text runs; in a sticky, shape or text item it is plain — one style for the whole
  item. Write `[{"type":"text","text":{"content":"…"},"plain_text":"…"}]`.
- **Style** keys: `fillColor`, `textColor`, `fontSize`, `fontFamily`, `textAlign`, `textAlignVertical`, `borderColor`,
  `borderWidth`, `borderStyle`, `fillOpacity`; a connector's `strokeColor`, `strokeWidth`, `strokeStyle`, `startStrokeCap`,
  `endStrokeCap`. An update merges by key; `null` removes a key.
- **Connectors** join `start_item_id` to `end_item_id` (snap sides in `data.startSnap` / `data.endSnap`: auto, top, right,
  bottom, left) and stay attached when either end moves; a free end is `data.startPoint` / `data.endPoint` {x, y}, absolute.
  `data.shape`: straight, elbowed, curved.

## Reading a board — never the whole of it
1. `board_overview` — the frames with titles and boxes, clusters of unframed items, counts by type, who is working.
2. `board_search` — where words appear: id, type, frame and box of each match.
3. `board_read_region` with a box, or `frame_read` with a frame — the items there in the **SVG dialect**:
   - one element per item, carrying `data-id`, `data-type`, `data-z`, `data-locked`, its box and its text and colour name;
   - `<g data-frame data-title="…">` for a frame, its children inside in **frame-relative** coordinates;
   - `<path data-start="…" data-end="…">` for a connector, `<polyline>` for a drawing, `<image>` with a title.
   A reply holds at most 400 items / 64 kB; "narrow the box" means read a smaller box or one frame at a time.
4. `board_outline` — the whole board as text (frames → items, connectors as A → B, open comments), for a summary in words.

Other questions: `find_boards`, `records_search`; `board_changes` (what changed, by whom, where; `source = agent`);
`find_comments`, `comment_thread`; `board_access`; `board_versions`; `item_connections`; activity: `board_trail`,
`member_activity`, `agent_activity`, `record_history`, `most_active_boards`.

## Writing to a board
- Every write is an action, never SVG: `item_create_batch`, `item_update_batch`, `items_arrange`, `frame_create`,
  `connector_create`. A batch holds at most **500 ops**; your edits appear live on everyone's screen.
- New items get a new id; give each a `z` above what it sits on.
- **Budget**: a board warns above 4,000 items and refuses new items above 10,000. Prefer frames and fewer, clearer items.
- A **locked** item does not move or change until unlocked; a board **locked** by its owner is read-only for everyone else.
- `ops_undo` takes back your own batches from the last hour. Deleting anything else, sharing, restoring a version, publishing a
  template and the settings pause for a person.
