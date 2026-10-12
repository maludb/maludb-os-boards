<?php
declare(strict_types=1);

/**
 * The dispatch loop (agents-search.md; Spaces' app/features/agents/dispatch.php ported — messages → comments, channels → boards): the worker's pass
 * `dispatches`. A person's comment that mentions an agent who can see the board made an agent_dispatches row (db/011 bd_comment_create()); here each
 * one is ONE turn of the kernel's chat endpoint as that agent, the asker acting (design §5, D10, D11). The outcome goes back through
 * bd_dispatch_record() (db/018), which keeps the placeholder, the reply as one comment, the backoff and the five attempts — PHP never decides whether
 * an agent should answer. Nothing here calls a model and nothing holds a key. A run still going is polled by the next pass.
 * A comment's words, a reply or the context are never in a log payload: ids, counts and the kernel's sentences are.
 */
require_once dirname(__DIR__) . '/comments/richtext.php';

const DISPATCH_WAIT_SECONDS = 25;
const DISPATCH_RUN_LIMIT_SECONDS = 600;                 // a run that has not finished in 10 minutes failed
const DISPATCH_FAILED_RUN = ['failed', 'error', 'errored', 'cancelled', 'canceled', 'timeout'];
const DISPATCH_DECLINED = ['declined', 'rejected', 'refused', 'denied', 'cancelled', 'canceled'];

/**
 * What the agent is told besides the utterance: who it is, why it was asked, where the comment is pinned, which items were selected, the
 * thread so far, the manners. $d is a row of bd_dispatches_due() / _running() / _waiting().
 */
function dispatch_context(array $d): string
{
    $agent = (string) ($d['agent_name'] ?? 'the agent');
    $asker = (string) (($d['acting_name'] ?? '') !== '' ? $d['acting_name'] : 'Someone');
    $pinned = ($d['anchor_item_id'] ?? null) !== null
        ? 'the item ' . $d['anchor_item_id'] . (($d['anchor_excerpt'] ?? '') !== '' ? ' ("' . $d['anchor_excerpt'] . '")' : '')
        : 'the point (' . round((float) ($d['anchor_x'] ?? 0)) . ', ' . round((float) ($d['anchor_y'] ?? 0)) . ')';
    $items = $d['context_items'] ?? [];
    $items = is_string($items) ? (json_decode($items, true) ?: []) : $items;
    $selected = $items === [] ? 'none were selected'
        : implode('; ', array_map(static fn (array $i): string => $i['item_id'] . ' (' . $i['type'] . ')' . (($i['excerpt'] ?? '') !== '' ? ': ' . $i['excerpt'] : ''), $items));
    $thread = trim((string) ($d['thread'] ?? ''));
    return 'You are ' . $agent . ', a member of Boards. ' . $asker . ' mentioned you in a comment on the board "' . $d['board_title'] . '" (board ' . $d['board_id'] . '). '
        . 'The comment is pinned to ' . $pinned . '. "These" means the items selected when it was written: ' . $selected . ".\n"
        . "The thread so far:\n" . ($thread === '' ? '(nothing before this comment)' : $thread) . "\n"
        . 'Read before you change anything (board_overview, then board_read_region around those items), do the work on the board with your tools, then answer in this thread once, '
        . 'as yourself, saying in a sentence or two what you changed. Never mention an agent.';
}

/**
 * The agent's reply as the comment's runs: the text converter as the AGENT sees people (an @Name becomes a mention run only for a member the agent may see).
 * app.member_id is the agent for the call and restored after.
 */
function reply_to_runs(PDO $pdo, string $text, int $agentId): array
{
    $was = (string) $pdo->query("SELECT COALESCE(current_setting('app.member_id', true), '')")->fetchColumn();
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) $agentId]);
    try {
        return comment_text_to_rich($pdo, $text)['runs'];
    } finally {
        $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => $was]);
    }
}

