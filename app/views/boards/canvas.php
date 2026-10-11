<?php
/**
 * The canvas page (screen `board-view`; canvas-core.md "The island and the page"): the top bar, the island's mount, the toolbar, the bottom
 * bar and the right rail — all server-rendered here; the island (html/assets/boards/boards.<hash>.js, built from client/) binds to them by id
 * and draws the board inside #board-canvas. Without JavaScript the mount says so and links the outline. Data: b (board_for_canvas), level,
 * mount (canvas_mount — the data-* attributes), bundle (canvas_bundle or null), back (a local path or null)
 */
$id = (string) $b['board_id'];
$editable = bd_level_at_least($level, 'edit') && (!$b['is_locked'] || bd_level_at_least($level, 'full'));
?>
<div class="board-page" id="board-page" data-board-id="<?= e($id) ?>">
    <?= view('boards/partials/topbar.php', ['b' => $b, 'level' => $level, 'back' => $back]) ?>
    <div class="board-body" id="board-body">
        <div class="board-stage-wrap" id="board-stage-wrap">
            <div<?= html_attrs($mount) ?>>
                <noscript><div class="board-noscript p-4">
                    <p class="mb-2">The canvas needs JavaScript. You can read this board as a list.</p>
                    <a class="btn btn-primary btn-touch" href="/boards/<?= e($id) ?>/outline" id="board-noscript-outline"><i class="feather-list me-1"></i>Open the outline</a>
                </div></noscript>
            </div>
            <?= view('boards/partials/toolbar.php', ['editable' => $editable]) ?>
            <?= view('boards/partials/bottombar.php', ['editable' => $editable]) ?>
            <div class="board-banner d-none" id="board-banner" role="status"></div>
            <?= view('boards/partials/live-banner.php') ?>
            <?= view('boards/partials/minimap.php') ?>
            <div class="board-toasts" id="board-toasts" aria-live="polite"></div>
        </div>
        <?= view('boards/partials/rail.php', ['b' => $b, 'level' => $level]) ?>
    </div>
</div>
<?php if ($bundle !== null): ?>
    <?php if ($bundle['css'] !== null): ?><link rel="stylesheet" href="<?= e($bundle['css']) ?>" /><?php endif; ?>
    <script src="<?= e($bundle['js']) ?>" defer></script>
<?php else: ?>
    <div class="alert alert-warning m-3" id="board-no-bundle">The canvas's script is not built (node client/build.mjs).</div>
<?php endif; ?>
