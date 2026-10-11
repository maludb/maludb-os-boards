<?php
/**
 * A thread (the comments page's inline thread AND the canvas's rail — one partial): where it is pinned, Resolved, the root and every reply, Resolve/Reopen,
 * the reply form, Show on the board. Its wrapper `.comment-thread#comment-thread-{root}` is what the handlers' answers replace (outerHTML).
 * Data: t (find_thread), tz, board, level (mine), notes (the last comment's answer: needs_share [id => name], not_shared [names], unresolved [names]), edit (a comment id being edited, or null)
 */
$root = $t['root'];
$rid = (string) $root['comment_id'];
$notes = $notes ?? [];
$edit = $edit ?? null;
$may = bd_level_at_least($level, 'comment');
$resolved = $root['resolved_at'] !== null;
?>
<div class="comment-thread" id="comment-thread-<?= e($rid) ?>" data-thread="<?= e($rid) ?>">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="fs-12 text-muted" id="comment-where-<?= e($rid) ?>"><i class="feather-map-pin me-1"></i><?= e(ucfirst(comment_where_words($root))) ?></span>
        <?php if ($resolved): ?><span class="badge text-bg-success" id="comment-resolved-<?= e($rid) ?>">Resolved<?= ($root['resolved_by_name'] ?? '') !== '' ? ' by ' . e($root['resolved_by_name']) : '' ?></span><?php endif; ?>
        <a href="/boards/<?= e($board) ?>?comment=<?= e($rid) ?>" class="fs-12 ms-auto" id="comment-show-<?= e($rid) ?>"><i class="feather-crosshair me-1"></i>Show on the board</a>
    </div>
    <?= view('comments/partials/needs-share.php', ['board' => $board, 'level' => $level, 'agents' => $notes['needs_share'] ?? [], 'notShared' => $notes['not_shared'] ?? [], 'unresolved' => $notes['unresolved'] ?? []]) ?>
    <?= view('comments/partials/reply.php', ['c' => $root, 'thread' => $rid, 'tz' => $tz, 'level' => $level, 'editing' => $edit]) ?>
    <?php foreach ($t['replies'] as $rep): ?>
        <?= view('comments/partials/reply.php', ['c' => $rep, 'thread' => $rid, 'tz' => $tz, 'level' => $level, 'editing' => $edit]) ?>
    <?php endforeach; ?>
    <?php if ($may): ?>
        <form method="post" action="/comments/<?= $resolved ? 'reopen' : 'resolve' ?>.php" class="mb-3" hx-post="/comments/<?= $resolved ? 'reopen' : 'resolve' ?>.php" hx-target="closest .comment-thread" hx-swap="outerHTML">
            <?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($rid) ?>">
            <button type="submit" class="btn btn-light btn-touch" id="comment-<?= $resolved ? 'reopen' : 'resolve' ?>-<?= e($rid) ?>"><i class="feather-<?= $resolved ? 'rotate-ccw' : 'check-circle' ?> me-1"></i><?= $resolved ? 'Reopen' : 'Resolve' ?></button>
        </form>
        <?= view('comments/partials/form.php', ['board' => $board, 'root' => $rid, 'base' => 'comment-reply-' . $rid, 'target' => 'closest .comment-thread', 'swap' => 'outerHTML', 'button' => 'Reply', 'cancel' => false]) ?>
    <?php else: ?>
        <div class="fs-12 text-muted">You can read this thread; commenting needs the comment level.</div>
    <?php endif; ?>
</div>
