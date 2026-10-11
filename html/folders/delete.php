<?php
declare(strict_types=1);
/** Action `folder_delete` (log folder.delete — its owner or a Boards admin; ✔; an agent's call pauses — deletion): **folder** — its boards stay where they are. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/write.php';
bd_handler_begin();
$pdo = db();
$f = ctype_digit((string) (req_val('folder') ?? '')) ? find_folder($pdo, (int) req_val('folder')) : null;
if ($f === null) {
    refuse(404, 'There is no such folder.');
}
if (!$f['may_change']) {
    refuse(403, 'Only the folder\'s owner or a Boards admin changes it.');
}
$id = (int) $f['folder_id'];
bd_guard($pdo, static function () use ($pdo, $id, $f): void {
    $pdo->beginTransaction();
    folder_delete($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'folder.delete', 'folder', $id, ['before' => ['folder_id' => $id, 'name' => $f['name'], 'is_team' => (bool) $f['is_team'], 'board_count' => (int) $f['board_count']]]);
    $pdo->commit();
});
bd_done('Deleted the folder ' . $f['name'], $id, bd_land('/folders', 'deleted'), 'folderChanged');
