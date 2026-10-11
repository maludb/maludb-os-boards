<?php
declare(strict_types=1);
/**
 * Action `file_upload` (log file.upload — level edit; people only): POST multipart **board**, **file**. The door mints the item's id
 * (pen-files.md divergence 1): the file is stored against it and answered as `item_id`; the island then commits the `image` / `document`
 * create with exactly that id. Up to max_upload_mb (25 MB — past it 422 with the sentence; past the vhost's 32 MB body Apache answers 413,
 * and so does this door when PHP dropped an oversized body); the type by finfo, never the name. The thumbnails are made before the answer.
 * record_id = the attachment. An upload never committed as an item is an orphan the worker removes after 24 hours.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/files/write.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';

$ini = static function (string $k): int {
    $v = trim((string) ini_get($k));
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $ini('post_max_size')) {
    json_error('too_large', 'That file is too large to send.', 413);           // PHP dropped the body: nothing to check a token against
}
bd_handler_begin();
require_human();
$pdo = db();
$gate = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$board = (string) $gate['board_id'];
$maxMb = (int) (one_value($pdo, 'SELECT max_upload_mb FROM bd_settings WHERE id = 1') ?? 25);
$tooBig = 'Boards keeps files up to ' . $maxMb . ' MB.';
$f = $_FILES['file'] ?? null;
if (!is_array($f) || is_array($f['error'] ?? null)) {
    bd_refuse_fields(['file' => 'Choose a file.']);
}
switch ((int) $f['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        bd_refuse_fields(['file' => $tooBig]);
    case UPLOAD_ERR_NO_FILE:
        bd_refuse_fields(['file' => 'Choose a file.']);
    case UPLOAD_ERR_PARTIAL:
        bd_refuse_fields(['file' => 'The upload was cut short — try again.']);
    default:
        error_log('file_upload: PHP upload error ' . (int) $f['error']);
        bd_refuse_fields(['file' => 'The file could not be received — try again.']);
}
if ((int) $f['size'] > $maxMb * 1024 * 1024) {
    bd_refuse_fields(['file' => $tooBig . ' This one is ' . bytes_words((int) $f['size']) . '.']);
}
if ((int) $f['size'] === 0) {
    bd_refuse_fields(['file' => 'That file is empty.']);
}
$me = (int) current_member_id();
$up = bd_guard($pdo, static function () use ($pdo, $board, $me, $f): array {
    $pdo->beginTransaction();
    try {
        $up = store_upload($pdo, $board, $me, $f);
        log_activity($pdo, 'file.upload', 'board', $board, ['board_id' => $board, 'after' => ['attachment_id' => $up['attachment_id'], 'item_id' => $up['item_id'],
            'mime_type' => $up['mime_type'], 'byte_size' => $up['byte_size'], 'kind' => $up['kind']]]);
        $pdo->commit();
        return $up;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        store_upload_cleanup($pdo);
        throw $e;
    }
});
session_write_close();
emit_action_status(true, ['did' => 'Uploaded ' . $up['filename'], 'record_id' => $up['attachment_id']]);
json_response(['ok' => true, 'did' => 'Uploaded ' . $up['filename'], 'record_id' => $up['attachment_id'], 'url' => '/files/' . $up['attachment_id']] + $up);
