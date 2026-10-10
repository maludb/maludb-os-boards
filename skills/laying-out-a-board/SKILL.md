---
name: laying-out-a-board
description: How to place things on a board so people can read it — finding empty space, grids and spacing, sticky and frame sizes, frames as areas, colour with meaning, labels, connectors, z-order — and never covering a person's work. Load before item_create_batch, frame_create or items_arrange.
---
# Laying out a board

## Find room first
1. `board_overview`: the frames' boxes and the clusters of loose items give you the occupied area.
2. Place new work **in empty space**: to the right of the rightmost content (x = right edge + 200), or below the lowest
   (y = bottom edge + 200), aligned with the top or left edge of what is near it. Read the target box with `board_read_region`
   to be sure it is empty.
3. **Never cover or overlap a person's items.** If a frame is full, make a new frame beside it rather than squeezing.

## Sizes and spacing
- **Sticky**: 200 × 200 (Miro's default is about 199 × 228). Grid cells of 200 × 200 with **20–40 px gaps** (240 px pitch is
  safe). Text a few words; long text makes a sticky unreadable at the board's zoom.
- **Columns**: 3–6 stickies wide for a cluster; a long list in several columns rather than one long row.
- **Frame**: fit its children with 40 px padding on every side and 80 px above for the title band. A frame of 4 × 3 stickies at a
  240 px pitch is about 1,040 × 880. Frames side by side with 100–200 px between them.
- **Text heading** above a column: a `text` item, fontSize 24–36, the width of the column.
- **Shapes** in a diagram: 160 × 80 for a step, 120 × 120 for a decision diamond; 80–120 px between shapes.

## Coordinates, again
`x`, `y` are the **centre**. A sticky at column c, row r of a grid inside a frame, with padding p = 40, title band t = 80, cell
pitch s = 240 and size 200: `x = p + 100 + c·s`, `y = t + 100 + r·s` — relative to the frame's top-left. On the canvas, add the
grid's top-left. Use `items_arrange` (grid, column, row, align, distribute) to tidy items you already placed instead of computing
positions twice.

## Colour with meaning
Keep the board's own colours if it has them (read a few stickies' `fillColor`). Otherwise: yellow for ideas, green for what went
well or agreed, red/pink for problems and risks, blue for questions, orange for actions, grey for notes. One colour per theme
when clustering. Never recolour a person's sticky unless asked.

## Order, connectors, labels
- `z`: frames lowest, then shapes and stickies, then connectors and text labels on top.
- Connectors attach to items (`start_item_id`, `end_item_id`) so they follow when things move; elbowed for flowcharts and org
  charts, straight for simple links; a caption only when the link needs a word ("yes" / "no").
- Every frame has a `data.title`; every cluster a heading. A reader should understand the area without asking.

## Batches
Frames first, then items into them, then connectors and labels — one batch per step, at most 500 ops each. After placing, read
the region back once to check nothing overlaps; if it does, `ops_undo` your batch and place it again.
