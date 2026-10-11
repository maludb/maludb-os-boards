<?php
/** The rail's Outline panel: the board as a list (board_outline_html — the page's own renderer); a line flies the canvas to its item.
 *  Refreshed after changes (`boards:changed`, at most every 2 s). Data: board, html */
?>
<div id="rail-outline" class="rail-panel p-3" hx-get="/boards/<?= e($board) ?>/rail?panel=outline" hx-trigger="boards:changed from:body throttle:2s" hx-target="this" hx-swap="outerHTML">
    <div class="board-outline-body fs-13"><?= $html ?></div>
    <a href="/boards/<?= e($board) ?>/outline" class="btn btn-link btn-sm p-0 mt-2" id="rail-outline-page"><i class="feather-external-link me-1"></i>Open as a page</a>
</div>