/** bd_dispatch_record(): the one call that writes the outcome. Answers the placeholder's / reply's comment id, or null. */
function dispatch_record(PDO $pdo, int $id, string $status, ?int $runId = null, ?string $requestId = null, ?array $reply = null, ?string $detail = null, ?DateTimeImmutable $now = null): ?string
{
    $st = $pdo->prepare('SELECT bd_dispatch_record(:d, :s, :r, :q, CAST(:b AS jsonb), :t, CAST(:n AS timestamptz))');
    $st->execute(['d' => $id, 's' => $status, 'r' => $runId, 'q' => $requestId, 'b' => $reply === null ? null : json_encode($reply, JSON_UNESCAPED_UNICODE),
                  't' => $detail === null ? null : mb_substr($detail, 0, 500), 'n' => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s.uP')]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string) $v;
}

/** Log a dispatch event: ids, never words. The actor is the asker for a call, the agent for what it did. */
function dispatch_log(PDO $pdo, string $action, array $d, array $after = [], bool $asAgent = false): void
{
    log_activity($pdo, $action, 'comment', (string) $d['comment_id'], [
        'actor_member_id' => $asAgent ? (int) $d['agent_member_id'] : ($d['acting_member_id'] === null ? null : (int) $d['acting_member_id']),
        'board_id' => (string) $d['board_id'], 'agent_run_id' => isset($after['run_id']) ? (int) $after['run_id'] : null,
        'after' => ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => 'mention'] + $after,
    ]);
}

/**
 * A failure or a refusal told once: the record (backoff and the five attempts are the database's), the log row. Returns 'failed' (retried later or for good)
 * or 'refused'. The asker is told by the database when the fifth attempt fails.
 */
function dispatch_fail(PDO $pdo, array $d, string $status, string $detail, DateTimeImmutable $now, ?int $run = null): string
{
    $detail = mb_substr($detail, 0, 200);
    dispatch_record($pdo, (int) $d['dispatch_id'], $status, $run, null, null, $detail, $now);
    $row = $pdo->prepare('SELECT status, attempts FROM agent_dispatches WHERE id = :d');
    $row->execute(['d' => (int) $d['dispatch_id']]);
    $row = $row->fetch() ?: [];
    dispatch_log($pdo, 'agent.fail', $d, ['status' => $status, 'attempts' => (int) ($row['attempts'] ?? 0), 'detail' => $detail] + ($run !== null ? ['run_id' => $run] : []), true);
    return $status === 'refused' ? 'refused' : 'failed';
}

/** What a finished answer of the kernel does: the reply posted (the placeholder becomes it), or a failure the run itself reported. */
function dispatch_finish(PDO $pdo, array $d, array $body, ?int $runId, ?string $requestId, DateTimeImmutable $now): string
{
    $status = (string) ($body['status'] ?? 'succeeded');
    $reply = trim((string) ($body['reply'] ?? ''));
    if (in_array($status, DISPATCH_FAILED_RUN, true) && $reply === '') {
        $why = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : (string) ($body['error'] ?? '');
        return dispatch_fail($pdo, $d, 'failed', $why !== '' ? $why : 'The run ended: ' . $status . '.', $now, $runId);
    }
    if ($reply === '' && !empty($body['error'])) {
        $why = is_array($body['error']) ? (string) ($body['error']['message'] ?? '') : (string) $body['error'];
        return dispatch_fail($pdo, $d, 'failed', $why !== '' ? $why : 'The run ended without a reply.', $now, $runId);
    }
    $text = $reply === '' ? ((string) $d['agent_name']) . ' finished without a reply.' : mb_substr($reply, 0, 8000);
    $runs = reply_to_runs($pdo, $text, (int) $d['agent_member_id']);
    $pdo->beginTransaction();
    try {
        dispatch_record($pdo, (int) $d['dispatch_id'], 'answered', $runId, $requestId, $runs, null, $now);
        dispatch_log($pdo, 'agent.reply', $d, ['run_id' => $runId, 'request_id' => $requestId, 'reply_length' => mb_strlen($reply), 'cost' => $body['cost'] ?? null, 'currency' => $body['currency'] ?? null], true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return 'answered';
}

/** The kernel's sentence of a refusal body, or ''. */
function dispatch_kernel_words(?array $body): string
{
    if (!is_array($body)) { return ''; }
    return is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : (string) ($body['error'] ?? '');
}

/**
 * One dispatch: the kernel's chat endpoint as the agent, the asker acting. Returns ['outcome' => answered|running|awaiting|refused|failed, 'http' => ?int].
 * $d is a row of bd_dispatches_due().
 */
function dispatch_agent(PDO $pdo, array $d, DateTimeImmutable $now): array
{
    dispatch_log($pdo, 'agent.dispatch', $d);
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . (int) $d['agent_member_id'], [
        'utterance' => mb_substr((string) $d['utterance'], 0, 4000), 'conversation_id' => (string) $d['conversation_id'], 'context' => dispatch_context($d), 'wait' => DISPATCH_WAIT_SECONDS,
    ], ['X-Acting-Member: ' . (int) $d['acting_member_id']], DISPATCH_WAIT_SECONDS + 15);
    $http = $answer['status'] ?? null;
    if ($answer === null || $http === 401 || $http >= 500) {
        return ['outcome' => dispatch_fail($pdo, $d, 'failed', $answer === null ? 'The kernel did not answer.' : 'The kernel answered ' . $http . '.', $now), 'http' => $http];
    }
    $body = $answer['body'] ?? [];
    if ($http === 409) {                                           // the agent is busy with another turn: not a refusal — the backoff retries it
        $why = dispatch_kernel_words($body);
        return ['outcome' => dispatch_fail($pdo, $d, 'failed', $why !== '' ? $why : 'The agent is busy with another turn.', $now), 'http' => $http];
    }
    if ($http >= 400) {
        $why = dispatch_kernel_words($body);
        return ['outcome' => dispatch_fail($pdo, $d, 'refused', $why !== '' ? $why : 'The kernel refused this turn (' . $http . ').', $now), 'http' => $http];
    }
    $runId = isset($body['run_id']) ? (int) $body['run_id'] : null;
    $requestId = isset($body['request_id']) ? (string) $body['request_id'] : ($answer['request_id'] ?? null);
    if (($body['status'] ?? '') === 'pending_approval' || (empty($body['finished']) && !empty($body['approval_request_id']))) {
        $detail = 'Waiting for a person\'s approval (request ' . (int) ($body['approval_request_id'] ?? 0) . ')';
        dispatch_record($pdo, (int) $d['dispatch_id'], 'awaiting_approval', $runId, $requestId, null, $detail, $now);   // keeps the run and the placeholder; polled until the person decides
        dispatch_log($pdo, 'agent.dispatch', $d, ['status' => 'awaiting_approval', 'approval_request_id' => (int) ($body['approval_request_id'] ?? 0)] + ($runId !== null ? ['run_id' => $runId] : []));
        return ['outcome' => 'awaiting', 'http' => $http];
    }
    if (empty($body['finished'])) {
        if ($runId === null) {
            return ['outcome' => dispatch_fail($pdo, $d, 'failed', 'The kernel started no run.', $now), 'http' => $http];
        }
        dispatch_record($pdo, (int) $d['dispatch_id'], 'running', $runId, $requestId, null, null, $now);
        return ['outcome' => 'running', 'http' => $http];
    }
    return ['outcome' => dispatch_finish($pdo, $d, $body, $runId, $requestId, $now), 'http' => $http];
}

/** The runs the kernel is still going on: GET ?run= for each (the asker acting). Returns ['polled', 'answered', 'failed', 'running']. */
function poll_running(PDO $pdo, DateTimeImmutable $now): array
{
    $out = ['polled' => 0, 'answered' => 0, 'failed' => 0, 'running' => 0];
    $st = $pdo->prepare('SELECT * FROM bd_dispatches_running(50, CAST(:n AS timestamptz))');
    $st->execute(['n' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($st->fetchAll() as $r) {
        $out['polled']++;
        try {
            $age = $now->getTimestamp() - (new DateTimeImmutable((string) $r['started_at']))->getTimestamp();
            $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $r['run_id'], null, ['X-Acting-Member: ' . (int) $r['acting_member_id']], 20);
            $http = $answer['status'] ?? null;
            $body = $answer['body'] ?? [];
            if ($answer !== null && $http >= 200 && $http < 300 && !empty($body['finished'])) {
                $res = dispatch_finish($pdo, $r, $body, (int) $r['run_id'], $r['request_id'] ?? null, $now);
                $out[$res === 'answered' ? 'answered' : 'failed']++;
            } elseif ($answer !== null && $http >= 400 && $http < 500) {
                dispatch_fail($pdo, $r, 'failed', 'The kernel does not know that run (' . $http . ').', $now, (int) $r['run_id']);
                $out['failed']++;
            } elseif ($age > DISPATCH_RUN_LIMIT_SECONDS) {
                dispatch_fail($pdo, $r, 'failed', 'The run did not finish.', $now, (int) $r['run_id']);
                $out['failed']++;
            } else {
                dispatch_record($pdo, (int) $r['dispatch_id'], 'running', (int) $r['run_id'], null, null, null, $now);   // still going (or the kernel did not answer): ask again in 20 s
                $out['running']++;
            }
        } catch (Throwable $e) {
            error_log('poll of dispatch ' . $r['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $out['running']++;
        }
    }
    return $out;
}

/**
 * The dispatches waiting for a person's approval: GET ?run= for each. The run answered → the reply is posted; the kernel says it was declined, refused or is
 * gone (a 4xx) → `refused` with its sentence; still going or still awaiting (or the kernel did not answer) → awaiting again, which moves the poll on a minute.
 * Returns ['polled', 'answered', 'refused', 'awaiting'].
 */
function poll_waiting(PDO $pdo, DateTimeImmutable $now): array
{
    $out = ['polled' => 0, 'answered' => 0, 'refused' => 0, 'awaiting' => 0];
    $st = $pdo->prepare('SELECT * FROM bd_dispatches_waiting(50, CAST(:n AS timestamptz))');
    $st->execute(['n' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($st->fetchAll() as $r) {
        $out['polled']++;
        try {
            $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $r['run_id'], null, ['X-Acting-Member: ' . (int) $r['acting_member_id']], 20);
            $http = $answer['status'] ?? null;
            $body = $answer['body'] ?? [];
            $state = (string) ($body['status'] ?? '');
            $why = dispatch_kernel_words($body);
            if ($answer !== null && $http >= 400 && $http < 500) {
                dispatch_fail($pdo, $r, 'refused', $why !== '' ? $why : 'The kernel does not know that run (' . $http . ').', $now, (int) $r['run_id']);
                $out['refused']++;
            } elseif ($answer !== null && $http < 300 && in_array($state, DISPATCH_DECLINED, true)) {
                dispatch_fail($pdo, $r, 'refused', $why !== '' ? $why : 'The approval was declined.', $now, (int) $r['run_id']);
                $out['refused']++;
            } elseif ($answer !== null && $http < 300 && !empty($body['finished'])) {
                $res = dispatch_finish($pdo, $r, $body, (int) $r['run_id'], $r['request_id'] ?? null, $now);
                $out[$res === 'answered' ? 'answered' : 'refused']++;
            } else {
                dispatch_record($pdo, (int) $r['dispatch_id'], 'awaiting_approval', null, null, null, null, $now);
                $out['awaiting']++;
            }
        } catch (Throwable $e) {
            error_log('poll of waiting dispatch ' . $r['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }
    return $out;
}

/**
 * One pass of the worker's step: the runs going are polled, then those waiting for approval, then what is due (at most 10) is called. Idempotent — a second
 * pass dispatches nothing new. ['called', 'answered', 'running', 'awaiting', 'refused', 'failed', 'polled'].
 */
function dispatches_pass(PDO $pdo, DateTimeImmutable $now, int $limit): array
{
    $out = ['called' => 0, 'answered' => 0, 'running' => 0, 'awaiting' => 0, 'refused' => 0, 'failed' => 0, 'polled' => 0];
    $polled = poll_running($pdo, $now);
    $out['polled'] += $polled['polled'];
    $out['answered'] += $polled['answered'];
    $out['failed'] += $polled['failed'];
    $out['running'] += $polled['running'];
    $waited = poll_waiting($pdo, $now);
    $out['polled'] += $waited['polled'];
    $out['answered'] += $waited['answered'];
    $out['refused'] += $waited['refused'];
    $out['awaiting'] += $waited['awaiting'];
    $due = $pdo->prepare('SELECT * FROM bd_dispatches_due(:l, CAST(:n AS timestamptz))');
    $due->execute(['l' => max(1, min(10, $limit)), 'n' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($due->fetchAll() as $d) {
        $out['called']++;
        try {
            $r = dispatch_agent($pdo, $d, $now);
        } catch (Throwable $e) {
            error_log('dispatch ' . $d['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $r = ['outcome' => 'failed'];
            try { dispatch_fail($pdo, $d, 'failed', 'The turn could not be recorded.', $now); } catch (Throwable) { }
        }
        $out[$r['outcome']] = ($out[$r['outcome']] ?? 0) + 1;
    }
    return $out;
}
