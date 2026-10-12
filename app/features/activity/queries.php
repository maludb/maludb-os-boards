<?php
declare(strict_types=1);

/**
 * My trail (screen `activity`, sso-shell.md): my own rows of the activity log, in sentences — "You created Q4 retro", "You moved 3 items on
 * Q4 retro" — read through mcp_activity_log (db/012: admitted members; a row about a board only while the caller may open it). The board's
 * title comes from mcp_boards, so a board I can no longer open never names itself. Screen views are left out of the sentences (they are
 * the activity MCP's `member_activity`); everything I did is in.
 */
const ACTIVITY_PAGE = 50;

/**
 * My rows newest first, one page of 50: ['rows' => …, 'total' => n]. $filters: since (days: 1, 7, 30, 90), board (a uuid — that board's rows
 * of mine).
 */
function find_my_activity(PDO $pdo, int $memberId, array $filters, int $page): array
{
    $where = ['l.actor_member_id = :me', "l.action <> 'screen.view'"];
    $args = ['me' => $memberId];
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'l.occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    if (($filters['board'] ?? null) !== null) {
        $where[] = 'l.board_id = CAST(:board AS uuid)';
        $args['board'] = (string) $filters['board'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_activity_log l WHERE $w", $args);
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT l.log_id AS activity_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id,
                                l.after, l.board_id, l.agent_run_id, b.title AS board_title
                           FROM mcp_activity_log l LEFT JOIN mcp_boards b ON b.board_id = l.board_id
                          WHERE $w ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT " . ACTIVITY_PAGE . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['parts'] = activity_sentence_parts($r);
        $r['sentence'] = activity_sentence($r);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total];
}

/** The words of a row: ['text' => "You moved 3 items on", 'board' => the board's title or null] — the screen links the title. */
function activity_sentence_parts(array $r): array
{
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $n = isset($after['count']) ? (int) $after['count'] : (is_array($after['counts'] ?? null) ? (int) array_sum($after['counts']) : null);
    $items = $n === null ? 'items' : ($n === 1 ? '1 item' : $n . ' items');
    $label = isset($after['label']) && is_string($after['label']) ? ' “' . mb_substr($after['label'], 0, 60) . '”' : '';
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.session_ended' => 'were signed out', 'member.sign_on.refused' => 'were refused at sign-on',
        'token.mint' => 'made the MCP token' . $label, 'token.revoke' => 'revoked an MCP token', 'assistant.ask' => 'asked the command bar',
        'board.create' => 'created', 'board.update' => 'changed the settings of', 'board.lock' => 'locked or unlocked', 'board.delete' => 'moved to the trash',
        'board.restore' => 'brought back', 'board.share' => 'shared', 'board.unshare' => 'stopped sharing', 'board.access_set' => 'changed who in the business can open',
        'board.transfer' => 'handed over', 'board.favorite' => 'starred', 'board.unfavorite' => 'unstarred', 'board.duplicate' => 'duplicated', 'board.export' => 'exported',
        'board.open' => 'opened', 'folder.create' => 'made a folder', 'folder.update' => 'changed a folder', 'folder.delete' => 'deleted a folder',
        'folder.add' => 'filed', 'folder.remove' => 'took out of a folder',
        'item.batch' => 'changed ' . $items . ' on', 'item.create' => 'added ' . $items . ' to', 'item.update' => 'changed ' . $items . ' on',
        'item.delete' => 'deleted ' . $items . ' from', 'item.move' => 'moved ' . $items . ' on', 'item.connect' => 'connected two items on',
        'item.frame' => 'framed ' . $items . ' on', 'item.restore' => 'brought back ' . $items . ' on', 'ops.undo' => 'undid a change on',
        'file.upload' => 'uploaded a file to', 'comment.create' => 'commented on', 'comment.reply' => 'replied on', 'comment.resolve' => 'resolved a thread on',
        'comment.reopen' => 'reopened a thread on', 'comment.edit' => 'edited a comment on', 'comment.delete' => 'deleted a comment on',
        'version.save' => 'saved a version of', 'version.restore' => 'restored a version of', 'template.use' => 'made a board from', 'template.save' => 'saved as a template',
        'template.publish' => 'published as the business\'s template', 'settings.prefs' => 'changed how they are told', 'notification.read' => 'read a notice',
        'notification.read_all' => 'read every notice', 'settings.save' => 'changed Boards\' settings', 'trash.purge' => 'emptied from the trash',
        'dispatch.retry' => 'retried an agent\'s dispatch', 'agent.dispatch' => 'asked an agent on', 'agent.reply' => 'replied as an agent on', 'agent.fail' => 'could not answer on',
    ];
    $verb = $words[$r['action']] ?? str_replace(['.', '_'], ' ', (string) $r['action']);
    if ($r['action'] === 'member.sign_on' || $r['action'] === 'member.sign_out') {
        return ['text' => 'You ' . $verb, 'board' => null];
    }
    $board = ($r['board_title'] ?? null) !== null ? (string) $r['board_title'] : null;
    if ($board === null && ($r['board_id'] ?? null) !== null) {
        $verb .= ' a board';                                           // a board I can no longer open never names itself
    }
    return ['text' => 'You ' . $verb, 'board' => $board];
}

/** One line for a row, in words: "You moved 3 items on Q4 retro". */
function activity_sentence(array $r): string
{
    $p = activity_sentence_parts($r);
    return $p['board'] === null ? $p['text'] : $p['text'] . ' ' . $p['board'];
}

/** A row's JSON: the sentence and the keys (never a payload). */
function present_activity_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']), 'source' => $r['source'], 'action' => $r['action'],
            'sentence' => $r['sentence'] ?? activity_sentence($r), 'board_id' => $r['board_id'], 'board_title' => $r['board_title'] ?? null,
            'agent_run_id' => $r['agent_run_id'] !== null ? (int) $r['agent_run_id'] : null];
}
