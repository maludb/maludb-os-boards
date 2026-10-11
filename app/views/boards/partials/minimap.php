<?php /** The minimap (slice 4): the board's extent and the viewport, 160 × 120 at the bottom right; a click or a drag jumps there. Hidden at 375 px,
 *  where the Frames panel is the way around (decision 6). The island draws it (client/src/ui/minimap.ts). Data: none */ ?>
<div class="board-minimap d-none d-lg-block" id="board-minimap" aria-label="The board in miniature — click to go there"><canvas id="board-minimap-canvas" width="160" height="120"></canvas></div>
