<?php
declare(strict_types=1);
/**
 * Action `board_restore` (log board.restore — the board's owner or boards.admin): **board** from the trash. A deleted board is behind no level
 * (the wall answers nothing for it): bd_board_restore() is the rule — "There is no such board in your trash." (404) to anyone else.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/write.php';
bd_handler_begin();
$pdo = db();
$id = board_uuid(req_val('board'));
if ($id === null) {
    refuse(404, 'There is no such board in your trash.');
}
$title = (string) (one_value($pdo, 'SELECT title FROM mcp_trash WHERE board_id = CAST(:b AS uuid)', ['b' => $id]) ?? '');
bd_guard($pdo, static function () use ($pdo, $id, $title): void {
    $pdo->beginTransaction();
    board_restore($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'board.restore', 'board', $id, ['board_id' => $id, 'after' => ['title' => $title]]);
    $pdo->commit();
});
bd_done('Brought back ' . $title, $id, bd_land(return_path('/trash'), 'restored'), 'boardChanged');
