<?php
declare(strict_types=1);

/**
 * The file writes (slice 5; docs/build-specs/pen-files.md): an upload stored against an item id the door mints (divergence 1 — the island
 * commits the item with exactly that id), its thumbnails made at once; the worker's purges — an upload whose item was never committed after
 * 24 hours, and the items deleted past op_retention_days through bd_purge_deleted_items() — each unlinking only inside the store.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/thumbs.php';
require_once dirname(__DIR__) . '/items/layout.php';    // uuid_v4() — the door mints the item's id

/**
 * Store one uploaded file ($file = one entry of $_FILES, already within the size limits) for a board: the type by finfo (refused with the
 * sentence otherwise), the bytes hashed, the original under `attachments/{yyyy}/{mm}/`, the thumbnails beside, ONE attachments row
 * (record_type item, record_uuid = a fresh item id, board_id). Answers ['attachment_id', 'item_id', 'kind' (image | document — an image too
 * large to preview is a document), 'mime_type', 'filename', 'byte_size', 'width', 'height', 'sizes' (the thumbnail sizes made), 'preview'].
 * Runs in the caller's transaction; the files it wrote are removed when the transaction is rolled back by the caller (store_upload_cleanup()).
 */
function store_upload(PDO $pdo, string $boardId, int $by, array $file): array
{
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = upload_filename((string) ($file['name'] ?? ''));
    $type = file_type_of($tmp, $name);
    if ($type === null) {
        throw new DomainException('Boards keeps images and documents, not ' . file_type_words($tmp) . '.');
    }
    [$mime, $kind, $ext] = $type;
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext && !($ext === 'jpg' && preg_match('/\.jpe?g$/i', $name)) && !($ext === 'md' && preg_match('/\.markdown$/i', $name))) {
        $name = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name) . '.' . $ext;     // the name follows the bytes: a PNG renamed .jpg is shown as .png
    }
    $sha = (string) hash_file('sha256', $tmp);
    $size = (int) filesize($tmp);
    $itemId = uuid_v4();
    $aid = (int) one_value($pdo, "INSERT INTO attachments (record_type, record_uuid, board_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
                                  VALUES ('item', CAST(:i AS uuid), CAST(:b AS uuid), :f, :m, :n, :h, '', :by) RETURNING id",
        ['i' => $itemId, 'b' => $boardId, 'f' => $name, 'm' => $mime, 'n' => $size, 'h' => $sha, 'by' => $by]);
    [$rel, $abs] = file_original_path($pdo, $aid, $sha, $ext);
    $GLOBALS['__upload_written'][] = $abs;
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $abs) : copy($tmp, $abs);
    if (!$moved) {
        throw new RuntimeException('The file could not be stored.');
    }
    @chmod($abs, 0640);
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $rel, 'id' => $aid]);
    $t = make_thumbnails($abs, $mime);
    foreach ($t['thumbnails'] as $p) {
        $GLOBALS['__upload_written'][] = $p;
    }
    $thumbs = record_thumbnails($pdo, $aid, $t);
    if ($thumbs === [] && $t['none'] !== null && $kind === 'image' && $mime !== 'image/svg+xml') {
        $kind = 'document';                                  // a raster that cannot be previewed is a file card (decision 2)
    }
    return ['attachment_id' => $aid, 'item_id' => $itemId, 'kind' => $kind, 'mime_type' => $mime, 'filename' => $name, 'byte_size' => $size,
            'width' => $t['width'], 'height' => $t['height'], 'sizes' => array_map('intval', array_keys($thumbs)), 'preview' => $t['none'] === null];
}

/**
 * Record what make_thumbnails() made on an attachment: width and height, `thumbnails` {size: path relative to the store} — or {"none": why}
 * when there is no preview, so it is never tried again —, `thumbnail_path` the 256 one. Answers the sizes' paths.
 */
function record_thumbnails(PDO $pdo, int $attachmentId, array $t): array
{
    $store = bd_storage_dir($pdo);
    $thumbs = [];
    foreach ($t['thumbnails'] as $size => $p) {
        $thumbs[(string) $size] = substr((string) $p, strlen($store) + 1);
    }
    $pdo->prepare('UPDATE attachments SET width = COALESCE(:w, width), height = COALESCE(:h, height), thumbnails = CAST(:t AS jsonb), thumbnail_path = :tp WHERE id = :id')
        ->execute(['w' => $t['width'], 'h' => $t['height'], 'tp' => $thumbs['256'] ?? null, 'id' => $attachmentId,
                   't' => json_encode($thumbs === [] && $t['none'] !== null ? ['none' => $t['none']] : (object) $thumbs)]);
    return $thumbs;
}

/** The files a failed upload wrote (its transaction rolled back): removed. */
function store_upload_cleanup(PDO $pdo): void
{
    unlink_paths($GLOBALS['__upload_written'] ?? [], $pdo);
    $GLOBALS['__upload_written'] = [];
}

/** The client's file name, cleaned: no path, no control characters, at most 200 characters (the extension kept). */
function upload_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'file';
    }
    if (mb_strlen($name) > 200) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = $ext !== '' && mb_strlen($ext) <= 8 ? '.' . $ext : '';
        $name = mb_substr($name, 0, 200 - mb_strlen($ext)) . $ext;
    }
    return $name;
}

/**
 * An upload whose item was never committed (no board_items row has its record_uuid) and that is older than 24 hours: the row deleted and its
 * files unlinked. Answers how many; $byBoard gets the count per board (one `file.purge` row each). The worker's (no member).
 */
function purge_orphans(PDO $pdo, DateTimeImmutable $now, ?array &$byBoard = null): int
{
    $byBoard = [];
    $rows = $pdo->prepare("SELECT a.id, a.board_id, a.storage_path, a.thumbnails FROM attachments a
                            WHERE a.record_type = 'item' AND a.created_at < CAST(:cut AS timestamptz)
                              AND NOT EXISTS (SELECT 1 FROM board_items i WHERE i.id = a.record_uuid)
                            ORDER BY a.id LIMIT 500");
    $rows->execute(['cut' => $now->modify('-24 hours')->format(DATE_ATOM)]);
    $n = 0;
    foreach ($rows->fetchAll() as $r) {
        $paths = [(string) $r['storage_path']];
        foreach ((array) json_decode((string) $r['thumbnails'], true) as $k => $p) {
            if (ctype_digit((string) $k)) {
                $paths[] = (string) $p;
            }
        }
        $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => (int) $r['id']]);
        unlink_paths($paths, $pdo);
        $byBoard[(string) $r['board_id']] = ($byBoard[(string) $r['board_id']] ?? 0) + 1;
        $n++;
    }
    return $n;
}

/**
 * Items deleted longer than op_retention_days: bd_purge_deleted_items() deletes them with their attachments' rows and answers the files'
 * paths, which are unlinked here (only inside the store). Answers ['purged_items', 'files_removed']. The worker's (no member).
 */
function purge_deleted_items(PDO $pdo): array
{
    $due = (int) one_value($pdo, "SELECT count(*) FROM board_items WHERE deleted_at < now() - make_interval(days => bd_setting_int('op_retention_days')::int)");
    $paths = json_decode((string) one_value($pdo, 'SELECT to_json(bd_purge_deleted_items())'), true);
    $paths = array_values(array_filter((array) $paths, static fn ($p): bool => is_string($p) && $p !== ''));   // a {"none": why} reason resolves to no file
    return ['purged_items' => $due, 'files_removed' => unlink_paths($paths, $pdo)];
}
