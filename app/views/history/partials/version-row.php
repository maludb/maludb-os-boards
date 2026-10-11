<?php
/**
 * A version: its name (named ones bold) or the reason in words, items, who, when; Preview, and for an owner Restore (hx-confirm).
 * Data: board, v (a board_versions row), level, tz, base, shown (bool: its preview is open)
 */
$no = (int) $v['version_no'];
$named = (string) $v['reason'] === 'manual' && $v['label'] !== null;
$reason = VERSION_REASONS[(string) $v['reason']] ?? (string) $v['reason'];
?>
<div class="card record-card mb-2<?= $shown ? ' border-primary' : '' ?>" id="version-row-<?= $no ?>"><div class="card-body py-2">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <div class="min-w-0">
            <div class="text-break<?= $named ? ' fw-bold' : '' ?>"><?= e($named ? (string) $v['label'] : ($v['label'] !== null ? $reason . ' — ' . $v['label'] : $reason)) ?> <span class="text-muted fw-normal fs-12">· version <?= $no ?></span></div>
            <div class="fs-12 text-muted"><?= e($named ? 'Saved' : $reason) ?> · <?= (int) $v['item_count'] ?> item<?= (int) $v['item_count'] === 1 ? '' : 's' ?> · <?= e((string) ($v['saved_by_name'] ?? 'the board itself')) ?> · <span title="<?= e(format_ts($v['created_at'], $tz)) ?>"><?= e(ago_words($v['created_at']) ?? '') ?></span></div>
        </div>
        <div class="d-flex gap-2 align-items-start">
            <a class="btn btn-sm btn-light btn-touch" id="version-preview-<?= $no ?>" href="<?= e($base . '?version=' . $no) ?>#history-preview" hx-get="<?= e($base . '?version=' . $no) ?>" hx-target="#page-content" hx-push-url="<?= e($base . '?version=' . $no) ?>"><i class="feather-eye me-1"></i>Preview</a>
            <?php if (bd_level_at_least($level, 'full')): ?>
            <form method="post" action="/boards/version-restore.php" hx-post="/boards/version-restore.php" hx-target="#page-content" hx-swap="innerHTML"
                  hx-confirm="Restore version <?= $no ?>? The board as it is now is saved first.">
                <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($board) ?>"><input type="hidden" name="version" value="<?= $no ?>">
                <button type="submit" class="btn btn-sm btn-light btn-touch" id="version-restore-<?= $no ?>"><i class="feather-rotate-ccw me-1"></i>Restore</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div></div>
