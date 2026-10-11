<?php
declare(strict_types=1);
/**
 * GET /files/{id}[?size=256|1024|2048][&download=1][&inline=1] (pen-files.md "The file door"; divergence 2 — the size is a query parameter): an image, a
 * file or a thumbnail behind its board's wall — `view` on the attachment's board, the board not in the trash; anything less is the same 404 as
 * no file. The canvas asks a size (level of detail) and never the original of a raster; a size the attachment has no thumbnail for is 404
 * (a PDF's sizes are its first page). Content-Type the stored type; nosniff; private, an hour, an ETag. Images inline; an SVG ALWAYS under
 * the sandbox CSP and an attachment — inline only at `?inline=1`, the canvas's <img> (and even there an attachment when opened as a page,
 * Sec-Fetch-Dest: document): two URLs, so a browser's cached inline copy never answers a person who opens the file; documents always
 * attachment with their name. `file.open` is logged when a person opens or downloads an original (never a thumbnail, never `?inline=1`).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/files/store.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';

require_login();
$pdo = db();
$id = (string) ($_GET['id'] ?? '');
$a = ctype_digit($id) && strlen($id) < 19 ? attachment_for_door($pdo, (int) $id) : null;
if ($a === null) {
    refuse(404, 'There is no such file.');
}
session_write_close();
$size = (string) ($_GET['size'] ?? '');
$mime = (string) $a['mime_type'];
if ($size !== '') {
    $thumbs = json_decode((string) $a['thumbnails'], true);
    if (!in_array((int) $size, THUMB_SIZES, true) || !is_array($thumbs) || !isset($thumbs[$size])) {
        refuse(404, 'There is no preview of that size.');
    }
    $rel = (string) $thumbs[$size];
    $mime = match (strtolower(pathinfo($rel, PATHINFO_EXTENSION))) { 'jpg' => 'image/jpeg', 'webp' => 'image/webp', default => 'image/png' };
} else {
    $rel = (string) $a['storage_path'];
}
$abs = file_abs($pdo, $rel);
if ($abs === null || !is_file($abs)) {
    error_log('files/view: attachment ' . (int) $a['id'] . ' has no file at its path');
    refuse(404, 'There is no such file.');
}
$dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
$download = isset($_GET['download']) && $_GET['download'] !== '0';
$isSvg = $size === '' && $mime === 'image/svg+xml';
$isImage = str_starts_with($mime, 'image/');
$inline = ($_GET['inline'] ?? '') === '1';
$attach = $download || ($size === '' && !$isImage) || ($isSvg && (!$inline || $dest === 'document'));

if ($size === '' && !$inline && ($download || $dest === 'document' || $dest === '')) {
    log_activity($pdo, 'file.open', 'board', (string) $a['board_id'], ['board_id' => (string) $a['board_id'],
        'after' => ['attachment_id' => (int) $a['id'], 'mime_type' => (string) $a['mime_type'], 'download' => $attach]]);
}

$GLOBALS['__json_sent'] = true;
while (ob_get_level() > 0) {
    ob_end_clean();
}
$etag = '"' . substr((string) $a['sha256'], 0, 16) . ($size !== '' ? '-' . $size : '') . '"';
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if ($isSvg) {
    header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    header('Vary: Sec-Fetch-Dest');
}
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $mime);
if ($attach) {
    $name = (string) $a['filename'];
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'file';
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
} elseif ($isImage) {
    header('Content-Disposition: inline');
}
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
