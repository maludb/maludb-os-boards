<?php
/**
 * The right rail (canvas-core.md "The right rail"): an HTMX panel beside the canvas on a desktop (the canvas narrows), a bottom sheet at
 * 375 px (40 % / 90 %, a handle) — never a modal. Tabs Item (the selection's Properties), Frames (the navigator, slice 4), Outline,
 * Comments (slice 6), History (slice 7);
 * each loads from ONE door, /boards/{board}/rail?panel=. Data: b, level
 */
$id = (string) $b['board_id'];
$tabs = ['item' => ['Item', 'feather-sliders'], 'frames' => ['Frames', 'feather-layout'], 'outline' => ['Outline', 'feather-list'], 'comments' => ['Comments', 'feather-message-circle'], 'history' => ['History', 'feather-clock']];
?>
<aside class="board-rail" id="board-rail" aria-label="The board's panel">
    <button type="button" class="board-rail-handle d-lg-none" id="board-rail-handle" aria-label="Taller or shorter"><span></span></button>
    <div class="board-rail-tabs" role="tablist" id="board-rail-tabs">
        <?php foreach ($tabs as $k => [$label, $icon]): ?>
            <button type="button" role="tab" class="board-rail-tab<?= $k === 'item' ? ' active' : '' ?>" id="board-rail-tab-<?= e($k) ?>" data-panel="<?= e($k) ?>"
                    aria-selected="<?= $k === 'item' ? 'true' : 'false' ?>"
                    hx-get="/boards/<?= e($id) ?>/rail?panel=<?= e($k) ?>" hx-target="#board-rail-panel" hx-swap="innerHTML"
                    <?= $k === 'item' ? 'hx-vals=\'js:{ids: (document.getElementById("page-content").dataset.selection || "")}\'' : '' ?>><i class="<?= e($icon) ?> me-1"></i><?= e($label) ?></button>
        <?php endforeach; ?>
        <button type="button" class="board-rail-close d-lg-none ms-auto" id="board-rail-close" aria-label="Close the panel"><i class="feather-x"></i></button>
    </div>
    <div class="board-rail-panel" id="board-rail-panel">
        <?= view('boards/partials/rail-properties.php', ['board' => $id, 'rows' => [], 'sum' => null, 'may' => false, 'mayStart' => false]) ?>
    </div>
</aside>
