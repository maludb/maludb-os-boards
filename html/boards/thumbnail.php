<?php
declare(strict_types=1);
/**
 * /boards/{board}/thumbnail (canvas-core.md "The doors (PHP)"; canvas.md "The doors").
 *   POST — the cards' preview from the island: a PNG body (Content-Type image/png) ≤ 512 kB, at most 480 × 300, at most one per board per five
 *          minutes (sooner → 429 "not yet"). Level edit. Stored as a `board_thumbnail` attachment replacing the previous one (its file
 *          unlinked); boards.thumbnail_attachment_id set. NOT logged: it is a cache (canvas-core decision 10).
 *   GET  — the current one behind the board's wall (level view), until slice 5's /files/{id} door serves every file; 404 when none.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';

const THUMBNAIL_MAX_BYTES = 512 * 1024;
const THUMBNAIL_EVERY_SECONDS = 300;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $pdo = db();
    $gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
    session_write_close();
    $t = one_row($pdo, 'SELECT a.storage_path, a.byte_size, a.sha256 FROM boards b JOIN attachments a ON a.id = b.thumbnail_attachment_id WHERE b.id = CAST(:b AS uuid)',
        ['b' => (string) $gate['board_id']]);
    $path = $t === null ? null : bd_storage_dir($pdo) . '/' . ltrim((string) $t['storage_path'], '/');
    if ($path === null || !str_starts_with((string) $t['storage_path'], 'thumbnails/') || !is_file($path)) {
        refuse(404, 'This board has no preview yet.');
    }
    $GLOBALS['__json_sent'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $etag = '"' . substr((string) $t['sha256'], 0, 16) . '"';
    header('Cache-Control: private, max-age=60');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

bd_handler_begin();
require_human();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : (string) (req_val('board') ?? ''), 'edit');
$id = (string) $gate['board_id'];
$b = board_for_canvas($pdo, $id);
if ($b !== null && $b['thumbnail_age'] !== null && $b['thumbnail_age'] < THUMBNAIL_EVERY_SECONDS) {
    header('Retry-After: ' . (THUMBNAIL_EVERY_SECONDS - $b['thumbnail_age']));
    emit_action_status(false, ['error' => 'Not yet: one preview a board every five minutes.']);
    json_error('rate_limited', 'Not yet: one preview a board every five minutes.', 429);
}
$png = (string) file_get_contents('php://input', false, null, 0, THUMBNAIL_MAX_BYTES + 1);
if (strlen($png) > THUMBNAIL_MAX_BYTES) {
    refuse(422, 'A preview is at most 512 kB.');
}
$info = $png === '' ? false : @getimagesizefromstring($png);
if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
    refuse(422, 'A preview is a PNG.');
}
if ($info[0] > 480 || $info[1] > 300 || $info[0] < 1 || $info[1] < 1) {
    refuse(422, 'A preview is at most 480 × 300.');
}
$aid = bd_guard($pdo, static function () use ($pdo, $id, $png): int {
    $pdo->beginTransaction();
    $a = save_thumbnail($pdo, $id, (int) current_member_id(), $png);
    $pdo->commit();
    return $a;
});
emit_action_status(true, ['did' => 'Saved the preview', 'record_id' => $aid]);
json_response(['ok' => true, 'record_id' => $aid, 'bytes' => strlen($png), 'width' => $info[0], 'height' => $info[1]]);
