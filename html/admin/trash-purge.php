<?php
declare(strict_types=1);
/**
 * Action `trash_purge` (log trash.purge — trash.purge, people only): **board** in the trash, gone for good with its items, ops, versions, comments and files — the files unlinked
 * only inside the store (unlink_paths()). A board not in the trash is 404 (a Boards admin sees every deleted board; anyone else their own); a purge by someone who is not a Boards admin
 * is the database's refusal (403).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/write.php';
bd_handler_begin();
require_human();
require_right('trash.purge');
$pdo = db();
$id = board_uuid(req_val('board'));
if ($id === null || one_value($pdo, 'SELECT 1 FROM mcp_trash WHERE board_id = CAST(:b AS uuid)', ['b' => $id]) === null) {
    refuse(404, 'There is no such board in the trash.');
}
$r = bd_guard($pdo, static function () use ($pdo, $id): array {
    $r = trash_purge($pdo, $id, bd_storage_dir($pdo));
    log_activity($pdo, 'trash.purge', 'board', null, ['board_id' => $id, 'after' => $r]);
    return $r;
});
bd_done('Purged ' . $r['title'], $id, bd_land('/admin', 'purged', 'trash'), 'boardChanged', ['files_removed' => $r['files_removed'], 'files_left' => $r['files_left'], 'items' => $r['items']]);
