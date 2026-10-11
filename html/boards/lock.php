<?php
declare(strict_types=1);
/** Action `board_lock` (log board.lock — level full): **board**, **locked** (yes or no). A locked board is read-only for all but its owners. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
if (!req_has('locked')) {
    bd_refuse_fields(['locked' => 'Say whether the board is locked: yes or no.']);
}
$locked = bd_yes('locked');
$id = (string) $b['board_id'];
bd_guard($pdo, static function () use ($pdo, $id, $locked, $b): void {
    $pdo->beginTransaction();
    board_lock($pdo, $id, (int) current_member_id(), $locked);
    log_activity($pdo, 'board.lock', 'board', $id, ['board_id' => $id, 'before' => ['locked' => (bool) $b['is_locked']], 'after' => ['locked' => $locked]]);
    $pdo->commit();
});
bd_done(($locked ? 'Locked ' : 'Unlocked ') . $b['title'], $id, bd_land(return_path('/boards/' . $id . '/settings'), $locked ? 'locked' : 'unlocked'), 'boardChanged', ['locked' => $locked]);
