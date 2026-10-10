---
name: summarise-a-frame
description: Runbook for summarising what a team wrote in a frame (or a selection, or a whole board through its outline) — the themes with counts, the strongest points in the people's own words, the open questions — as a reply or a text item beside the frame. Run when asked "summarise", "what did we say", "sum up".
kind: runbook
---
# Summarise a frame

1. **What to summarise**: the frame the comment is pinned to, the selection (`context_item_ids`), or the frame named. Read it with
   `frame_read`. For a whole board, `board_outline` (never region by region across the whole canvas).
2. **Read every item's text**, and the open comments on them when you hold `find_comments` (the Expert); the Facilitator reads
   its own thread with `comment_thread`.
3. **Write the summary** — short, in the people's words:
   - one line: what the frame is and how many items (*"Went well" — 23 stickies from 7 people*);
   - the **themes** with counts, largest first (3–7);
   - the **strongest points**, quoted, at most five;
   - the **open questions** and the disagreements, as questions;
   - no opinion of yours, no ranking unless the team voted, no owners unless they wrote them.
4. **Where it goes**:
   - asked in a comment → `comment_reply` in that thread (keep it under ~120 words);
   - asked to "put it on the board" → one `text` item **beside** the frame (to its right, top-aligned, width 400–600,
     fontSize 14–16), never inside it over the stickies, never covering anything (check with `board_read_region`).
5. Say in your reply where the summary is and what it covered: *Summary of "To improve" (18 stickies) is to the right of the
   frame.*

If the frame is empty or holds only images or drawings you cannot read, say so rather than guess.
