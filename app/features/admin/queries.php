<?php
declare(strict_types=1);

/**
 * Admin — the reads (slice 10, home-admin.md "Query functions"): usage against the budget (mcp_boards_usage answers nothing to anyone but a Boards admin), the whole trash
 * (mcp_trash: a Boards admin's is everyone's), the dispatches that need a person (mcp_agent_dispatches), the most active boards (mcp_activity_log ⋈ mcp_boards, so only the
 * boards the caller sees) and the settings row. Shipped templates are not boards anyone works on and a template is left out of usage altogether.
 */
const USAGE_PAGE = 50;
/** The usage page's sorts: the value of ?sort=, its words, and its ORDER BY. */
const USAGE_SORTS = ['items' => ['Items', 'u.item_count DESC'], 'idle' => ['Idle', 'u.idle_days DESC'], 'open_threads' => ['Open threads', 'u.open_threads DESC']];

/** The usage page's filters from the request: sort (items | idle | open_threads), idle_days (a whole number of days), owner_left (1). */
function usage_filters_from_request(): array
{
    $f = ['sort' => 'items'];
    $s = request_string('sort');
    if (isset(USAGE_SORTS[$s])) {
        $f['sort'] = $s;
    }
    $d = request_integer('idle_days');
    if ($d !== null && $d > 0 && $d <= 36500) {
        $f['idle_days'] = $d;
    }
    if (in_array(request_string('owner_left'), ['1', 'yes', 'true', 'on'], true)) {
        $f['owner_left'] = true;
    }
    return $f;
}

/** Counts for the admin's Usage section: boards, items in all, boards over the soft budget, idle 90 days or more, whose owner has left. */
function usage_summary(PDO $pdo): array
{
    $r = one_row($pdo, 'SELECT count(*) AS boards, COALESCE(sum(u.item_count), 0) AS items, count(*) FILTER (WHERE u.over_soft) AS over_soft,
                               count(*) FILTER (WHERE u.idle_days >= 90) AS idle_90, count(*) FILTER (WHERE NOT u.owner_active) AS owner_left
                          FROM mcp_boards_usage u WHERE NOT u.is_template') ?? [];
    return ['boards' => (int) ($r['boards'] ?? 0), 'items' => (int) ($r['items'] ?? 0), 'over_soft' => (int) ($r['over_soft'] ?? 0),
            'idle_90' => (int) ($r['idle_90'] ?? 0), 'owner_left' => (int) ($r['owner_left'] ?? 0)];
}

/** Every working board by size: ['rows' => …, 'total' => n], 50 a page, in the sort asked (the board id breaks ties). */
function boards_usage(PDO $pdo, string $sort, ?int $idleDays, bool $ownerLeft, int $page): array
{
    $where = ['NOT u.is_template'];
    $args = [];
    if ($idleDays !== null) {
        $where[] = 'u.idle_days >= :idle';
        $args['idle'] = $idleDays;
    }
    if ($ownerLeft) {
        $where[] = 'NOT u.owner_active';
    }
    $w = implode(' AND ', $where);
    $order = (USAGE_SORTS[$sort] ?? USAGE_SORTS['items'])[1];
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_boards_usage u WHERE $w", $args);
    $st = $pdo->prepare("SELECT u.board_id, u.title, u.owner_member_id, u.owner_name, u.owner_active, u.item_count, u.over_soft, u.idle_days, u.open_threads, u.last_activity_at
                           FROM mcp_boards_usage u WHERE $w ORDER BY $order, u.board_id LIMIT " . USAGE_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * USAGE_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** The boards with the most activity in the last $days days (item, comment and board.open rows with a board), five by default: batches, distinct members, last at. */
function most_active_boards(PDO $pdo, int $days, int $limit): array
{
    $st = $pdo->prepare("SELECT b.board_id, b.title, count(*) AS batches, count(DISTINCT l.actor_member_id) AS members, max(l.occurred_at) AS last_at
                           FROM mcp_activity_log l JOIN mcp_boards b ON b.board_id = l.board_id
                          WHERE NOT b.is_template AND l.occurred_at > now() - make_interval(days => :d)
                            AND (l.action LIKE 'item.%' OR l.action LIKE 'comment.%' OR l.action = 'board.open')
                          GROUP BY b.board_id, b.title ORDER BY batches DESC, last_at DESC, b.board_id LIMIT " . max(1, min(50, $limit)));
    $st->execute(['d' => max(1, $days)]);
    return $st->fetchAll();
}

/** Every board in the trash I may see (a Boards admin: all), newest first, with the owner and who deleted it. */
function trash_all(PDO $pdo): array
{
    return $pdo->query('SELECT t.board_id, t.title, t.owner_member_id, o.display_name AS owner_name, t.item_count, t.deleted_at, d.display_name AS deleted_by_name, t.purge_after
                          FROM mcp_trash t LEFT JOIN members o ON o.id = t.owner_member_id LEFT JOIN members d ON d.id = t.deleted_by
                         ORDER BY t.deleted_at DESC, t.board_id LIMIT 200')->fetchAll();
}

/** The dispatches that need a person: failed ones and ones waiting for an approval. */
function failed_dispatches(PDO $pdo): array
{
    $r = one_row($pdo, "SELECT count(*) FILTER (WHERE status = 'failed') AS failed, count(*) FILTER (WHERE status = 'awaiting_approval') AS waiting FROM mcp_agent_dispatches") ?? [];
    return ['failed' => (int) ($r['failed'] ?? 0), 'waiting' => (int) ($r['waiting'] ?? 0)];
}

/** The budget the bars are drawn against: [soft, hard]. */
function usage_budget(PDO $pdo): array
{
    $r = one_row($pdo, 'SELECT item_soft_budget, item_hard_cap FROM bd_settings WHERE id = 1') ?? [];
    return [(int) ($r['item_soft_budget'] ?? 4000), (int) ($r['item_hard_cap'] ?? 10000)];
}

/** A board's bar colour: `danger` within 10 % of the hard cap, `warning` past the soft budget, else `success`. */
function budget_status(int $items, int $soft, int $hard): string
{
    return $items >= (int) ceil($hard * 0.9) ? 'danger' : ($items > $soft ? 'warning' : 'success');
}

/** The settings row, as stored. */
function settings_read(PDO $pdo): array
{
    return one_row($pdo, 'SELECT * FROM bd_settings WHERE id = 1') ?? [];
}
