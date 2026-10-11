<?php
declare(strict_types=1);
/** Action `folder_add_board` (log folder.add — its owner or a Boards admin, and view on the board — bd_folder_add): **folder**, **board**. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/write.php';
bd_handler_begin();
$pdo = db();
$errors = [];
$folderId = ctype_digit((string) (req_val('folder') ?? '')) ? (int) req_val('folder') : null;
if ($folderId === null) {
    bd_refuse_fields(['folder' => 'Choose a folder.']);
}
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'view');
$id = (string) $b['board_id'];
bd_guard($pdo, static function () use ($pdo, $folderId, $id): void {
    $pdo->beginTransaction();
    folder_add($pdo, $folderId, $id, (int) current_member_id());
    $name = one_value($pdo, 'SELECT name FROM board_folders WHERE id = :f', ['f' => $folderId]);
    log_activity($pdo, 'folder.add', 'folder', $folderId, ['board_id' => $id, 'after' => ['folder_id' => $folderId, 'name' => $name, 'board_id' => $id]]);
    $pdo->commit();
});
bd_done('Filed ' . $b['title'], $folderId, bd_land(return_path('/folders/' . $folderId), 'filed'), 'folderChanged', ['board_id' => $id]);
