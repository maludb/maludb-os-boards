<?php
declare(strict_types=1);
/**
 * Action `notification_read` (log notification.read — own): **notification**. Marks my notice read and lands on what it is about — its board at the comment —
 * or, for a notice whose board I can no longer open, back on the list. JSON: {did, record_id, location}.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$n = find_my_notification($pdo, (int) (req_val('notification') ?? 0));
if ($n === null) {
    refuse(404, 'There is no such notice.');
}
$changed = bd_guard($pdo, static fn (): int => notification_mark_read($pdo, $me, (int) $n['notification_id']));
log_activity($pdo, 'notification.read', 'notification', (int) $n['notification_id'], ['board_id' => $n['board_id'], 'after' => ['kind' => $n['kind'], 'changed' => $changed]]);
$board = $n['board_id'] === null ? null : one_value($pdo, 'SELECT board_id::text FROM mcp_boards WHERE board_id = CAST(:b AS uuid)', ['b' => $n['board_id']]);
$url = $board === null ? null : notification_url($n);
bd_done($url === null ? 'Marked the notice read' : 'Opened the notice', (int) $n['notification_id'], $url ?? bd_land('/notifications', $n['board_id'] === null ? null : 'gone'), 'notificationsChanged');
