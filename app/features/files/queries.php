<?php
declare(strict_types=1);

/**
 * The file reads (slice 5; docs/build-specs/pen-files.md "Query functions"): the door's row behind the board's wall, and find_attachments
 * (the tool surface's Q14). A path never leaves this file's callers: the door resolves it inside the store and streams the bytes.
 */

/**
 * The attachment if the caller may see it: `view` or more on its board (bd_my_level — the wall), the board not in the trash. null otherwise —
 * the door answers the same 404 for "no such file" and "not yours". The row carries storage_path and thumbnails for the door alone.
 */
function attachment_for_door(PDO $pdo, int $id): ?array
{
    return one_row($pdo, "SELECT a.id, a.board_id, a.record_type, a.record_uuid, a.filename, a.mime_type, a.byte_size, a.sha256, a.storage_path,
                                 a.thumbnails, a.width, a.height
                            FROM attachments a JOIN boards b ON b.id = a.board_id AND b.deleted_at IS NULL
                           WHERE a.id = :id AND bd_level_rank(bd_my_level(a.board_id)) >= bd_level_rank('view')", ['id' => $id]);
}

/**
 * A board's images and files (over mcp_attachments — the wall in the view): those on a live item, newest first; $kind image | document | null.
 * Rows: attachment_id, item_id, filename, mime_type, byte_size, width, height, uploaded_by, created_at — never a path.
 */
function find_attachments(PDO $pdo, string $boardId, ?string $kind, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $kindSql = match ($kind) {
        'image' => "AND i.type = 'image'",
        'document' => "AND i.type = 'document'",
        default => '',
    };
    $st = $pdo->prepare("SELECT x.attachment_id, x.record_uuid AS item_id, x.filename, x.mime_type, x.byte_size, x.width, x.height, x.uploaded_by, x.created_at
                           FROM mcp_attachments x
                           JOIN mcp_board_items i ON i.item_id = x.record_uuid AND i.board_id = x.board_id
                          WHERE x.board_id = CAST(:b AS uuid) AND x.record_type = 'item' {$kindSql}
                          ORDER BY x.created_at DESC, x.attachment_id DESC
                          LIMIT {$limit}");
    $st->execute(['b' => $boardId]);
    return $st->fetchAll();
}

/** The sizes an attachment has thumbnails for (keys of `thumbnails` that are sizes; `{"none": why}` has none). */
function attachment_sizes(array $a): array
{
    $t = json_decode((string) ($a['thumbnails'] ?? '{}'), true);
    return array_values(array_map('intval', array_filter(array_keys(is_array($t) ? $t : []), static fn ($k): bool => ctype_digit((string) $k))));
}

/** "2.4 MB" — a byte count in words for a file card and the rail. */
function bytes_words(int $n): string
{
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1024 * 1024) {
        return round($n / 1024) . ' kB';
    }
    return number_format($n / 1024 / 1024, $n < 10 * 1024 * 1024 ? 1 : 0) . ' MB';
}
