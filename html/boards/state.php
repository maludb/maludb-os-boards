<?php
declare(strict_types=1);
/**
 * GET /boards/{board}/state — the canvas's first load (canvas-core.md "The op protocol"; a door, not a screen): {board: {id, title, level,
 * is_locked, background, start_view, item_count}, items: [bd_item_json …], last_seq, people: {member_id: {name, kind}}, server_time}. The
 * items and the last seq come from ONE statement (one snapshot), written through as JSON text; gzip-encoded when the browser accepts it.
 * The board's gate at `view` (below → 404). Never logged (the open is board.open, on the page).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
session_write_close();                     // a long read never holds the session's lock against the tab's own commits
$s = board_state($pdo, (string) $gate['board_id']);
$board = $s['board'] + ['level' => (string) $gate['my_level']];
$json = '{"board":' . json_encode($board, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',"items":' . $s['json_items']
    . ',"last_seq":' . $s['last_seq']
    . ',"people":' . json_encode((object) $s['people'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',"server_time":"' . gmdate('Y-m-d\TH:i:s\Z') . '"}';
$GLOBALS['__json_sent'] = true;            // JSON mode's shutdown answer stands aside: this door writes its own body
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip') && function_exists('gzencode')) {
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    $json = gzencode($json, 5);
}
header('Content-Length: ' . strlen($json));
echo $json;
