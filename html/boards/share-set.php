<?php
declare(strict_types=1);
/**
 * Action `board_share` (log board.share — level full; ✔; an agent's call pauses — other): **board**, member (a person or an agent) or department,
 * **level** (view, comment, edit or full). The database keeps the rules: the owner's own row refused, a principal of its kind, the owner's
 * level. A person shared by name is told (one `share` notice); a principal with no Boards grant is flagged `needs_grant`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
[$kind, $pid, $name] = share_principal_from_request($pdo);
$errors = [];
$level = bd_choice('level', null, ['view', 'comment', 'edit', 'full'], 'The level', $errors, false);
if ($level === null && !isset($errors['level'])) {
    $errors['level'] = 'The level is view, comment, edit or full.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$before = share_level_of($pdo, $id, $kind, $pid);
$r = bd_guard($pdo, static function () use ($pdo, $id, $kind, $pid, $name, $level, $before): array {
    $pdo->beginTransaction();
    $r = board_share($pdo, $id, (int) current_member_id(), $kind, $pid, $level);
    log_activity($pdo, 'board.share', 'board', $id, ['board_id' => $id, 'before' => ['principal_kind' => $kind, 'principal_id' => $pid, 'level' => $before],
        'after' => ['principal_kind' => $kind, 'principal_id' => $pid, 'principal_name' => $name, 'level' => $level, 'needs_grant' => (bool) ($r['needs_grant'] ?? false),
        'notified' => ($r['notified'] ?? null) !== null]]);
    $pdo->commit();
    return $r;
});
$needs = (bool) ($r['needs_grant'] ?? false);
bd_done('Shared ' . $b['title'] . ' with ' . $name . ' (' . $level . ')', $id, bd_land(return_path('/boards/' . $id . '/share'), $needs ? 'needs_grant' : 'shared'), 'boardChanged',
    ['needs_grant' => $needs, 'principal_kind' => $kind, 'principal_id' => $pid, 'level' => $level]);
