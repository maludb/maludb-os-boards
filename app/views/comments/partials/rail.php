<?php
/**
 * The canvas rail's Comments panel (html/boards/rail.php?panel=comments): the new-thread form (hidden until the Comment tool places an anchor), the open
 * thread (loaded when the island fires boards:open-comment, or at load for the thread a link named), and the board's threads as cards.
 * Data: board, level, tz, list (the find_threads rows), total
 */
$may = bd_level_at_least($level, 'comment');
?>
<div id="comment-rail" class="rail-panel p-3" data-board="<?= e($board) ?>">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="fw-semibold flex-grow-1">Comments</span>
        <label class="fs-12 text-muted d-flex align-items-center gap-1 mb-0"><input type="checkbox" class="form-check-input mt-0" id="comment-rail-resolved" name="resolved" value="1"> Show resolved</label>
    </div>
    <?php if ($may): ?>
    <div class="card mb-3 d-none" id="comment-new"><div class="card-body">
        <div class="fs-12 text-muted mb-2" id="comment-new-where">New comment</div>
        <?= view('comments/partials/form.php', ['board' => $board, 'root' => '', 'base' => 'comment-new', 'target' => '#comment-rail-thread', 'swap' => 'innerHTML', 'button' => 'Comment', 'cancel' => true]) ?>
    </div></div>
    <?php else: ?>
    <div class="fs-12 text-muted mb-2" id="comment-rail-readonly">You can read the comments; the comment level lets you write them.</div>
    <?php endif; ?>
    <div id="comment-rail-thread" hx-get="/comments/thread.php" hx-trigger="boards:open-comment from:body, load" hx-swap="innerHTML"
         hx-vals='js:{id: (document.getElementById("board-rail-panel") || {dataset: {}}).dataset.openComment || ""}'></div>
    <div id="comment-rail-list" hx-get="/boards/<?= e($board) ?>/rail?panel=comment-list" hx-trigger="commentChanged from:body, change from:#comment-rail-resolved" hx-include="#comment-rail-resolved" hx-swap="innerHTML">
        <?= view('comments/partials/rail-list.php', ['list' => $list, 'total' => $total, 'tz' => $tz]) ?>
    </div>
</div>
