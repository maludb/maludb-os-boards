<?php
declare(strict_types=1);
/** Action `board_delete` (log board.delete — level full; ✔; an agent's call pauses — deletion): **board** to the trash for 30 days (board_restore brings it back). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
bd_guard($pdo, static function () use ($pdo, $id, $b): void {
    $pdo->beginTransaction();
    board_delete($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'board.delete', 'board', $id, ['board_id' => $id, 'before' => ['title' => $b['title'], 'item_count' => (int) $b['item_count']]]);
    $pdo->commit();
});
bd_done('Moved ' . $b['title'] . ' to the trash', $id, bd_land('/boards', 'deleted'), 'boardChanged');
