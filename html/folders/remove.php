<?php
declare(strict_types=1);
/** Action `folder_remove_board` (log folder.remove — its owner or a Boards admin): **folder**, **board** — the board is where it was. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
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
$id = board_uuid(req_val('board'));
$folderId = (int) $f['folder_id'];
if ($id === null || !bd_guard($pdo, static function () use ($pdo, $folderId, $id, $f): bool {
    $pdo->beginTransaction();
    $gone = folder_remove($pdo, $folderId, $id, (int) current_member_id());
    if ($gone) {
        log_activity($pdo, 'folder.remove', 'folder', $folderId, ['board_id' => $id, 'before' => ['folder_id' => $folderId, 'name' => $f['name'], 'board_id' => $id]]);
    }
    $pdo->commit();
    return $gone;
})) {
    refuse(404, 'That board is not in this folder.');
}
bd_done('Took the board out of ' . $f['name'], $folderId, bd_land(return_path('/folders/' . $folderId), 'removed'), 'folderChanged', ['board_id' => $id]);
