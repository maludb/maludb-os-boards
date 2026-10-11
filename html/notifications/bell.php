<?php
declare(strict_types=1);
/** GET /notifications/bell — the header's bell (the unread count): the shell polls it every 60 s (hx-trigger on #header-bell). Not logged. JSON: {unread}. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shell/queries.php';
require_login();
$pdo = db();
$n = bell_count($pdo, (int) current_member_id());
if (wants_json()) {
    respond_screen(['unread' => $n]);
}
session_write_close();
header('Cache-Control: no-store');
echo view('notifications/partials/bell.php', ['unread' => $n]);
