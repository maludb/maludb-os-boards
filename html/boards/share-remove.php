<?php
declare(strict_types=1);
/** Action `board_unshare` (log board.unshare — level full; ✔; an agent's call pauses — other): **board**, member (a person or an agent) or department — bd_board_share(…, 'none') removes the row. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
[$kind, $pid, $name] = share_principal_from_request($pdo);
$before = share_level_of($pdo, $id, $kind, $pid);
if ($before === 'none') {
    refuse(404, $name . ' has no share on this board to remove.');
}
bd_guard($pdo, static function () use ($pdo, $id, $kind, $pid, $name, $before): void {
    $pdo->beginTransaction();
    board_share($pdo, $id, (int) current_member_id(), $kind, $pid, 'none');
    log_activity($pdo, 'board.unshare', 'board', $id, ['board_id' => $id, 'before' => ['principal_kind' => $kind, 'principal_id' => $pid, 'principal_name' => $name, 'level' => $before],
        'after' => ['level' => 'none']]);
    $pdo->commit();
});
bd_done('Stopped sharing ' . $b['title'] . ' with ' . $name, $id, bd_land(return_path('/boards/' . $id . '/share'), 'unshared'), 'boardChanged');
