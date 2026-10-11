<?php
declare(strict_types=1);

/**
 * Comments — the reads (slice 6, comments-notifications.md "Query functions"): threads for the page and the rail, one thread, the pins. Every read
 * goes through mcp_comments (the boards the caller may open; a deleted comment is not a row) — nothing here re-implements the wall.
 */
require_once dirname(__DIR__) . '/boards/queries.php';        // board_uuid(), ago_words()
require_once __DIR__ . '/richtext.php';

const THREAD_PAGE = 30;
const PIN_LIMIT = 500;

/** Where a thread is pinned, in words: "on 'Shipped late'" (the item's first 60 characters), "on a sticky note", "at a point". */
function comment_where_words(array $r): string
{
    if (($r['item_id'] ?? null) === null) {
        return 'at a point';
    }
    if (($r['item_gone'] ?? false) || ($r['item_type'] ?? null) === null) {
        return 'on an item that is gone';
    }
    $t = trim((string) preg_replace('/\s+/', ' ', (string) ($r['item_text'] ?? '')));
    if ($t !== '') {
        return "on '" . mb_substr($t, 0, 60) . "'";
    }
    $kind = ['sticky_note' => 'a sticky note', 'shape' => 'a shape', 'text' => 'a text', 'frame' => 'a frame', 'connector' => 'a connector',
             'drawing' => 'a drawing', 'image' => 'an image', 'document' => 'a file', 'embed' => 'an embed'][(string) $r['item_type']] ?? 'an item';
    return 'on ' . $kind;
}

/**
 * A board's threads (the roots), open first and then resolved, newest first: $f = ['open' => 1|0|null (null both, 1 open only, 0 resolved only),
 * 'mine' => bool (threads I wrote in or am mentioned in)]. ['rows' => …, 'total' => n].
 */
