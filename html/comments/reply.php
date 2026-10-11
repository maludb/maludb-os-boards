<?php
declare(strict_types=1);
/**
 * Action `comment_reply` (log comment.reply — right boards.comment, level comment): **comment** (the thread's root, or any comment in it — a reply to a reply
 * hangs on the root), **text**. Notified: the thread's people (a reply), whoever is mentioned; an agent mentioned who can see the board is dispatched. Over HTMX
 * the answer is the thread again.
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
$errors = [];
$text = comment_text_field($pdo, $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$root = (string) $ref['thread_id'];
$r = bd_guard($pdo, static function () use ($pdo, $me, $root, $board, $text): array {
    $pdo->beginTransaction();
    $r = comment_reply($pdo, $root, $me, $text['runs'], current_agent_run_id());
    log_activity($pdo, 'comment.reply', 'comment', (string) $r['id'], ['board_id' => $board, 'after' => ['comment_id' => $r['id'], 'thread_id' => $root,
        'mentions' => comment_mention_counts($text['runs']), 'dispatched' => array_map('intval', $r['dispatched'] ?? []), 'needs_share' => array_map('intval', $r['needs_share'] ?? []),
        'not_shared' => count($r['not_shared'] ?? [])]]);
    $pdo->commit();
    return $r;
});
$names = member_names($pdo, array_merge($r['needs_share'] ?? [], $r['not_shared'] ?? []));
$needs = [];
foreach ($r['needs_share'] ?? [] as $a) {
    $needs[(int) $a] = $names[(int) $a] ?? 'An agent';
}
$told = array_map(static fn ($m): string => $names[(int) $m] ?? 'Someone', $r['not_shared'] ?? []);
comment_finish($pdo, $root, 'Replied', (string) $r['id'], $board, [
    'dispatched' => array_map('intval', $r['dispatched'] ?? []),
    'needs_share' => array_map(static fn ($id, $n): array => ['member_id' => (int) $id, 'name' => $n], array_keys($needs), $needs),
    'not_shared' => array_values($told), 'unresolved_mentions' => $text['unresolved'],
], ['needs_share' => $needs, 'not_shared' => $told, 'unresolved' => $text['unresolved']]);
