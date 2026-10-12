<?php
/** A deleted board on the admin's page: title, owner, items, who deleted it and when, when it is purged; Restore, and Purge now behind a confirm. Data: t (a trash_all row), may_purge, tz */
$id = (string) $t['board_id'];
?>
<div class="col"><div class="card record-card mb-0 h-100" id="trash-row-<?= e($id) ?>"><div class="card-body py-3">
    <div class="fw-semibold text-break"><?= e((string) $t['title']) ?></div>
    <div class="d-flex flex-wrap gap-1 mt-1"><span class="badge text-bg-dark">In the trash</span><span class="fs-12 text-muted"><?= (int) $t['item_count'] ?> item<?= (int) $t['item_count'] === 1 ? '' : 's' ?> · <?= e((string) $t['owner_name']) ?></span></div>
    <div class="fs-12 text-muted mt-1">Deleted by <?= e((string) ($t['deleted_by_name'] ?? 'someone')) ?> <?= e(ago_words($t['deleted_at']) ?? '') ?></div>
    <div class="fs-12 text-muted" id="trash-row-<?= e($id) ?>-purge">Purged on <?= e(format_ts($t['purge_after'], $tz, 'M j, Y')) ?></div>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <form method="post" action="/boards/restore.php" hx-post="/boards/restore.php" hx-target="#page-content" hx-swap="innerHTML">
            <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="return_to" value="/admin#trash">
            <button type="submit" class="btn btn-light btn-touch" id="trash-row-<?= e($id) ?>-restore"><i class="feather-rotate-ccw me-1"></i>Restore</button>
        </form>
        <?php if ($may_purge): ?>
            <form method="post" action="/admin/trash-purge.php" hx-post="/admin/trash-purge.php" hx-target="#page-content" hx-swap="innerHTML"
                  hx-confirm="Purge <?= e((string) $t['title']) ?> for good, with its <?= (int) $t['item_count'] ?> items and files? This cannot be undone.">
                <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>">
                <button type="submit" class="btn btn-danger btn-touch" id="trash-purge-<?= e($id) ?>"><i class="feather-trash-2 me-1"></i>Purge now</button>
            </form>
        <?php endif; ?>
    </div>
</div></div></div>
