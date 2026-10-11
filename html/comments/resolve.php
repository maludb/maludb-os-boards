<?php
declare(strict_types=1);
/**
 * Action `comment_resolve` (log comment.resolve — right boards.comment, level comment): **comment** (a thread's root). The database keeps the rule (a thread, not a reply). Over HTMX the
 * answer is the thread again.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_right('boards.comment');
$ref = find_comment_ref($pdo, (string) (req_val('comment') ?? ''));
if ($ref === null) {
    refuse(404, 'There is no such thread.');
}
$board = (string) $ref['board_id'];
bd_board_or_404($pdo, $board, 'comment');
$id = (string) $ref['comment_id'];
bd_guard($pdo, static function () use ($pdo, $me, $id, $board): void {
    $pdo->beginTransaction();
    comment_set_resolved($pdo, $id, $me, true);
    log_activity($pdo, 'comment.resolve', 'comment', $id, ['board_id' => $board, 'after' => ['comment_id' => $id]]);
    $pdo->commit();
});
comment_finish($pdo, $id, 'Resolved the thread', $id, $board);
