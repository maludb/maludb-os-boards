<?php
declare(strict_types=1);

/** The JSON shapes of the agents' pages (slice 9) — whitelists, never a raw row. The words for a status are the spec's vocabulary. */

const DISPATCH_STATUS_WORDS = [
    'sent' => ['info', 'Sending'], 'awaiting_approval' => ['warning', 'Waiting for approval'], 'answered' => ['success', 'Answered'],
    'refused' => ['secondary', 'Refused'], 'failed' => ['danger', 'Failed'],
];

/** [color, words] of a dispatch: a sent one with a run is Working. */
function dispatch_status_chip(array $r): array
{
    if ($r['status'] === 'sent' && ($r['run_id'] ?? null) !== null) {
        return ['info', 'Working'];
    }
    return DISPATCH_STATUS_WORDS[$r['status']] ?? ['secondary', (string) $r['status']];
}

function present_agent_card(array $a, array $reach, array $week): array
{
    return ['agent_id' => (int) $a['member_id'], 'name' => $a['display_name'], 'job_title' => $a['job_title'] ?: null,
            'roles' => array_values(is_array($a['roles_resolved'] ?? null) ? $a['roles_resolved'] : pg_text_array((string) ($a['roles_resolved'] ?? ''))),
            'boards_visible' => $reach['boards'],
            'shared_by_name' => array_map(static fn (array $b): array => ['board_id' => (string) $b['board_id'], 'title' => $b['title'], 'level' => $b['level'], 'url' => '/boards/' . $b['board_id']], $reach['shared']),
            'week' => $week, 'dispatches' => ['answered' => $reach['answered'], 'failed' => $reach['failed'], 'waiting' => $reach['waiting']]];
}

function present_dispatch_row(array $r): array
{
    [, $words] = dispatch_status_chip($r);
    $seeable = $r['board_title'] !== null;
    return ['dispatch_id' => (int) $r['dispatch_id'], 'status' => $r['status'], 'status_words' => $words, 'attempts' => (int) $r['attempts'], 'run_id' => $r['run_id'] !== null ? (int) $r['run_id'] : null,
            'agent' => ['member_id' => (int) $r['agent_member_id'], 'name' => $r['agent_name']],
            'asked_by' => $r['acting_member_id'] !== null ? ['member_id' => (int) $r['acting_member_id'], 'name' => $r['asker_name']] : null,
            'board' => $seeable ? ['board_id' => (string) $r['board_id'], 'title' => $r['board_title']] : null,
            'comment_excerpt' => $seeable ? $r['comment_excerpt'] : null,
            'url' => $seeable ? '/boards/' . $r['board_id'] . '?comment=' . $r['comment_id'] : null,
            'reply_excerpt' => $r['reply_excerpt'], 'detail' => $r['detail'],
            'created_at' => json_ts($r['created_at']), 'answered_at' => json_ts($r['answered_at']),
            'may_retry' => in_array($r['status'], ['failed', 'awaiting_approval'], true)];
}
