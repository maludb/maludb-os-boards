<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log settings.prefs — own): email_enabled (yes or no), text_enabled (yes or no), kinds[] (mention, reply, comment, share, agent_replied), text_kinds[],
 * digest (yes or no). A field left out stays as it was. A form posts every checkbox's `<name>__sent` marker so an unchecked box means no (a list posts nothing when empty).
 * The log holds the NAMES of the fields changed. The text goes only when the kernel (K6) knows a verified phone and no opt-out — the worker's business, not this form's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cur = prefs_of($pdo, $me);
$errors = [];
$f = prefs_changes_from_request($cur, $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
if ($f !== []) {
    bd_guard($pdo, static function () use ($pdo, $me, $f): void {
        $pdo->beginTransaction();
        prefs_save($pdo, $me, $f);
        log_activity($pdo, 'settings.prefs', 'notification_prefs', $me, ['after' => ['changed' => array_keys($f)]]);
        $pdo->commit();
    });
}
bd_done($f === [] ? 'Nothing to change' : 'Saved your notification settings', $me, bd_land(return_path('/settings/'), $f === [] ? null : 'saved'), 'prefsChanged', ['changed' => array_keys($f)]);
