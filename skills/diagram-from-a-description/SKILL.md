---
name: diagram-from-a-description
description: Runbook for drafting a flowchart, an org chart or a timeline from a description — shapes and text placed on a grid inside a frame, joined by connectors that stay attached — as a draft for people to correct. Run for "draw the process", "make an org chart", "lay out a timeline".
kind: runbook
---
# Diagram from a description

1. **Read the description** and list the nodes and the links before drawing anything:
   - **flowchart**: steps (rectangle), decisions (diamond, two or more labelled exits), start/end (round_rectangle or ellipse);
   - **org chart**: people or roles (rectangle with name and title), each with one manager link;
   - **timeline**: dated events in order along one axis (rectangle or sticky per event, a text date above each).
   More than 40 nodes: draft the top level and say what you left out.
2. **Find room** (skill `laying-out-a-board`) and make **one frame** titled after the diagram, sized to fit.
3. **Place on a grid** (coordinates relative to the frame's top-left):
   - flowchart: top to bottom, main path in one column, branches to the right; 160 × 80 steps, 120 × 120 diamonds,
     120 px between rows, 220 px between columns;
   - org chart: one row per level, children centred under their manager, 200 px between rows, 40 px between siblings;
   - timeline: left to right, 240 px between events, dates as `text` above each.
   Create the shapes in one `item_create_batch` (text as plain runs; `data.shape` set; fontSize 14).
4. **Connect** in a second batch (`connector_create`): `start_item_id` → `end_item_id`, `data.shape` elbowed (flowchart,
   org chart) or straight (timeline), `endStrokeCap` an arrow for flow, a caption only on a decision's exits (*yes* / *no*).
   Snap sides: bottom → top for top-to-bottom flow, right → left for sideways.
5. **Check** the region: no overlaps, every node reachable. If wrong, `ops_undo` and redo.
6. **Say** it is a draft: *Drew the returns process as a flowchart in a new frame "Returns" (9 steps, 2 decisions) — please
   correct anything I misread.* Do not decide a step the description left open; leave it as a question sticky in the frame.
