<?php
declare(strict_types=1);
/**
 * Action `connector_create` (log item.connect — level edit; agents yes): **board**, **from**, **to** (item ids), shape (straight | elbowed |
 * curved — elbowed by default), caption (≤ 200), arrow (end | start | both | none — end by default). ONE create op through bd_commit_ops();
 * the database refuses a connector to a connector, across boards or to an item that is not there (its sentence, 422). record_id = the seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/connectors.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$uuid = static fn (?string $v): ?string => $v !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($v)) ? strtolower($v) : null;
$from = $uuid(req_val('from'));
$to = $uuid(req_val('to'));
if ($from === null) { $errors['from'] = 'Name the item the connector starts from, by id.'; }
if ($to === null) { $errors['to'] = 'Name the item the connector goes to, by id.'; }
if ($from !== null && $from === $to) { $errors['to'] = 'A connector joins two different items.'; }
$shape = bd_choice('shape', 'elbowed', CONNECTOR_SHAPES, 'The shape', $errors, false) ?? 'elbowed';
$arrow = bd_choice('arrow', 'end', array_keys(CONNECTOR_ARROWS), 'The arrow', $errors, false) ?? 'end';
$caption = bd_text('caption', null, 200, 'The caption', $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$me = (int) current_member_id();
[$a, $c] = bd_guard($pdo, static function () use ($pdo, $id, $me, $from, $to, $shape, $arrow, $caption): array {
    $pdo->beginTransaction();
    $c = connector_ops($pdo, $id, $from, $to, ['shape' => $shape, 'arrow' => $arrow, 'caption' => $caption]);
    $a = commit_ops($pdo, $id, $me, items_source(), null, items_batch_id(), [$c['op']], current_agent_run_id(), agent_run_request_id() ?? request_id());
    if (!empty($a['duplicate'])) {
        $c['id'] = batch_created_ids($pdo, $id, (int) $a['seq'])[0] ?? $c['id'];
    } else {
        log_activity($pdo, 'item.connect', 'board', $id, ['board_id' => $id, 'after' => ['seq' => (int) $a['seq'], 'connector' => $c['id'], 'from' => $from, 'to' => $to,
            'shape' => $shape, 'arrow' => $arrow, 'box' => $a['summary']['bbox'] ?? null]]);
    }
    $pdo->commit();
    return [$a, $c];
});
bd_done('Connected ' . $c['from'] . ' → ' . $c['to'], (int) $a['seq'], '/boards/' . $id . '?item=' . $c['id'], '', ['connector' => $c['id'], 'duplicate' => (bool) $a['duplicate']]);
