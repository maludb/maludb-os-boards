<?php
declare(strict_types=1);
/**
 * /boards/{board}/ops (canvas-core.md "The op protocol"; canvas.md "The doors").
 *   GET  ?after={seq} — the catch-up and the poll: {batches: [{seq, member_id, client_id, source, ops, summary, at}], last_seq, resync, more}
 *        (≤ 500 batches; `resync` when the seq is older than the board's latest compaction). Level view. Never logged.
 *   POST — action `ops_commit` (people only; the agents use /items/*): the island's batch {client_batch_id, client_id, ops} as a JSON body
 *        (or the same three as form fields, ops as JSON text) → bd_commit_ops(board, me, 'web', …) → {ok, seq, duplicate, versions, replaced,
 *        item_count, warnings, inverse}. The gate is the board's at `view` (a stranger: 404); `edit` — and `full` on a locked board — is the
 *        database's (42501 → 403 with its sentence). A malformed batch 422 (22023); a rule 422 (23514); an item id taken 409 (23505 —
 *        canvas.md's door, so a client tells a collision from a rule); an item not on the board 404 (P0002). ONE log row per committed batch,
 *        named by its verbs (batch_event()); a duplicate (a retry) logs nothing and answers the first commit's seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $pdo = db();
    $gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
    session_write_close();
    $after = filter_var($_GET['after'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($after === false) {
        refuse(422, 'Ask for the changes after a seq: a whole number.');
    }
    json_response(ops_after($pdo, (string) $gate['board_id'], (int) $after));
}

bd_handler_begin();
require_human();
$pdo = db();
$body = $_POST;
if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        refuse(422, 'The batch is not JSON.');
    }
} elseif (isset($body['ops']) && is_string($body['ops'])) {
    $body['ops'] = json_decode($body['ops'], true);
}
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : (isset($body['board']) ? (string) $body['board'] : null), 'view');
$id = (string) $gate['board_id'];
if (!isset($body['ops']) || !is_array($body['ops']) || !array_is_list($body['ops'])) {
    refuse(422, 'A batch is a list of ops.');
}
$batchId = isset($body['client_batch_id']) && $body['client_batch_id'] !== '' ? strtolower((string) $body['client_batch_id']) : null;
if ($batchId !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $batchId)) {
    refuse(422, 'client_batch_id is a uuid.');
}
$clientId = isset($body['client_id']) && $body['client_id'] !== '' ? (string) $body['client_id'] : null;
if ($clientId !== null && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clientId)) {
    refuse(422, 'client_id is up to 64 letters, digits, - and _.');
}
$ops = $body['ops'];
$me = (int) current_member_id();
$answer = bd_guard($pdo, static function () use ($pdo, $id, $me, $clientId, $batchId, $ops): array {
    $pdo->beginTransaction();
    try {
        $a = commit_ops($pdo, $id, $me, 'web', $clientId, $batchId, $ops, null, request_id());
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23505') {
            throw $e;
        }
        $pdo->rollBack();                                  // an item id another item holds: 409, not the kit's 422
        emit_action_status(false, ['error' => db_message($e, 'That id is taken.')]);
        json_error('conflict', db_message($e, 'That id is taken.'), 409);
    }
    if (empty($a['duplicate']) && empty($a['noop'])) {
        $event = batch_event($a['summary'], $ops);
        log_activity($pdo, $event, 'board', $id, ['board_id' => $id, 'after' => batch_log_after($event, $a, $ops)]);
    }
    $pdo->commit();
    return $a;
});
unset($answer['summary']);
emit_action_status(true, ['did' => 'Saved the change', 'record_id' => (int) $answer['seq']]);
json_response(['ok' => true, 'record_id' => (int) $answer['seq']] + $answer);
