<?php /** The trash (screen `trash`): my deleted boards (a Boards admin: everyone's) as cards — "In the trash", who deleted it and when, the purge date; Restore. Data: result, page, tz, notice */
$more = $page * BOARD_PAGE < $result['total'];
?>
<?= view('shared/page-header.php', ['id' => 'trash', 'title' => 'Trash', 'crumbs' => [['Home', '/'], ['Trash', null]], 'back' => back_link()]) ?>
<div class="main-content" id="trash-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <p class="fs-12 text-muted">A deleted board waits here for 30 days, then it is purged with everything on it. Bringing it back brings back who it was shared with.</p>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="trash-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-trash-2"></i></span>
            <div><div class="fw-semibold">The trash is empty</div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="trash-grid">
            <?php foreach ($result['rows'] as $t): $id = (string) $t['board_id']; ?>
                <div class="col"><div class="card record-card mb-0 h-100" id="trash-card-<?= e($id) ?>"><div class="card-body py-3">
                    <div class="fw-semibold text-break"><?= e($t['title']) ?></div>
                    <div class="d-flex flex-wrap gap-1 mt-1"><span class="badge text-bg-dark">In the trash</span><span class="fs-12 text-muted"><?= (int) $t['item_count'] ?> items · <?= e((string) $t['owner_name']) ?></span></div>
                    <div class="fs-12 text-muted mt-1">Deleted by <?= e((string) ($t['deleted_by_name'] ?? 'someone')) ?> <?= e(ago_words($t['deleted_at']) ?? '') ?></div>
                    <div class="fs-12 text-muted" id="trash-card-<?= e($id) ?>-purge">Purged on <?= e(format_ts($t['purge_after'], $tz, 'M j, Y')) ?></div>
                    <form method="post" action="/boards/restore.php" class="mt-2" hx-post="/boards/restore.php" hx-target="#page-content" hx-swap="innerHTML">
                        <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
                        <button type="submit" class="btn btn-light btn-touch" id="trash-card-<?= e($id) ?>-restore"><i class="feather-rotate-ccw me-1"></i>Restore</button>
                    </form>
                </div></div></div>
            <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link('/trash?page=' . ($page - 1), 'Previous', 'page-link') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link('/trash?page=' . ($page + 1), 'Next', 'page-link') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
