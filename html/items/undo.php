<?php
declare(strict_types=1);
/**
 * Action `ops_undo` (log ops.undo — own; agents yes): **board**, count (1–10, default 1). Takes back MY last `count` batches on this board
 * within the hour — never an undo batch, never one a later undo of mine already reversed (canvas.md decision 1) — as ONE new batch through
 * bd_undo_ops() (their inverses, newest first). Another member's batch is never mine to undo: the database refuses it (42501 → 403).
 * record_id = the new batch's seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$count = bd_int('count', 1, 1, 10, 'The count', $errors) ?? 1;
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$me = (int) current_member_id();
$a = bd_guard($pdo, static function () use ($pdo, $id, $me, $count): array {
    $pdo->beginTransaction();
    $a = undo_own($pdo, $id, $me, $count, items_source(), current_agent_run_id());
    log_activity($pdo, 'ops.undo', 'board', $id, ['board_id' => $id, 'after' => ['seq' => (int) $a['seq'], 'undone' => $a['undone'],
        'counts' => $a['summary']['counts'] ?? (object) [], 'box' => $a['summary']['bbox'] ?? null]]);
    $pdo->commit();
    return $a;
});
$n = count($a['undone']);
bd_done('Took back your last ' . ($n === 1 ? 'change' : $n . ' changes') . ' on ' . $b['title'], (int) $a['seq'], '/boards/' . $id, '', ['undone' => $a['undone']]);