function find_threads(PDO $pdo, string $board, array $f, int $page): array
{
    $where = ['r.board_id = CAST(:b AS uuid)', 'r.parent_comment_id IS NULL'];
    $args = ['b' => $board];
    if (($f['open'] ?? null) === 1) {
        $where[] = 'r.resolved_at IS NULL';
    } elseif (($f['open'] ?? null) === 0) {
        $where[] = 'r.resolved_at IS NOT NULL';
    }
    if (!empty($f['mine'])) {
        $where[] = '(EXISTS (SELECT 1 FROM mcp_comments c WHERE c.thread_id = r.comment_id AND c.author_member_id = app_current_member_id())
                     OR EXISTS (SELECT 1 FROM comment_mentions m JOIN comments c ON c.id = m.comment_id AND c.deleted_at IS NULL
                                 WHERE (c.id = r.comment_id OR c.parent_comment_id = r.comment_id) AND m.kind = \'member\' AND m.principal_id = app_current_member_id()))';
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_comments r WHERE $w", $args);
    $st = $pdo->prepare("SELECT r.comment_id, r.board_id, r.item_id, r.author_member_id, r.author_name, r.author_kind, r.body, r.plain_text, r.created_at, r.edited_at,
                                r.resolved_at, rb.display_name AS resolved_by_name, r.anchor_x, r.anchor_y,
                                (SELECT count(*) FROM mcp_comments c WHERE c.parent_comment_id = r.comment_id) AS replies,
                                i.type AS item_type, i.plain_text AS item_text, (r.item_id IS NOT NULL AND i.item_id IS NULL) AS item_gone
                           FROM mcp_comments r LEFT JOIN members rb ON rb.id = r.resolved_by LEFT JOIN mcp_board_items i ON i.item_id = r.item_id
                          WHERE $w ORDER BY (r.resolved_at IS NOT NULL), r.created_at DESC, r.comment_id
                          LIMIT " . THREAD_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * THREAD_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['body'] = is_array($r['body']) ? $r['body'] : (json_decode((string) $r['body'], true) ?: []);
    }
    return ['rows' => $rows, 'total' => $total];
}

/** One comment's board, thread and author, as the caller sees it (null when they cannot open its board or it is gone). */
function find_comment_ref(PDO $pdo, string $commentId): ?array
{
    if (board_uuid($commentId) === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT comment_id, board_id, thread_id, parent_comment_id, author_member_id, author_kind, resolved_at FROM mcp_comments WHERE comment_id = CAST(:c AS uuid)');
    $st->execute(['c' => strtolower($commentId)]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A thread — any comment id in it names it: ['root' => row, 'replies' => rows], each with body runs, author, when, edited; null when not visible. */
function find_thread(PDO $pdo, string $commentId): ?array
{
    $ref = find_comment_ref($pdo, $commentId);
    if ($ref === null) {
        return null;
    }
    $st = $pdo->prepare("SELECT c.comment_id, c.board_id, c.item_id, c.parent_comment_id, c.thread_id, c.author_member_id, c.author_name, c.author_kind, c.body, c.plain_text,
                                c.anchor_x, c.anchor_y, c.context_item_ids, c.resolved_at, rb.display_name AS resolved_by_name, c.edited_at, c.created_at,
                                i.type AS item_type, i.plain_text AS item_text, (c.item_id IS NOT NULL AND i.item_id IS NULL) AS item_gone
                           FROM mcp_comments c LEFT JOIN members rb ON rb.id = c.resolved_by LEFT JOIN mcp_board_items i ON i.item_id = c.item_id
                          WHERE c.thread_id = CAST(:t AS uuid) ORDER BY (c.parent_comment_id IS NOT NULL), c.created_at, c.comment_id");
    $st->execute(['t' => $ref['thread_id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['body'] = is_array($r['body']) ? $r['body'] : (json_decode((string) $r['body'], true) ?: []);
    }
    unset($r);
    if ($rows === [] || $rows[0]['parent_comment_id'] !== null) {
        return null;
    }
    return ['root' => $rows[0], 'replies' => array_slice($rows, 1)];
}

/**
 * The pins of a board: one per thread. On an item: its top-right corner (the box's x2, y1) plus the stored offset (anchor_x, anchor_y); at a point: the
 * point. Resolved threads only when asked. A thread on an item that is gone has no pin.
 * [{id, item_id, x, y, author_id, author_name, author_kind, replies, resolved}]
 */
function board_pins(PDO $pdo, string $board, bool $resolved): array
{
    $st = $pdo->prepare('SELECT r.comment_id, r.item_id, r.anchor_x, r.anchor_y, r.author_member_id, r.author_name, r.author_kind, (r.resolved_at IS NOT NULL) AS resolved,
                                (SELECT count(*) FROM mcp_comments c WHERE c.parent_comment_id = r.comment_id) AS replies, i.x2 AS item_x2, i.y1 AS item_y1, i.item_id AS item_here
                           FROM mcp_comments r LEFT JOIN mcp_board_items i ON i.item_id = r.item_id
                          WHERE r.board_id = CAST(:b AS uuid) AND r.parent_comment_id IS NULL' . ($resolved ? '' : ' AND r.resolved_at IS NULL') . '
                            AND (r.item_id IS NULL OR i.item_id IS NOT NULL)
                          ORDER BY r.created_at, r.comment_id LIMIT ' . PIN_LIMIT);
    $st->execute(['b' => $board]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $onItem = $r['item_id'] !== null;
        $out[] = ['id' => (string) $r['comment_id'], 'item_id' => $onItem ? (string) $r['item_id'] : null,
                  'x' => round(($onItem ? (float) $r['item_x2'] : 0.0) + (float) ($r['anchor_x'] ?? 0), 2), 'y' => round(($onItem ? (float) $r['item_y1'] : 0.0) + (float) ($r['anchor_y'] ?? 0), 2),
                  'author_id' => $r['author_member_id'] === null ? null : (int) $r['author_member_id'], 'author_name' => (string) ($r['author_name'] ?? 'Someone'),
                  'author_kind' => (string) ($r['author_kind'] ?? 'human'), 'replies' => (int) $r['replies'], 'resolved' => (bool) $r['resolved']];
    }
    return $out;
}

/** Names of members by id (the directory's walled view), for the share prompts: [id => name]. */
function member_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT m.id, m.display_name FROM members m WHERE m.id = ANY (CAST(:ids AS bigint[]))');
    $st->execute(['ids' => '{' . implode(',', $ids) . '}']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['id']] = (string) $r['display_name'];
    }
    return $out;
}
