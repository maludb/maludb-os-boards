---
name: turn-a-list-into-stickies
description: Runbook for putting a pasted or dictated list on a board as stickies — one sticky per line, in a grid, inside a new or named frame, in empty space. Run for "add a sticky for each of these", "put this list on the board".
kind: runbook
---
# Turn a list into stickies

1. **Split the list** into lines: one idea per sticky. Drop bullets and numbering, trim each to a few words (keep the person's
   words; shorten only by dropping filler). Empty lines go. More than 200 lines: ask whether to split across frames.
2. **Where**: the frame named (*in the Ideas frame*) — `board_search` for its title, `frame_read` to see what is already in it;
   or a new frame, titled as asked (or after the list's subject), placed in empty space (`board_overview`; skill
   `laying-out-a-board`).
3. **The grid**: 200 × 200 stickies at a 240 px pitch; columns = 4 for up to 16 items, 5 up to 25, 6 beyond. Inside an existing
   frame, start below the last row already there; if the frame is too small, grow it (`item_update_batch` on the frame's
   width/height) or make a second frame beside it — never overlap.
4. **Create** — `item_create_batch` with the grid layout, `parent_id` = the frame, coordinates **relative to the frame's
   top-left**, colour as asked (default the board's sticky colour, else yellow), text as plain runs. One batch (at most 500).
   A new frame is created in the same batch, before its stickies.
5. **Check** with `board_read_region`; if anything overlaps, `ops_undo` and place again.
6. **Say** what you did: *Added 12 stickies to "Ideas", in three rows below the existing ones.* Link the board.
