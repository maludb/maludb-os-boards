<?php
declare(strict_types=1);

/**
 * Admin — the writes (slice 10). The settings are one UPDATE of bd_settings (the CHECKs are the referee); a purge is bd_board_purge() — the board with everything on it, in one
 * transaction — and then the files it answered, unlinked only through unlink_paths() (inside the store; anything else is counted and left).
 */
require_once dirname(__DIR__) . '/files/store.php';

/** The settings the form edits, with their kind: int [min, max, label], level, bool, text [max]. */
const SETTINGS_FORM = [
    'business_name' => ['text', 120, 'The business name'],
    'default_everyone_level' => ['level'],
    'item_soft_budget' => ['int', 100, 100000, 'The soft budget'],
    'item_hard_cap' => ['int', 100, 100000, 'The hard cap'],
    'batch_max_ops' => ['int', 1, 5000, 'The ops in a batch'],
    'max_upload_mb' => ['int', 1, 1024, 'The upload size'],
    'op_retention_days' => ['int', 7, 3650, 'The days the changes are kept'],
    'interval_version_days' => ['int', 1, 3650, 'The days autosaves are kept'],
    'daily_version_days' => ['int', 1, 36500, 'The days daily versions are kept'],
    'trash_days' => ['int', 1, 3650, 'The days a deleted board waits'],
    'live_enabled' => ['bool'],
];
const SETTINGS_LEVELS = ['none' => 'Private', 'view' => 'Everyone can view', 'comment' => 'Everyone can comment', 'edit' => 'Everyone can edit'];

/**
 * UPDATE bd_settings with $fields (column => value) and answer what changed: ['before' => …, 'after' => …], each holding only the changed fields. A check the database refuses
 * is a PDOException 23514 for the caller.
 */
function settings_save(PDO $pdo, array $fields): array
{
    $cols = array_keys($fields);
    $old = settings_read($pdo);
    if ($cols !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $cols));
        $args = [];
        foreach ($fields as $c => $v) {
            $args[$c] = is_bool($v) ? ($v ? 't' : 'f') : $v;
        }
        $pdo->prepare("UPDATE bd_settings SET $set WHERE id = 1")->execute($args);
    }
    $new = settings_read($pdo);
    $pick = static fn (array $row): array => array_intersect_key($row, array_flip($cols));
    return bd_diff($pick($old), $pick($new));
}

/**
 * Empty one board from the trash for good (bd_board_purge(): a Boards admin or the worker), then unlink the files it answered — only those that resolve inside the store
 * (unlink_paths()). ['title', 'items', 'files_removed', 'files_left']; files_left counts paths that are still on disk (outside the store, or that could not go).
 */
function trash_purge(PDO $pdo, string $board, string $storageDir): array
{
    $row = one_row($pdo, 'SELECT title, item_count FROM boards WHERE id = CAST(:b AS uuid) AND deleted_at IS NOT NULL', ['b' => $board]) ?? ['title' => '', 'item_count' => 0];
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $paths = pg_text_array((string) one_value($pdo, 'SELECT bd_board_purge(CAST(:b AS uuid))::text', ['b' => $board]));
    if ($own) {
        $pdo->commit();
    }
    $removed = unlink_paths($paths, $pdo);
    $left = 0;
    foreach (array_unique(array_filter($paths, static fn (string $p): bool => $p !== '')) as $p) {
        if (file_exists($p[0] === '/' ? $p : rtrim($storageDir, '/') . '/' . ltrim($p, '/'))) {
            $left++;
        }
    }
    return ['title' => (string) $row['title'], 'items' => (int) $row['item_count'], 'files_removed' => $removed, 'files_left' => $left];
}
