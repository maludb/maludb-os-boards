<?php
/**
 * One comment inside a thread (the root or a reply): the author's initial and name (an agent wears the **agent** chip), when, the text with mention chips,
 * Edit (own) and Delete (own, or a board's owner). Data: c (a find_thread row), thread (the root's id), tz, level, editing (the comment id being edited, or null)
 */
$cid = (string) $c['comment_id'];
$me = (int) current_member_id();
$mine = (int) ($c['author_member_id'] ?? 0) === $me;
$agent = ($c['author_kind'] ?? '') === 'agent';
$may = bd_level_at_least($level, 'comment');
$canDelete = $may && ($mine || bd_level_at_least($level, 'full'));
$name = (string) ($c['author_name'] ?? 'Someone');
?>
<div class="comment-bubble mb-3<?= $agent ? ' is-agent' : '' ?>" id="comment-<?= e($cid) ?>">
    <div class="d-flex align-items-center gap-2 mb-1">
        <span class="comment-avatar<?= $agent ? ' is-agent' : '' ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <span class="fw-semibold text-break"><?= e($mine ? 'You' : $name) ?></span>
            <?php if ($agent): ?><span class="badge bg-soft-info text-info ms-1">agent</span><?php endif; ?>
            <div class="fs-11 text-muted" title="<?= e(format_ts($c['created_at'], $tz)) ?>"><?= e(ago_words($c['created_at']) ?? '') ?><?= ($c['edited_at'] ?? null) !== null ? ' · edited' : '' ?></div>
        </div>
    </div>
    <?php if ($editing === $cid): ?>
        <form method="post" action="/comments/edit.php" hx-post="/comments/edit.php" hx-target="closest .comment-thread" hx-swap="outerHTML" class="comment-composer" id="comment-edit-form-<?= e($cid) ?>">
            <?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($cid) ?>">
            <textarea name="text" id="comment-edit-field-<?= e($cid) ?>" class="form-control mb-2" rows="3" maxlength="4000" required><?= e((string) $c['plain_text']) ?></textarea>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-touch" id="comment-edit-save-<?= e($cid) ?>">Save</button>
                <a href="/comments/thread.php?id=<?= e($thread) ?>" class="btn btn-light btn-touch" id="comment-edit-cancel-<?= e($cid) ?>"
                   hx-get="/comments/thread.php?id=<?= e($thread) ?>" hx-target="closest .comment-thread" hx-swap="outerHTML">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <div class="comment-text fs-13"><?= rich_to_html($c['body']) ?></div>
        <?php if ($mine || $canDelete): ?>
        <div class="d-flex gap-3 mt-1 fs-12">
            <?php if ($mine && $may): ?>
                <a href="/comments/thread.php?id=<?= e($thread) ?>&amp;edit=<?= e($cid) ?>" class="text-muted" id="comment-edit-<?= e($cid) ?>"
                   hx-get="/comments/thread.php?id=<?= e($thread) ?>&amp;edit=<?= e($cid) ?>" hx-target="closest .comment-thread" hx-swap="outerHTML"><i class="feather-edit-2 me-1"></i>Edit</a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" action="/comments/delete.php" class="d-inline" hx-post="/comments/delete.php" hx-target="closest .comment-thread" hx-swap="outerHTML"
                      hx-confirm="<?= e(($c['parent_comment_id'] ?? null) === null ? 'Delete this comment? Its replies go with it.' : 'Delete this comment?') ?>">
                    <?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($cid) ?>">
                    <button type="submit" class="btn btn-link p-0 fs-12 text-muted" id="comment-delete-<?= e($cid) ?>"><i class="feather-trash-2 me-1"></i>Delete</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
