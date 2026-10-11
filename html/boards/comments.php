<?php
declare(strict_types=1);
/**
 * /boards/{board}/comments?open=&mine= — screen `board-comments` (comments-notifications.md): the board's threads as cards, open first and then resolved
 * (`open=1` open only, `open=0` resolved only, `mine=1` the threads I wrote in or am mentioned in), a card opens its thread inline. Level view to read.
 * Logs screen.view with the board. JSON: the threads (`rows`, `total`); with `format=pins` the canvas's pins — {pins: [{id, item_id, x, y, author_id, author_name,
 * author_kind, replies, resolved}]}, resolved ones only with `resolved=1`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/present.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$id = (string) $gate['board_id'];
if (wants_json() && ($_GET['format'] ?? '') === 'pins') {
    respond_screen(['board_id' => $id, 'pins' => board_pins($pdo, $id, in_array((string) ($_GET['resolved'] ?? ''), ['1', 'yes', 'true'], true))]);
}
$open = (string) ($_GET['open'] ?? '');
$f = ['open' => $open === '1' ? 1 : ($open === '0' ? 0 : null), 'mine' => in_array((string) ($_GET['mine'] ?? ''), ['1', 'yes', 'true'], true)];
$page = max(1, (int) ($_GET['page'] ?? 1));
$r = find_threads($pdo, $id, $f, $page);
log_screen_view($pdo, 'board-comments', $id);
if (wants_json()) {
    respond_screen(['board_id' => $id, 'title' => (string) $gate['title'], 'total' => $r['total'], 'page' => $page, 'rows' => array_map('present_thread_row', $r['rows'])]);
}
render_screen((string) $gate['title'] . ' · Comments', view('comments/page.php', ['b' => $gate, 'rows' => $r['rows'], 'total' => $r['total'], 'page' => $page,
    'pages' => max(1, (int) ceil($r['total'] / THREAD_PAGE)), 'f' => $f, 'tz' => member_timezone()]), ['activeNav' => 'boards', 'screen' => 'board-comments', 'entity' => 'board', 'recordId' => $id]);
