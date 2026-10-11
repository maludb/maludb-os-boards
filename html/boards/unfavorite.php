<?php
declare(strict_types=1);
/**
 * Action `board_unfavorite` (log board.unfavorite — own): **board**. Over HTMX from a card's star the answer is the star itself
 * (hx-swap outerHTML); a browser lands back where it was; JSON learns the board.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$on = false;
$id = $on ? (string) bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'view')['board_id'] : board_uuid(req_val('board'));
if ($id === null) {
    refuse(404, 'There is no such board.');
}
$changed = bd_guard($pdo, static fn (): bool => board_favorite($pdo, $id, $me, $on));
if ($changed) {
    log_activity($pdo, 'board.unfavorite', 'board', $id, ['board_id' => $id]);
}
if (is_htmx_request() && str_ends_with((string) ($_SERVER['HTTP_HX_TARGET'] ?? ''), '-star')) {
    emit_action_status(true, ['did' => $on ? 'Starred' : 'Unstarred', 'record_id' => $id]);
    hx_trigger('boardChanged');
    echo view('boards/partials/star.php', ['id' => $id, 'on' => $on, 'return' => return_path('/boards')]);
    exit;
}
bd_done($on ? 'Starred the board' : 'Unstarred the board', $id, bd_land(return_path('/boards')), 'boardChanged');
