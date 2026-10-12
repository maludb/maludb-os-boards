<?php
/** One dispatch as a card: the agent, the asker, the board, the comment, the status, attempts, the run, the reply or the detail, when; Retry on a failed one or one waiting for approval. Data: r (a find_dispatches row), tz */
$id = (int) $r['dispatch_id'];
[$color, $words] = dispatch_status_chip($r);
$seeable = $r['board_title'] !== null;
$link = $seeable ? '/boards/' . $r['board_id'] . '?comment=' . $r['comment_id'] : null;
$tail = $r['status'] === 'answered' ? (string) ($r['reply_excerpt'] ?? '') : (string) ($r['detail'] ?? '');
?>
<div class="card record-card mb-2" id="dispatch-row-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex flex-wrap align-items-center gap-1">
        <span class="fw-semibold text-break"><?= e((string) $r['agent_name']) ?></span><span class="badge bg-soft-info text-info">agent</span>
        <span class="badge bg-soft-<?= e($color) ?> text-<?= e($color) ?>" id="dispatch-status-<?= $id ?>"><?= e($words) ?></span>
        <span class="fs-12 text-muted ms-auto" title="<?= e(format_ts($r['created_at'], $tz)) ?>"><?= e(ago_words($r['created_at']) ?? '') ?></span>
    </div>
    <div class="fs-12 text-muted mt-1">Asked by <?= e((string) ($r['asker_name'] ?? 'someone who has left')) ?> · on <?= $seeable ? hx_link('/boards/' . $r['board_id'], e((string) $r['board_title'])) : 'a board you cannot open' ?>
        · attempt<?= (int) $r['attempts'] === 1 ? '' : 's' ?> <?= (int) $r['attempts'] ?><?= $r['run_id'] !== null ? ' · run ' . (int) $r['run_id'] : '' ?></div>
    <?php if ($seeable && ($r['comment_excerpt'] ?? '') !== ''): ?>
        <div class="fs-13 mt-1 text-break"><?= hx_link($link, '“' . e((string) $r['comment_excerpt']) . '”', 'text-dark', 'id="dispatch-comment-' . $id . '"') ?></div>
    <?php endif; ?>
    <?php if ($tail !== ''): ?><div class="fs-12 text-muted mt-1 text-break" id="dispatch-detail-<?= $id ?>"><?= e($tail) ?></div><?php endif; ?>
    <?php if (in_array($r['status'], ['failed', 'awaiting_approval'], true)): ?>
        <form method="post" action="/admin/dispatch-retry.php" class="mt-2" id="dispatch-retry-form-<?= $id ?>"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $id ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="dispatch-retry-<?= $id ?>"><i class="feather-refresh-cw me-1"></i>Retry</button></form>
    <?php endif; ?>
</div></div>
