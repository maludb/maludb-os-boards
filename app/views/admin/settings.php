<?php
/** Settings (screen `admin-settings`): the page-header form for the manifest's fields, the rest read-only. Data: s (settings_read), notice */
$field = static fn (string $name): string => 'settings-form-field-' . $name;
$num = static function (string $name, string $label, int $min, int $max, string $hint = '') use ($s, $field): string {
    return '<div class="mb-3"><label class="form-label" for="' . $field($name) . '">' . e($label) . '</label>'
        . '<input type="number" inputmode="numeric" name="' . e($name) . '" id="' . $field($name) . '" class="form-control btn-touch" min="' . $min . '" max="' . $max . '" value="' . (int) $s[$name] . '" required>'
        . ($hint !== '' ? '<div class="form-text">' . e($hint) . '</div>' : '') . '</div>';
};
?>
<?= view('shared/page-header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Admin', '/admin'], ['Settings', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card"><div class="card-body">
        <form method="post" action="/admin/settings-save.php" id="settings-form" class="page-header-form" hx-post="/admin/settings-save.php" hx-target="#page-content" hx-swap="innerHTML">
            <?= csrf_field() ?>
            <h6>The business</h6>
            <div class="mb-3">
                <label class="form-label" for="<?= $field('business_name') ?>">Business name <span class="text-muted fs-12">(optional)</span></label>
                <input type="text" name="business_name" id="<?= $field('business_name') ?>" class="form-control btn-touch" maxlength="120" value="<?= e((string) ($s['business_name'] ?? '')) ?>">
            </div>
            <h6 class="mt-4">A new board</h6>
            <fieldset class="mb-3" id="<?= $field('default_everyone_level') ?>"><legend class="form-label fs-14">Who can open a new board without being named</legend>
                <?php foreach (SETTINGS_LEVELS as $key => $label): ?>
                    <div class="form-check"><input class="form-check-input" type="radio" name="default_everyone_level" id="<?= $field('default_everyone_level') ?>-<?= e($key) ?>" value="<?= e($key) ?>"<?= $s['default_everyone_level'] === $key ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= $field('default_everyone_level') ?>-<?= e($key) ?>"><?= e($label) ?></label></div>
                <?php endforeach; ?>
                <div class="form-text">Private is the default. It only sets where a new board starts; its owner changes it on the board's Share page.</div>
            </fieldset>
            <h6 class="mt-4">Limits</h6>
            <?= $num('item_soft_budget', 'Soft budget (items on a board)', 100, 100000, 'A board past this shows a warning. Must be below the hard cap.') ?>
            <?= $num('item_hard_cap', 'Hard cap (items on a board)', 100, 100000, 'Creating above it is refused. Lowering it never removes items.') ?>
            <?= $num('batch_max_ops', 'Changes in one batch', 1, 5000) ?>
            <?= $num('max_upload_mb', 'Largest upload (MB)', 1, 1024) ?>
            <h6 class="mt-4">Keeping things</h6>
            <?= $num('op_retention_days', 'Days the changes are kept', 7, 3650, 'Older changes are folded into a version.') ?>
            <?= $num('interval_version_days', 'Days autosaves are kept', 1, 3650, 'At most the days daily versions are kept.') ?>
            <?= $num('daily_version_days', 'Days daily versions are kept', 1, 36500, 'Named versions are kept for good.') ?>
            <?= $num('trash_days', 'Days a deleted board waits in the trash', 1, 3650) ?>
            <h6 class="mt-4">Live updates</h6>
            <div class="form-check form-switch mb-1">
                <input type="hidden" name="live_enabled" value="0">
                <input class="form-check-input" type="checkbox" role="switch" name="live_enabled" id="<?= $field('live_enabled') ?>" value="1"<?= $s['live_enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="<?= $field('live_enabled') ?>">Boards update live</label>
            </div>
            <div class="form-text mb-3">Off means every board polls every <?= (int) $s['poll_seconds'] ?> seconds instead.</div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-touch" id="settings-form-save-btn">Save the settings</button>
                <?= hx_link('/admin', 'Cancel', 'btn btn-light btn-touch', 'id="settings-form-cancel"') ?>
            </div>
        </form>
    </div></div>
    <div class="alert alert-light border" id="settings-notes">
        <ul class="mb-0 ps-3">
            <li>Lowering the hard cap never removes items; creating above it is refused.</li>
            <li>With live updates off, every board polls every <?= (int) $s['poll_seconds'] ?> seconds.</li>
        </ul>
    </div>
    <div class="card" id="settings-fixed"><div class="card-body">
        <h6>Set at install</h6>
        <dl class="row mb-0 fs-13">
            <?php foreach (['timezone' => 'Business time zone', 'drawing_max_points' => 'Points in a drawing', 'room_max_sockets' => 'People live on one board', 'live_check_seconds' => 'Live check (seconds)',
                'poll_seconds' => 'Poll interval (seconds)', 'key_rate_per_minute' => 'Key calls a minute', 'key_rate_per_day' => 'Key calls a day', 'key_rotation_overlap_hours' => 'Key overlap (hours)'] as $k => $label): ?>
                <dt class="col-8 col-md-4 fw-normal text-muted"><?= e($label) ?></dt><dd class="col-4 col-md-8" id="settings-fixed-<?= e($k) ?>"><?= e((string) $s[$k]) ?></dd>
            <?php endforeach; ?>
        </dl>
        <div class="fs-12 text-muted mt-2">These are set when Boards is installed and are not edited here.</div>
    </div></div>
</div>
