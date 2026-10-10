---
name: cluster-stickies-by-theme
description: Runbook for affinity mapping — read the selected stickies (or a frame), find 3–7 themes, make a labelled frame per theme in empty space, move the stickies in without rewriting them, and reply with what was done. Run when mentioned with "group these", "cluster by theme", "sort these".
kind: runbook
---
# Cluster stickies by theme

1. **What "these" are**: the comment's selection (`context_item_ids`); else the frame the comment is pinned to; else ask in a
   reply. Read them: `board_read_region` over their box, or `frame_read`. Keep only `sticky_note` (and `text` if asked).
   More than 300 stickies: say so and ask which frame to start with.
2. **Find the themes**: read every sticky's text. Group by meaning, not by colour or wording. Aim for **3–7 themes**, each named
   in 1–4 words from the people's own words. A sticky that fits nowhere goes to **Other**. Two stickies that say the same
   thing stay in the same theme, side by side — note them as duplicates; do not delete either.
3. **Find room** (skill `laying-out-a-board`): `board_overview`; the empty space to the right of (or below) the stickies.
4. **Make the theme frames** — one batch (`frame_create`, or `item_create_batch` of frames): side by side, 100–200 px apart,
   each `data.title` = the theme, sized for its stickies (40 px padding, 80 px title band, a 240 px pitch, up to 5 columns).
5. **Move the stickies** — one batch of `item_update_batch`: each sticky gets the theme frame's id as `parent_id` and its grid
   position **relative to that frame's top-left**. Change nothing else: not the text, not the colour.
6. **Check**: `board_read_region` over the new frames — every sticky placed once, nothing overlapping. If wrong, `ops_undo` your
   batches and redo.
7. **Reply in the thread** (`comment_reply`), two sentences with counts: *Grouped 31 stickies into 5 themes to the right of
   "Ideas": Pricing (9), Onboarding (8), Support (6), Speed (5), Other (3). Stickies 4 and 17 say the same thing — they sit
   together in Pricing.*

Never delete, rewrite or recolour a person's sticky; never rank the themes — the team decides what matters.
