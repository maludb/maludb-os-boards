<?php
declare(strict_types=1);

/**
 * The agents on Boards (slice 9; screens `admin-agents`, `admin-dispatches`): who they are, what they can see and did, every dispatch and Retry. Everything is
 * read through the walled views (db/012) — a board the admin cannot open is a count, never a name — and the dispatches through mcp_agent_dispatches
 * (an `agents.manage` holder sees every one). Hiring, grants, models and duties are the kernel's.
 */
const DISPATCH_PAGE = 50;
const DISPATCH_STATUSES = ['sent', 'awaiting_approval', 'answered', 'refused', 'failed'];

/** The agents admitted to Boards (mcp_members), by name. */
function agents_here(PDO $pdo): array
{
    return $pdo->query("SELECT member_id, display_name, job_title, roles_resolved, status FROM mcp_members WHERE member_kind = 'agent' ORDER BY display_name, member_id")->fetchAll();
}

/**
 * One agent's reach, from the database's own rule: how many live boards it can open (bd_member_board_levels — templates left out), the first ten boards
 * shared with it BY NAME that the caller can open, and its dispatch counts (answered; failed or refused; waiting = sent or awaiting approval).
 */
function agent_reach(PDO $pdo, int $agent): array
{
    $count = (int) one_value($pdo, 'SELECT count(*) FROM bd_member_board_levels(:a) l JOIN boards b ON b.id = l.board_id WHERE b.deleted_at IS NULL AND NOT b.is_template', ['a' => $agent]);
    $st = $pdo->prepare("SELECT b.board_id, b.title, p.level FROM mcp_board_permissions p JOIN mcp_boards b ON b.board_id = p.board_id
                          WHERE p.principal_kind = 'agent' AND p.principal_id = :a AND NOT b.is_template ORDER BY b.title, b.board_id LIMIT 10");
    $st->execute(['a' => $agent]);
    $named = $st->fetchAll();
    $d = one_row($pdo, "SELECT count(*) FILTER (WHERE status = 'answered') AS answered, count(*) FILTER (WHERE status IN ('failed', 'refused')) AS failed,
                               count(*) FILTER (WHERE status IN ('sent', 'awaiting_approval')) AS waiting
                          FROM mcp_agent_dispatches WHERE agent_member_id = :a", ['a' => $agent]) ?? [];
    return ['boards' => $count, 'shared' => $named, 'answered' => (int) ($d['answered'] ?? 0), 'failed' => (int) ($d['failed'] ?? 0), 'waiting' => (int) ($d['waiting'] ?? 0)];
}

/**
 * What agents did, newest first: mcp_activity_log rows whose actor is an agent (a row about a board only while the caller may open it). $agent narrows to one,
 * $board to a board, $since is a timestamp the rows are after. Each row: at, agent id and name, action, board id and title, counts (after.counts), run id.
 */
function agent_activity(PDO $pdo, ?int $agent, ?string $board, ?string $since, int $limit): array
{
    $where = ["a.member_kind = 'agent'", "l.action <> 'screen.view'"];
    $args = [];
    if ($agent !== null) { $where[] = 'l.actor_member_id = :agent'; $args['agent'] = $agent; }
    if ($board !== null) { $where[] = 'l.board_id = CAST(:board AS uuid)'; $args['board'] = $board; }
    if ($since !== null) { $where[] = 'l.occurred_at > CAST(:since AS timestamptz)'; $args['since'] = $since; }
    $st = $pdo->prepare('SELECT l.log_id, l.occurred_at AS at, l.actor_member_id AS agent_id, a.display_name AS agent_name, l.action, l.board_id, b.title AS board_title,
                                l.after->\'counts\' AS counts, l.agent_run_id AS run_id
                           FROM mcp_activity_log l JOIN members a ON a.id = l.actor_member_id LEFT JOIN mcp_boards b ON b.board_id = l.board_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}

/** This week's batches (item changes) and comments (comments and replies written, the dispatch replies) of an agent. */
function agent_week(PDO $pdo, int $agent): array
{
    $r = one_row($pdo, "SELECT count(*) FILTER (WHERE l.action LIKE 'item.%' OR l.action = 'ops.undo') AS batches,
                               count(*) FILTER (WHERE l.action LIKE 'comment.%' OR l.action = 'agent.reply') AS comments
                          FROM mcp_activity_log l WHERE l.actor_member_id = :a AND l.occurred_at > now() - interval '7 days'", ['a' => $agent]) ?? [];
    return ['batches' => (int) ($r['batches'] ?? 0), 'comments' => (int) ($r['comments'] ?? 0)];
}

/** The filters of the dispatches page from the request: status (one of DISPATCH_STATUSES), agent (a member id). */
function dispatch_filters_from_request(): array
{
    $f = [];
    $s = request_string('status');
    if (in_array($s, DISPATCH_STATUSES, true)) { $f['status'] = $s; }
    $a = request_integer('agent');
    if ($a !== null && $a > 0) { $f['agent'] = $a; }
    return $f;
}

/**
 * Every dispatch, newest first, paged 50: ['rows' => …, 'total' => n]. The comment's first 80 characters and the board's title come through the walled views, so a
 * board the caller cannot open shows neither (a null title and no link).
 */
function find_dispatches(PDO $pdo, array $f, int $page): array
{
    $where = ['true'];
    $args = [];
    if (isset($f['status'])) { $where[] = 'd.status = :status'; $args['status'] = $f['status']; }
    if (isset($f['agent'])) { $where[] = 'd.agent_member_id = :agent'; $args['agent'] = (int) $f['agent']; }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_agent_dispatches d WHERE $w", $args);
    $st = $pdo->prepare("SELECT d.dispatch_id, d.status, d.attempts, d.run_id, d.created_at, d.answered_at, d.reply_comment_id, d.reply_excerpt, ag.display_name AS agent_name,
                                d.agent_member_id, ac.display_name AS asker_name, d.acting_member_id, d.board_id, b.title AS board_title, d.comment_id,
                                CASE WHEN b.board_id IS NOT NULL THEN left(c.plain_text, 80) END AS comment_excerpt, x.detail
                           FROM mcp_agent_dispatches d
                           JOIN agent_dispatches x ON x.id = d.dispatch_id
                           LEFT JOIN members ag ON ag.id = d.agent_member_id
                           LEFT JOIN members ac ON ac.id = d.acting_member_id
                           LEFT JOIN mcp_boards b ON b.board_id = d.board_id
                           LEFT JOIN mcp_comments c ON c.comment_id = d.comment_id
                          WHERE $w ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT " . DISPATCH_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * DISPATCH_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/**
 * Retry: a `failed` dispatch goes back to `sent` (no run, due now, the attempts kept) and an `awaiting_approval` one is polled at once. Anything else is a
 * DomainException (422). Returns the status it had.
 */
function retry_dispatch(PDO $pdo, int $id): string
{
    $st = $pdo->prepare('SELECT status FROM agent_dispatches WHERE id = :d FOR UPDATE');
    $st->execute(['d' => $id]);
    $status = $st->fetchColumn();
    if ($status === false) {
        throw new DomainException('Not found.');
    }
    if ($status === 'failed') {
        $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, pending_comment_id = NULL, next_attempt_at = now() WHERE id = :d")->execute(['d' => $id]);
    } elseif ($status === 'awaiting_approval') {
        $pdo->prepare('UPDATE agent_dispatches SET next_attempt_at = now() WHERE id = :d')->execute(['d' => $id]);
    } else {
        throw new DomainException('Only a failed dispatch or one waiting for approval can be retried.');
    }
    return (string) $status;
}
