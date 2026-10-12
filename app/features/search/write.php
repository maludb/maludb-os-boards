<?php
declare(strict_types=1);

/**
 * The search index's catch-up (the worker's `search_catch_up` pass; agents-search.md): db/010's triggers keep search_index current as rows change, so this only
 * repairs what a trigger missed — a row restored from a dump, an import, a fixture. It adds the live boards (not a shipped template), the items with text or a
 * title and the comments that have no index row, and removes index rows whose entity is gone, deleted or (a board) shipped. At most $limit of each.
 * Returns ['indexed' => n, 'removed' => n]. Nothing here changes a board, an item or a comment.
 */
function search_catch_up(PDO $pdo, int $limit): array
{
    $limit = max(1, $limit);
    $indexed = 0;
    $put = $pdo->prepare('SELECT bd_search_put(:k, CAST(:u AS uuid), CAST(:b AS uuid), :a, :t, :body, :it, CAST(:at AS timestamptz), true)');
    $rows = $pdo->query("SELECT 'board' AS kind, b.id AS uuid, b.id AS board, b.owner_member_id AS author, b.title, b.description AS body, NULL::text AS item_type, b.last_activity_at AS at
                           FROM boards b WHERE b.deleted_at IS NULL AND NOT (b.is_template AND b.template_scope = 'shipped')
                            AND NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'board' AND s.entity_uuid = b.id)
                            AND (COALESCE(b.title, '') <> '' OR COALESCE(b.description, '') <> '') ORDER BY b.created_at LIMIT $limit")->fetchAll();
    $rows = array_merge($rows, $pdo->query("SELECT 'item' AS kind, i.id AS uuid, i.board_id AS board, COALESCE(i.updated_by, i.created_by) AS author,
                               CASE WHEN i.type IN ('frame', 'image', 'document') THEN i.data->>'title' END AS title, NULLIF(i.plain_text, '') AS body, i.type AS item_type, i.updated_at AS at
                           FROM board_items i JOIN boards b ON b.id = i.board_id AND b.deleted_at IS NULL AND NOT (b.is_template AND b.template_scope = 'shipped')
                          WHERE i.deleted_at IS NULL AND (i.plain_text <> '' OR jsonb_exists(i.data, 'title'))
                            AND NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'item' AND s.entity_uuid = i.id) ORDER BY i.created_at LIMIT $limit")->fetchAll());
    $rows = array_merge($rows, $pdo->query("SELECT 'comment' AS kind, c.id AS uuid, c.board_id AS board, c.author_member_id AS author, NULL::text AS title, c.plain_text AS body, NULL::text AS item_type, c.created_at AS at
                           FROM comments c JOIN boards b ON b.id = c.board_id AND b.deleted_at IS NULL AND NOT (b.is_template AND b.template_scope = 'shipped')
                          WHERE c.deleted_at IS NULL AND c.plain_text <> ''
                            AND NOT EXISTS (SELECT 1 FROM search_index s WHERE s.entity_kind = 'comment' AND s.entity_uuid = c.id) ORDER BY c.created_at LIMIT $limit")->fetchAll());
    foreach ($rows as $r) {
        $put->execute(['k' => $r['kind'], 'u' => $r['uuid'], 'b' => $r['board'], 'a' => $r['author'], 't' => $r['title'], 'body' => $r['body'], 'it' => $r['item_type'], 'at' => $r['at']]);
        $indexed++;
    }
    $gone = $pdo->prepare("DELETE FROM search_index WHERE ctid IN (
            SELECT s.ctid FROM search_index s
             WHERE (s.entity_kind = 'board'   AND NOT EXISTS (SELECT 1 FROM boards b WHERE b.id = s.entity_uuid AND b.deleted_at IS NULL AND NOT (b.is_template AND b.template_scope = 'shipped')))
                OR (s.entity_kind = 'item'    AND NOT EXISTS (SELECT 1 FROM board_items i WHERE i.id = s.entity_uuid AND i.deleted_at IS NULL))
                OR (s.entity_kind = 'comment' AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.id = s.entity_uuid AND c.deleted_at IS NULL))
             LIMIT $limit)");
    $gone->execute();
    return ['indexed' => $indexed, 'removed' => $gone->rowCount()];
}
