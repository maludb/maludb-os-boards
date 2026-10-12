<?php
declare(strict_types=1);
/**
 * /admin/settings — screen `admin-settings` (home-admin.md): the page-header form for the manifest's fields (a new board's everyone level, the limits, the retention windows, the
 * trash's days, live updates on or off) and the rest of bd_settings read-only, "set at install". Right settings.manage.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('settings.manage');
$pdo = db();
$s = settings_read($pdo);
log_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    respond_screen(present_settings($s));
}
render_screen('Settings', view('admin/settings.php', ['s' => $s, 'notice' => bd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved. The new settings hold from now on.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-settings']);
