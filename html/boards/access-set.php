<?php
declare(strict_types=1);
/**
 * Action `board_access_set` (log board.access_set — level full; ✔; an agent's call pauses — other): **board**, **level** (none, view, comment or
 * edit) for everyone in the business — externals never through it. WIDENING ASKS FIRST (decision 3): a browser that sends a wider level
 * without `confirm=1` gets the share page again with the question at its top, and its Confirm re-posts; narrowing needs no question. A
 * JSON caller is the command bar or an agent, whose ✔ and pause come before the call.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'full');
$id = (string) $b['board_id'];
$errors = [];
$level = bd_choice('level', null, ['none', 'view', 'comment', 'edit'], 'The level for everyone', $errors, false);
if ($level === null && !isset($errors['level'])) {
    $errors['level'] = 'Everyone may view, comment or edit — or nothing.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$before = share_level_of($pdo, $id, 'everyone', null);
$rank = ['none' => 0, 'view' => 1, 'comment' => 2, 'edit' => 3];
if (!wants_json() && $rank[$level] > $rank[$before] && !bd_yes('confirm')) {
    // the share page again, with the question at its top (its Confirm re-posts with confirm=1; Cancel is the page as it was)
    render_screen($b['title'] . ' · Share', view('boards/share.php', ['b' => $b, 'perm' => board_permissions($pdo, $id), 'may' => true, 'business' => (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: 'the business'),
        'notice' => null, 'confirm' => ['level' => $level, 'before' => $before]]), ['activeNav' => 'boards', 'screen' => 'board-share', 'entity' => 'board', 'recordId' => $id]);
    exit;
}
if ($level !== $before) {
    bd_guard($pdo, static function () use ($pdo, $id, $level, $before): void {
        $pdo->beginTransaction();
        board_access_set($pdo, $id, (int) current_member_id(), $level);
        log_activity($pdo, 'board.access_set', 'board', $id, ['board_id' => $id, 'before' => ['level' => $before], 'after' => ['level' => $level]]);
        $pdo->commit();
    });
}
bd_done($level === $before ? 'Nothing to change' : 'Everyone in the business: ' . $level, $id, bd_land(return_path('/boards/' . $id . '/share'), 'access'), 'boardChanged',
    ['level' => $level]);
