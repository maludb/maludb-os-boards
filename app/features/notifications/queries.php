<?php
declare(strict_types=1);

/**
 * Notifications and preferences — the reads and the own-row writes (slice 6, comments-notifications.md "Query functions"). A notice is told by
 * bd_notify() (db/011), which tells only a person who can see the board and never the writer; here a person reads, opens and marks THEIR OWN
 * (mcp_notifications answers only the caller's). Preferences are one row a person owns.
 */
const NOTICE_PAGE = 30;
/** The kinds a person can choose to be told of by email or text, with their words. (A 'digest' notice is the worker's own.) */
const NOTICE_KINDS = ['mention' => 'Someone mentions me', 'reply' => 'A reply in a thread I am in', 'comment' => 'A comment on a board I own',
                      'share' => 'A board is shared with me', 'agent_replied' => 'An agent replies'];

/** My notices, unread first then newest first, one page of 30, with the board's title (a board I can still open): ['rows' => …, 'total' => n, 'unread' => n]. */
function find_my_notifications(PDO $pdo, int $member, bool $unreadOnly, int $page): array
{
    $where = $unreadOnly ? 'n.read_at IS NULL' : 'TRUE';
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_notifications n WHERE $where");
    $st = $pdo->prepare("SELECT n.notification_id, n.kind, n.title, n.body, n.board_id, n.record_type, n.record_uuid, n.actor_member_id, n.read_at, n.created_at,
                                b.title AS board_title, a.display_name AS actor_name
                           FROM mcp_notifications n LEFT JOIN mcp_boards b ON b.board_id = n.board_id LEFT JOIN members a ON a.id = n.actor_member_id
                          WHERE $where ORDER BY (n.read_at IS NOT NULL), n.created_at DESC, n.notification_id DESC
                          LIMIT " . NOTICE_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * NOTICE_PAGE));
    $st->execute();
    return ['rows' => $st->fetchAll(), 'total' => $total, 'unread' => unread_count($pdo, $member)];
}

/** How many of my notices are unread. */
function unread_count(PDO $pdo, int $member): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** One of my notices, or null (another's never answers). */
function find_my_notification(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT n.notification_id, n.kind, n.title, n.board_id, n.record_type, n.record_uuid, n.read_at FROM mcp_notifications n WHERE n.notification_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mark one (or, with null, all) of MY unread notices read. Answers how many changed. */
function notification_mark_read(PDO $pdo, int $member, ?int $id): int
{
    $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL' . ($id === null ? '' : ' AND id = :id'));
    $st->execute(['m' => $member] + ($id === null ? [] : ['id' => $id]));
    return $st->rowCount();
}

/** Where a notice leads: the board at the comment it is about (a comment's uuid), else the board; null when it names no board. */
function notification_url(array $n): ?string
{
    if (($n['board_id'] ?? null) === null) {
        return null;
    }
    $u = '/boards/' . $n['board_id'];
    return ($n['record_type'] ?? '') === 'comment' && ($n['record_uuid'] ?? null) !== null ? $u . '?comment=' . $n['record_uuid'] : $u;
}

/** My preferences, or the defaults when I have saved none. */
function prefs_of(PDO $pdo, int $member): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, digest FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $member]);
    $r = $st->fetch();
    if ($r === false) {
        return ['email_enabled' => true, 'text_enabled' => false, 'digest' => false, 'kinds' => ['mention', 'reply', 'comment', 'share', 'agent_replied'], 'text_kinds' => ['mention'], 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'digest' => (bool) $r['digest'],
            'kinds' => pg_text_array((string) $r['kinds']), 'text_kinds' => pg_text_array((string) $r['text_kinds']), 'saved' => true];
}

/** Save my preferences (an upsert of my own row): $f holds the keys to change among email_enabled, text_enabled, digest, kinds, text_kinds. */
function prefs_save(PDO $pdo, int $member, array $f): void
{
    $cur = prefs_of($pdo, $member);
    $new = array_merge($cur, $f);
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, digest, updated_at)
                   VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :d, now())
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                        text_kinds = EXCLUDED.text_kinds, digest = EXCLUDED.digest, updated_at = now()')
        ->execute(['m' => $member, 'e' => $new['email_enabled'] ? 't' : 'f', 't' => $new['text_enabled'] ? 't' : 'f', 'd' => $new['digest'] ? 't' : 'f',
                   'k' => '{' . implode(',', $new['kinds']) . '}', 'tk' => '{' . implode(',', $new['text_kinds']) . '}']);
}
