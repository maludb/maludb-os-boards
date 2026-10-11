<?php
/**
 * The outline page (screen `board-outline`): the board as a list — read at 375 px first. Every item line opens the canvas at it. "Copy as
 * Markdown" copies bd_board_outline() verbatim; without JavaScript the Markdown is under "Markdown". Data: b (the gate's mcp_boards row), html, markdown
 */
$id = (string) $b['board_id'];
?>
<?= view('shared/page-header.php', ['id' => 'board-outline', 'title' => 'Outline', 'crumbs' => [['Boards', '/boards'], [(string) $b['title'], null], ['Outline', null]],
    'action' => '<a class="btn btn-primary btn-touch" href="/boards/' . e($id) . '" id="board-outline-canvas"><i class="feather-edit-3 me-1"></i>Open the canvas</a>']) ?>
<div class="main-content">
    <div class="card"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h1 class="fs-5 mb-0 flex-grow-1 text-break" id="board-outline-title"><?= e((string) $b['title']) ?></h1>
            <button type="button" class="btn btn-light btn-touch" id="board-outline-copy"
                    onclick="var t=document.getElementById('board-outline-markdown').value, b=this; if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function(){ b.innerHTML='<i class=&quot;feather-check me-1&quot;></i>Copied'; }); }"><i class="feather-copy me-1"></i>Copy as Markdown</button>
        </div>
        <div class="board-outline-body" id="board-outline-body"><?= $html ?></div>
        <details class="mt-3"><summary class="fs-12 text-muted">Markdown</summary>
            <textarea class="form-control font-monospace fs-12 mt-2" rows="10" readonly id="board-outline-markdown"><?= e($markdown) ?></textarea>
        </details>
    </div></div>
</div>
