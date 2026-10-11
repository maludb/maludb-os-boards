<?php
declare(strict_types=1);
/**
 * /notifications — screen `notifications` (comments-notifications.md): my notices, unread first, 30 a page: the kind's icon, the title, the body, the board's title and
 * when. A notice opens its board at the comment (and is marked read); Mark all read. ?unread=1 shows only the unread. Logs screen.view. JSON: the notices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$unreadOnly = in_array((string) ($_GET['unread'] ?? ''), ['1', 'yes', 'true'], true);
$page = max(1, (int) ($_GET['page'] ?? 1));
$r = find_my_notifications($pdo, $me, $unreadOnly, $page);
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(['unread' => $r['unread'], 'total' => $r['total'], 'page' => $page, 'rows' => array_map('present_notification', $r['rows'])]);
}
$notices = ['read_all' => ['success', 'Marked every notice read.'], 'gone' => ['warning', 'That notice names a board you can no longer open.']];
render_screen('Notifications', view('notifications/index.php', ['rows' => $r['rows'], 'total' => $r['total'], 'unread' => $r['unread'], 'page' => $page,
    'pages' => max(1, (int) ceil($r['total'] / NOTICE_PAGE)), 'unreadOnly' => $unreadOnly, 'tz' => member_timezone(), 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'notifications', 'screen' => 'notifications', 'entity' => 'notification']);
