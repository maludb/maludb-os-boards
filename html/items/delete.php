<?php
declare(strict_types=1);
/**
 * Action `item_delete_batch` (log item.delete — level edit; agents yes, PAUSED for an agent: `deletion`): **board**, **items[]** (ids — a
 * frame takes its children unless keep_children), keep_children (yes or no). ONE delete op through bd_commit_ops() — the cascades (a frame's
 * children, an item's connectors) are the database's, and so is the inverse that brings them all back (ops_undo). record_id = the seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$ids = array_map('strtolower', request_list('items') ?? []);
if ($ids === []) {
    bd_refuse_fields(['items' => 'Name the items to delete, by id.']);
}
if (count($ids) > 500) {
    bd_refuse_fields(['items' => 'At most 500 items in one call.']);
}
items_require_on_board($pdo, $id, $ids);
$keep = bd_yes('keep_children', false);
$ops = [['op' => 'delete', 'ids' => $ids, 'keep_children' => $keep]];
$me = (int) current_member_id();
$a = bd_guard($pdo, static function () use ($pdo, $id, $me, $ops, $keep): array {
    $pdo->beginTransaction();
    $a = commit_ops($pdo, $id, $me, items_source(), null, items_batch_id(), $ops, current_agent_run_id(), agent_run_request_id() ?? request_id());
    if (empty($a['duplicate'])) {
        log_activity($pdo, 'item.delete', 'board', $id, ['board_id' => $id, 'after' => batch_log_after('item.delete', $a, $ops) + ['keep_children' => $keep]]);
    }
    $pdo->commit();
    return $a;
});
$gone = (int) ($a['summary']['items'] ?? count($ids));
bd_done('Deleted ' . ($gone === 1 ? '1 item' : $gone . ' items') . ' — ops_undo brings ' . ($gone === 1 ? 'it' : 'them') . ' back', (int) $a['seq'], '/boards/' . $id, '',
    ['items' => $ids, 'duplicate' => (bool) $a['duplicate']]);
