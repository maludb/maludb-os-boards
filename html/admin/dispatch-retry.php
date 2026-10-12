<?php
declare(strict_types=1);
/**
 * Action `dispatch_retry` (log dispatch.retry — right agents.manage, people only): **dispatch** (an id). A failed dispatch goes back to sent and is sent by the next pass of
 * the worker; one waiting for approval is polled at once; anything else is refused. record_id = the dispatch. Lands on the dispatches page.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
bd_handler_begin();
$pdo = db();
require_human();
require_right('agents.manage');
$errors = [];
$id = bd_int('dispatch', null, 1, PHP_INT_MAX, 'The dispatch', $errors);
if ($id === null && !isset($errors['dispatch'])) {
    $errors['dispatch'] = 'Name the dispatch.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$was = bd_guard($pdo, static function () use ($pdo, $id): string {
    $pdo->beginTransaction();
    $was = retry_dispatch($pdo, (int) $id);
    $row = one_row($pdo, 'SELECT board_id, agent_member_id, attempts FROM agent_dispatches WHERE id = :d', ['d' => $id]);
    log_activity($pdo, 'dispatch.retry', 'comment', null, ['board_id' => $row['board_id'], 'after' => ['dispatch_id' => (int) $id, 'agent_member_id' => (int) $row['agent_member_id'],
        'was' => $was, 'attempts' => (int) $row['attempts']]]);
    $pdo->commit();
    return $was;
});
bd_done($was === 'failed' ? 'Put the dispatch back in the queue' : 'Asked the kernel about the dispatch again', $id, bd_land('/admin/dispatches', 'retried'), 'dispatchChanged', ['dispatch_id' => $id]);
