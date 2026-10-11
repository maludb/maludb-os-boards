<?php
declare(strict_types=1);
/**
 * Action `board_transfer` (log board.transfer — level full; ✔; an agent's call pauses — other; cannot be undone — the new owner hands it
 * back): **board**, **new_owner** (a person who may make boards — the database's rule and its sentence). The previous owner keeps edit.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
$errors = [];
$to = bd_ref($pdo, 'new_owner', null, 'SELECT 1 FROM members WHERE id = :id', 'new owner', $errors, false);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$old = $b['owner_member_id'] !== null ? (int) $b['owner_member_id'] : null;
bd_guard($pdo, static function () use ($pdo, $id, $to, $old): void {
    $pdo->beginTransaction();
    board_transfer($pdo, $id, (int) current_member_id(), $to);
    log_activity($pdo, 'board.transfer', 'board', $id, ['board_id' => $id, 'before' => ['owner_member_id' => $old], 'after' => ['owner_member_id' => $to]]);
    $pdo->commit();
});
$name = (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $to]) ?? '');
bd_done('Handed ' . $b['title'] . ' to ' . $name, $id, bd_land(return_path('/boards/' . $id . '/share'), 'transferred'), 'boardChanged', ['new_owner' => $to]);
