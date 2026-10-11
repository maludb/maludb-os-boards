<?php
declare(strict_types=1);
/**
 * /boards/{board}/export — a DOOR, not an action (templates-export.md "Handlers and the export door"; canvas-core.md "Exports"). Right boards.export, level view.
 *   GET ?format=svg[&frame={item}] — the board, or one frame of it, as the SVG dialect that an agent reads (the database's one renderer, bd_region_svg): image/svg+xml,
 *        an attachment named after the board, `Content-Security-Policy: sandbox`. A whole board past 400 items is refused 422 — a file that passes for the whole board never holds part of it
 *        (a frame, or a PNG, instead). Logs board.export {format, scope, items, truncated}.
 *   POST format=png&scope=view|selection|frame (CSRF) — the island made the PNG in the browser and downloaded it; this only logs board.export. Answers 204.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) {
    bd_handler_begin();
} else {
    require_login();
}
require_right('boards.export');
$pdo = db();
$raw = isset($_GET['board']) ? (string) $_GET['board'] : (string) (req_val('board') ?? '');
$b = bd_board_or_404($pdo, $raw, 'view');
$id = (string) $b['board_id'];

if ($post) {
    $errors = [];
    $format = bd_choice('format', null, ['png'], 'The format', $errors, false);
    $scope = bd_choice('scope', null, ['view', 'selection', 'frame'], 'The scope', $errors, false);
    if (!req_has('format')) {
        $errors['format'] = 'Say the format.';
    }
    if (!req_has('scope')) {
        $errors['scope'] = 'Say what was exported: the view, the selection or a frame.';
    }
    $items = bd_int('items', null, 0, 10000000, 'The item count', $errors, true);
    if ($errors !== []) {
        bd_refuse_fields($errors);
    }
    log_activity($pdo, 'board.export', 'board', $id, ['board_id' => $id, 'after' => ['format' => $format, 'scope' => $scope, 'items' => $items, 'truncated' => false]]);
    emit_action_status(true, ['did' => 'Logged the export', 'record_id' => null]);
    $GLOBALS['__json_sent'] = true;
    http_response_code(204);
    exit;
}

$format = request_string('format', 'svg');
if ($format !== 'svg') {
    refuse(422, 'The server makes the SVG; the PNG is made in your browser from the canvas.');
}
$frame = null;
$frameTitle = null;
if (isset($_GET['frame']) && (string) $_GET['frame'] !== '') {
    $frame = board_uuid($_GET['frame']);
    $frameTitle = $frame === null ? null : frame_title($pdo, $id, $frame);
    if ($frameTitle === null) {
        refuse(404, 'There is no such frame on this board.');
    }
}
session_write_close();
$r = board_export_svg($pdo, $id, $frame);
if ($r === null) {
    refuse(404, $frame !== null ? 'There is no such frame on this board.' : 'There is no such board.');
}
if ($r['truncated']) {
    refuse(422, 'This board holds ' . number_format($r['total']) . ' items — export a frame, or a PNG, instead.');
}
log_activity($pdo, 'board.export', 'board', $id, ['board_id' => $id, 'after' => ['format' => 'svg', 'scope' => $frame !== null ? 'frame' : 'board', 'items' => $r['items'], 'truncated' => false]]);
$name = (string) $b['title'] . ($frameTitle !== null ? ' — ' . $frameTitle : '') . '.svg';
$ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name)) ?: 'board.svg';
$GLOBALS['__json_sent'] = true;
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: image/svg+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($r['svg']));
echo $r['svg'];
exit;
