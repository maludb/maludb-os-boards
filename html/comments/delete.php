<?php
declare(strict_types=1);
/**
 * Action `comment_delete` (log comment.delete — its author, or a board's owner at level full; an agent's call pauses: `deletion`): **comment**. A thread's root takes
 * its replies with it. The database keeps the rule in its own words. Over HTMX the answer is the thread again (or, for a removed root, a line saying so).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$ref = find_comment_ref($pdo, (string) (req_val('comment') ?? ''));
if ($ref === null) {
    refuse(404, 'There is no such comment.');
}
$board = (string) $ref['board_id'];
bd_board_or_404($pdo, $board, 'view');
$id = (string) $ref['comment_id'];
$thread = (string) $ref['thread_id'];
$isRoot = $ref['parent_comment_id'] === null;
bd_guard($pdo, static function () use ($pdo, $me, $id, $board, $isRoot): void {
    $pdo->beginTransaction();
    comment_delete($pdo, $id, $me);
    log_activity($pdo, 'comment.delete', 'comment', $id, ['board_id' => $board, 'after' => ['comment_id' => $id, 'thread' => $isRoot]]);
    $pdo->commit();
});
comment_finish($pdo, $thread, 'Deleted the comment', $id, $board);
