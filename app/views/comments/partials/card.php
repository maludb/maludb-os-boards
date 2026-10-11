<?php
/**
 * A thread as a card (RecordCard's look): the author's initial and name (an agent wears the **agent** chip), the text with mention chips, where it is pinned, the
 * replies, who resolved it, and Open — the thread inline (HTMX swap into the card's slot) on the page, in the rail's panel in the canvas. Show on the board.
 * Data: r (a find_threads row), tz, rail (bool: the canvas's rail list)
 */
$id = (string) $r['comment_id'];
$agent = ($r['author_kind'] ?? '') === 'agent';
$name = (string) ($r['author_name'] ?? 'Someone');
$resolved = $r['resolved_at'] !== null;
$n = (int) $r['replies'];
$rail = !empty($rail);
?>
<div class="card record-card comment-card mb-2<?= $resolved ? ' is-resolved' : '' ?>" id="comment-card-<?= e($id) ?>"><div class="card-body py-3">
    <div class="d-flex gap-3 align-items-start">
        <span class="comment-avatar<?= $agent ? ' is-agent' : '' ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <span class="fw-semibold text-break"><?= e($name) ?></span>
                <?php if ($agent): ?><span class="badge bg-soft-info text-info">agent</span><?php endif; ?>
                <?php if ($resolved): ?><span class="badge text-bg-success">Resolved</span><?php endif; ?>
            </div>
            <div class="fs-12 text-muted mb-1"><i class="feather-map-pin me-1"></i><?= e(comment_where_words($r)) ?> · <span title="<?= e(format_ts($r['created_at'], $tz)) ?>"><?= e(ago_words($r['created_at']) ?? '') ?></span></div>
            <div class="comment-text fs-13"><?= rich_to_html($r['body']) ?></div>
            <div class="fs-11 text-muted mt-1"><i class="feather-message-circle me-1"></i><?= $n ?> repl<?= $n === 1 ? 'y' : 'ies' ?><?= $resolved && ($r['resolved_by_name'] ?? '') !== '' ? ' · resolved by ' . e($r['resolved_by_name']) : '' ?></div>
            <div class="d-flex flex-wrap gap-3 mt-2 fs-12">
                <?php if ($rail): ?>
                    <a href="/boards/<?= e((string) $r['board_id']) ?>?comment=<?= e($id) ?>" class="fw-semibold" id="comment-open-<?= e($id) ?>"
                       hx-get="/comments/thread.php?id=<?= e($id) ?>" hx-target="#comment-rail-thread" hx-swap="innerHTML"><i class="feather-corner-down-right me-1"></i>Open</a>
                <?php else: ?>
                    <a href="/comments/thread.php?id=<?= e($id) ?>" class="fw-semibold" id="comment-open-<?= e($id) ?>"
                       hx-get="/comments/thread.php?id=<?= e($id) ?>" hx-target="#comment-slot-<?= e($id) ?>" hx-swap="innerHTML"><i class="feather-corner-down-right me-1"></i>Open the thread</a>
                <?php endif; ?>
                <a href="/boards/<?= e((string) $r['board_id']) ?>?comment=<?= e($id) ?>" id="comment-show-card-<?= e($id) ?>"><i class="feather-crosshair me-1"></i>Show on the board</a>
            </div>
        </div>
    </div>
    <?php if (!$rail): ?><div class="mt-3" id="comment-slot-<?= e($id) ?>"></div><?php endif; ?>
</div></div>
