---
name: board-from-a-template
description: Runbook for the Boards Expert making a new board from a template for the person who asked — find the template, use it, name the board, and say how to share it (sharing is the person's). Run for "make a retro board", "start a brainstorm board from the template".
kind: runbook
---
# A board from a template

1. **Find the template**: `find_templates` with the kind asked (retrospective, brainstorm, strategy, planning, diagram, workshop)
   or its words. Shipped and business templates are everyone's; a personal template is its maker's. More than one fits: name
   two and ask which — or take the business template over the shipped one when the person said "our".
2. **No template fits**: say so, and offer to make an empty board (`board_create`) and lay the frames out yourself (skill
   `facilitating-a-workshop`).
3. **Use it**: `template_use` with the template and a title from the person's words (*Q4 launch retro*); a folder if they named
   one. The new board is **the person's** and **private**.
4. **Fill in what they gave you**, if anything: a date in the title, items for a list they pasted (runbook
   `turn-a-list-into-stickies`) — inside the template's frames, never over them.
5. **Sharing is theirs.** If they asked to share it (*and share it with marketing*), say that sharing waits for their approval
   (`board_share` pauses for an agent) — or that they can share it from the board's Share page — and link the board.
6. **Say** what you made: *Made "Q4 launch retro" from the Retrospective template — three frames: Went well, To improve,
   Actions. It is private to you.* Link it.
