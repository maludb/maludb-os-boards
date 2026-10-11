<?php
/**
 * One principal's row (share-row-{kind}-{id}): avatar or a department's icon, the name, the kind chip (Agent, External, Department), its level
 * (a select for `full` that saves on change — board_share; else the badge), Remove (board_unshare, ✔); a Viewer's cap said; a principal with no
 * Boards grant flagged (needs-grant). Data: b, p (a board_permissions row), may, levels
 */
$id = (string) $b['board_id'];
$kind = (string) $p['principal_kind'];
$pid = (int) $p['principal_id'];
$rowId = 'share-row-' . $kind . '-' . $pid;
$field = $kind === 'department' ? 'department' : 'member';
?>
<div class="list-group-item" id="<?= e($rowId) ?>">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <?php if ($kind === 'department'): ?><span class="avatar-text avatar-md rounded"><i class="feather-briefcase"></i></span>
            <?php else: ?><span class="avatar-text avatar-md rounded-circle"><?= e(mb_strtoupper(mb_substr((string) $p['principal_name'], 0, 1))) ?></span><?php endif; ?>
            <div class="min-w-0">
                <div class="fw-semibold text-truncate"><?= e((string) $p['principal_name']) ?></div>
                <div class="d-flex flex-wrap gap-1">
                    <?php if ($kind === 'agent'): ?><span class="badge text-bg-light border">Agent</span><?php endif; ?>
                    <?php if ($kind === 'department'): ?><span class="badge text-bg-light border">Department</span><?php endif; ?>
                    <?php if ($p['is_external']): ?><span class="badge text-bg-light border">External</span><?php endif; ?>
                    <?php if ($p['is_viewer']): ?><span class="fs-11 text-muted" id="<?= e($rowId) ?>-cap">(a Viewer: at most comment)</span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($may): ?>
            <div class="d-flex align-items-center gap-1">
                <form method="post" action="/boards/share-set.php" hx-post="/boards/share-set.php" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="<?= $field ?>" value="<?= $pid ?>">
                    <select name="level" id="<?= e($rowId) ?>-level" class="form-select btn-touch w-auto share-level" aria-label="<?= e((string) $p['principal_name']) ?>'s level">
                        <?php foreach ($levels as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['level'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-light btn-sm">Save</button></noscript>
                </form>
                <form method="post" action="/boards/share-remove.php" hx-post="/boards/share-remove.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Stop sharing with <?= e((string) $p['principal_name']) ?>?">
                    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="<?= $field ?>" value="<?= $pid ?>">
                    <button type="submit" class="btn btn-light btn-touch" id="<?= e($rowId) ?>-remove" aria-label="Stop sharing with <?= e((string) $p['principal_name']) ?>"><i class="feather-x"></i></button>
                </form>
            </div>
        <?php else: ?>
            <?= view('shared/level-badge.php', ['level' => (string) $p['level']]) ?>
        <?php endif; ?>
    </div>
    <?php if ($p['needs_grant']): ?><?= view('boards/partials/needs-grant.php', ['name' => (string) $p['principal_name'], 'rowId' => $rowId]) ?><?php endif; ?>
</div>
