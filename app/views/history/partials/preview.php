<?php
/**
 * A version's preview: its header and its outline (frames as headings, items as lines — the 375 px reader's shape), Restore this version (full), Back to the history.
 * Data: b, level, v (the version row), outline (html), tz, base
 */
$id = (string) $b['board_id'];
$no = (int) $v['version_no'];
$named = (string) $v['reason'] === 'manual' && $v['label'] !== null;
?>
<section class="card mb-4" id="history-preview" aria-labelledby="history-preview-title"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <div>
            <h6 class="mb-0<?= $named ? ' fw-bold' : '' ?>" id="history-preview-title">Version <?= $no ?><?= $v['label'] !== null ? ' — ' . e((string) $v['label']) : '' ?></h6>
            <div class="fs-12 text-muted"><?= e(VERSION_REASONS[(string) $v['reason']] ?? (string) $v['reason']) ?> · <?= (int) $v['item_count'] ?> item<?= (int) $v['item_count'] === 1 ? '' : 's' ?> · <?= e((string) ($v['saved_by_name'] ?? 'the board itself')) ?> · <?= e(format_ts($v['created_at'], $tz)) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-start">
            <?php if (bd_level_at_least($level, 'full')): ?>
            <form method="post" action="/boards/version-restore.php" hx-post="/boards/version-restore.php" hx-target="#page-content" hx-swap="innerHTML"
                  hx-confirm="Restore version <?= $no ?>? The board as it is now is saved first.">
                <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="version" value="<?= $no ?>">
                <button type="submit" class="btn btn-primary btn-touch" id="history-preview-restore"><i class="feather-rotate-ccw me-1"></i>Restore this version</button>
            </form>
            <?php endif; ?>
            <a class="btn btn-light btn-touch" id="history-preview-back" href="<?= e($base) ?>" hx-get="<?= e($base) ?>" hx-target="#page-content" hx-push-url="<?= e($base) ?>"><i class="feather-arrow-left me-1"></i>Back to the history</a>
        </div>
    </div>
    <div class="history-outline fs-13" id="history-preview-outline"><?= $outline !== '' ? $outline : '<span class="text-muted">This version is an empty board.</span>' ?></div>
</div></section>
