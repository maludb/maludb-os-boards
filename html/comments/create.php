<?php
declare(strict_types=1);
/**
 * Action `comment_create` (log comment.create — right boards.comment, level comment): **board**, **text** (@Name mentions a person, an agent or a department),
 * item (an item of the board to pin it to; x and y then an offset from its top-right corner), x and y (a point on the canvas when there is no item), context[]
 * (the item ids it is about). The database keeps the rest: who is told (only a person who can see the board), which agent is dispatched (one who can see it),
 * which one NEEDS A SHARE (a mention never grants). The answer carries `dispatched`, `needs_share` (id + name), `not_shared` and `unresolved_mentions`.
 * Over HTMX from the rail's form the answer is the new thread, with the share prompts above it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/comments/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_right('boards.comment');
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'comment');
$board = (string) $b['board_id'];
$errors = [];
$text = comment_text_field($pdo, $errors);
$item = req_val('item');
$item = $item === null || $item === '' ? null : board_uuid($item);
if (req_val('item') !== null && req_val('item') !== '' && $item === null) {
    $errors['item'] = 'That is not an item of this board.';
}
if ($item !== null && one_value($pdo, 'SELECT 1 FROM mcp_board_items WHERE item_id = CAST(:i AS uuid) AND board_id = CAST(:b AS uuid)', ['i' => $item, 'b' => $board]) === null) {
    $errors['item'] = 'That is not an item of this board.';
}
$num = static function (string $n) use (&$errors): ?float {
    $v = req_val($n);
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) || abs((float) $v) > 1e7) {
        $errors[$n] = 'The ' . $n . ' is a number.';
        return null;
    }
    return round((float) $v, 2);
};
$x = $num('x');
$y = $num('y');
if (($x === null) !== ($y === null)) {
    $errors['x'] = 'Give both x and y, or neither.';
}
if ($item === null && ($x === null || $y === null) && !isset($errors['item'])) {
    $errors['item'] = 'Pin the comment to an item or to a point on the board.';
}
$context = array_values(array_filter(array_map(static fn (string $c): ?string => board_uuid($c), request_list('context') ?? [])));
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$r = bd_guard($pdo, static function () use ($pdo, $me, $board, $text, $item, $x, $y, $context): array {
    $pdo->beginTransaction();
    $r = comment_create($pdo, $board, $me, $text['runs'], $item, $x, $y, $context, current_agent_run_id());
    log_activity($pdo, 'comment.create', 'comment', (string) $r['id'], ['board_id' => $board, 'after' => ['comment_id' => $r['id'], 'item_id' => $item,
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
comment_finish($pdo, (string) $r['root'], 'Commented', (string) $r['id'], $board, [
    'dispatched' => array_map('intval', $r['dispatched'] ?? []),
    'needs_share' => array_map(static fn ($id, $n): array => ['member_id' => (int) $id, 'name' => $n], array_keys($needs), $needs),
    'not_shared' => array_values($told), 'unresolved_mentions' => $text['unresolved'],
], ['needs_share' => $needs, 'not_shared' => $told, 'unresolved' => $text['unresolved']]);
