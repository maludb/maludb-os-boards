<?php
declare(strict_types=1);
/**
 * /settings/ — screen `settings` (comments-notifications.md): my notification preferences — email on or off, the morning digest instead of one email per event, texts
 * on or off (the kernel keeps the verified phone and the opt-out; the page says so), the kinds by email and by text — and a link to My tokens. Logs screen.view.
 * JSON: the preferences and the kinds.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/present.php';
require_login();
$pdo = db();
$p = prefs_of($pdo, (int) current_member_id());
log_screen_view($pdo, 'settings');
if (wants_json()) {
    respond_screen(['prefs' => present_prefs($p), 'kinds' => NOTICE_KINDS]);
}
render_screen('My settings', view('settings/index.php', ['p' => $p, 'notice' => bd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved your notification settings.']]),
    'tokens' => has_right('tokens.own')]), ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'notification_prefs']);
