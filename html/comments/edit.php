<?php
declare(strict_types=1);
/**
 * Action `comment_edit` (log comment.edit — its author only, the database's rule): **comment**, **text**. A mention added by an edit tells nobody (only a new
 * comment does); the log holds the length. Over HTMX the answer is the thread again.
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
$errors = [];
$text = comment_text_field($pdo, $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$id = (string) $ref['comment_id'];
$length = mb_strlen(implode('', array_map(static fn (array $r): string => (string) ($r['plain_text'] ?? ''), $text['runs'])));
bd_guard($pdo, static function () use ($pdo, $me, $id, $board, $text, $length): void {
    $pdo->beginTransaction();
    comment_edit($pdo, $id, $me, $text['runs']);
    log_activity($pdo, 'comment.edit', 'comment', $id, ['board_id' => $board, 'after' => ['comment_id' => $id, 'length' => $length]]);
    $pdo->commit();
});
comment_finish($pdo, (string) $ref['thread_id'], 'Edited the comment', $id, $board, ['unresolved_mentions' => $text['unresolved']], ['unresolved' => $text['unresolved']]);
