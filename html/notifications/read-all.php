<?php
declare(strict_types=1);
/**
 * Action `notifications_read_all` (log notification.read_all — own): all (yes — every unread notice of mine). Answers how many. Lands on the list.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!bd_yes('all', true)) {
    bd_refuse_fields(['all' => 'Say yes to mark every unread notice read.']);
}
$n = bd_guard($pdo, static fn (): int => notification_mark_read($pdo, $me, null));
log_activity($pdo, 'notification.read_all', 'notification', null, ['after' => ['count' => $n]]);
bd_done('Marked ' . $n . ' notice' . ($n === 1 ? '' : 's') . ' read', $n, bd_land(return_path('/notifications'), 'read_all'), 'notificationsChanged', ['count' => $n]);
