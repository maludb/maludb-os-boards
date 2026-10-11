<?php
declare(strict_types=1);

/**
 * The canvas's reads (canvas.md "Query functions"; canvas-core.md "The op protocol"). Every door that calls these has passed the board's
 * gate first (bd_board_or_404 — the wall is db/011's), so the state and the catch-up read board_items and board_ops directly: ONE statement
 * per answer, never a per-item view (mcp_board_items computes the caller's level per board — fine for a few ids, not for 5,000 items).
 */

/** The mcp_boards row for the canvas + the start view and background decoded + the thumbnail's id and age in seconds (null when none). */
function board_for_canvas(PDO $pdo, string $boardId): ?array
{
    $r = one_row($pdo, 'SELECT b.*, t.id AS thumbnail_id, extract(epoch FROM now() - t.created_at)::int AS thumbnail_age
                          FROM mcp_boards b JOIN boards bb ON bb.id = b.board_id LEFT JOIN attachments t ON t.id = bb.thumbnail_attachment_id
                         WHERE b.board_id = CAST(:b AS uuid)', ['b' => $boardId]);
    if ($r === null) {
        return null;
    }
    $r['background'] = json_decode((string) ($r['background'] ?? '{}'), true) ?: ['pattern' => 'dots', 'color' => '#ffffff'];
    $sv = $r['start_view'] !== null ? json_decode((string) $r['start_view'], true) : null;
    $r['start_view'] = is_array($sv) && isset($sv['x'], $sv['y'], $sv['zoom']) ? ['x' => (float) $sv['x'], 'y' => (float) $sv['y'], 'zoom' => (float) $sv['zoom']] : null;
    $r['thumbnail_id'] = $r['thumbnail_id'] === null ? null : (int) $r['thumbnail_id'];
    $r['thumbnail_age'] = $r['thumbnail_age'] === null ? null : (int) $r['thumbnail_age'];
    return $r;
}

/**
 * The state the island loads first: the board, every live item (bd_item_json, by z) and the last seq FROM ONE STATEMENT (one snapshot), the
 * people its items and permissions name. The items stay JSON TEXT (json_items) — the door writes them through without decoding 5,000 rows.
 */
function board_state(PDO $pdo, string $boardId): array
{
    $r = one_row($pdo, "SELECT b.id, b.title, b.is_locked, b.background, b.start_view, b.item_count, b.last_seq,
                               COALESCE(jsonb_agg(bd_item_json(i) ORDER BY i.z, i.id) FILTER (WHERE i.id IS NOT NULL), '[]')::text AS items,
                               COALESCE(array_agg(DISTINCT i.created_by) FILTER (WHERE i.created_by IS NOT NULL), '{}') ||
                               COALESCE(array_agg(DISTINCT i.updated_by) FILTER (WHERE i.updated_by IS NOT NULL), '{}') AS item_people
                          FROM boards b LEFT JOIN board_items i ON i.board_id = b.id AND i.deleted_at IS NULL
                         WHERE b.id = CAST(:b AS uuid) AND b.deleted_at IS NULL
                         GROUP BY b.id", ['b' => $boardId]);
    if ($r === null) {
        throw new DomainException('Not found.');
    }
    $ids = pg_int_array((string) $r['item_people']);
    $st = $pdo->prepare("SELECT m.id, m.display_name, m.member_kind FROM members m
                          WHERE m.id = ANY (CAST(:ids AS bigint[]))
                             OR m.id IN (SELECT p.principal_id FROM board_permissions p WHERE p.board_id = CAST(:b AS uuid) AND p.principal_kind IN ('member', 'agent'))
                             OR m.id = (SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid))");
    $st->execute(['ids' => pg_array_literal($ids), 'b' => $boardId]);
    $people = [];
    foreach ($st->fetchAll() as $p) {
        $people[(string) $p['id']] = ['name' => (string) $p['display_name'], 'kind' => (string) $p['member_kind']];
    }
    $sv = $r['start_view'] !== null ? json_decode((string) $r['start_view'], true) : null;
    return ['board' => ['id' => (string) $r['id'], 'title' => (string) $r['title'], 'is_locked' => (bool) $r['is_locked'],
                        'background' => json_decode((string) $r['background'], true) ?: (object) [], 'start_view' => is_array($sv) ? $sv : null,
                        'item_count' => (int) $r['item_count']],
            'json_items' => (string) $r['items'], 'last_seq' => (int) $r['last_seq'], 'people' => $people];
}

/**
 * The catch-up: the batches after $after in seq order (≤ $limit; `more` when there are others), `last_seq` the last one returned (else the
 * board's), and `resync` when $after is older than the board's latest compaction (its ops are gone: the client reloads the state —
 * canvas.md decision 7).
 */
function ops_after(PDO $pdo, string $boardId, int $after, int $limit = 500): array
{
    $compacted = (int) (one_value($pdo, "SELECT max(content_rev) FROM board_versions WHERE board_id = CAST(:b AS uuid) AND reason = 'compaction'", ['b' => $boardId]) ?? 0);
    $last = (int) one_value($pdo, 'SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $boardId]);
    if ($after < $compacted) {
        return ['batches' => [], 'last_seq' => $last, 'resync' => true, 'more' => false];
    }
    $st = $pdo->prepare("SELECT seq, member_id, client_id, source, ops::text AS ops, summary::text AS summary, at
                           FROM board_ops WHERE board_id = CAST(:b AS uuid) AND seq > :after ORDER BY seq LIMIT :n");
    $st->bindValue('b', $boardId);
    $st->bindValue('after', $after, PDO::PARAM_INT);
    $st->bindValue('n', $limit + 1, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $batches = array_map(static fn (array $o): array => ['seq' => (int) $o['seq'], 'member_id' => $o['member_id'] === null ? null : (int) $o['member_id'],
        'client_id' => $o['client_id'], 'source' => (string) $o['source'], 'ops' => json_decode((string) $o['ops'], true),
        'summary' => json_decode((string) $o['summary'], true) ?: (object) [], 'at' => json_ts((string) $o['at'])], $rows);
    return ['batches' => $batches, 'last_seq' => $batches === [] ? max($after, $last) : (int) end($batches)['seq'], 'resync' => false, 'more' => $more];
}

/** The Properties panel's rows: the selected items (mcp_board_items — the view's own wall), in the order asked. */
function rail_selection(PDO $pdo, string $boardId, array $itemIds): array
{
    $itemIds = array_values(array_filter($itemIds, static fn ($i): bool => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $i)));
    if ($itemIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_board_items WHERE board_id = CAST(:b AS uuid) AND item_id = ANY (CAST(:ids AS uuid[]))');
    $st->execute(['b' => $boardId, 'ids' => pg_array_literal(array_slice($itemIds, 0, 500))]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['style'] = json_decode((string) $r['style'], true) ?: [];
        $r['data'] = json_decode((string) $r['data'], true) ?: [];
        $rows[(string) $r['item_id']] = $r;
    }
    return array_values(array_filter(array_map(static fn ($id) => $rows[$id] ?? null, $itemIds)));
}

/** The outline as Markdown — bd_board_outline() verbatim (db/013; the "Copy as Markdown" button and the K7 share board_outline). */
function board_outline_markdown(PDO $pdo, string $boardId): ?string
{
    $v = one_value($pdo, 'SELECT bd_board_outline(CAST(:b AS uuid))', ['b' => $boardId]);
    return $v === null ? null : (string) $v;
}

/**
 * The outline as HTML — the page board-outline and the rail's Outline panel: bd_outline_of()'s order and words (frames top to bottom then left
 * to right, each a heading with its items in reading order; the items on the canvas; the connections as "A → B"; the open comments), every
 * item line a link to /boards/{board}?item={id}. The line's words are the database's own (bd_outline_line), so the page and the Markdown agree.
 */
function board_outline_html(PDO $pdo, string $boardId): ?string
{
    $title = one_value($pdo, 'SELECT title FROM boards WHERE id = CAST(:b AS uuid) AND deleted_at IS NULL', ['b' => $boardId]);
    if ($title === null) {
        return null;
    }
    $st = $pdo->prepare("SELECT i.id, i.type, i.parent_id, i.x, i.y, i.data->>'title' AS frame_title, substr(bd_outline_line(bd_item_json(i)), 3) AS line
                           FROM board_items i WHERE i.board_id = CAST(:b AS uuid) AND i.deleted_at IS NULL AND i.type <> 'connector'
                          ORDER BY i.y, i.x");
    $st->execute(['b' => $boardId]);
    $frames = [];
    $canvas = [];
    $children = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['type'] === 'frame') {
            $frames[] = $r;
        } elseif ($r['parent_id'] !== null) {
            $children[(string) $r['parent_id']][] = $r;
        } else {
            $canvas[] = $r;
        }
    }
    $links = $pdo->prepare("SELECT k.id, COALESCE(NULLIF(left(bd_item_words(bd_item_json(s), 60), 60), ''), s.type, 'a free point') AS a,
                                   COALESCE(NULLIF(left(bd_item_words(bd_item_json(t), 60), 60), ''), t.type, 'a free point') AS b,
                                   NULLIF(COALESCE(k.data->'captions'->0->>'content', k.data->>'caption'), '') AS caption
                              FROM board_items k LEFT JOIN board_items s ON s.id = k.start_item_id LEFT JOIN board_items t ON t.id = k.end_item_id
                             WHERE k.board_id = CAST(:b AS uuid) AND k.type = 'connector' AND k.deleted_at IS NULL ORDER BY k.y, k.x");
    $links->execute(['b' => $boardId]);
    $comments = $pdo->prepare("SELECT m.display_name AS author, left(c.plain_text, 200) AS text, left(bd_item_words(bd_item_json(i), 60), 60) AS on_item, c.item_id
                                 FROM comments c LEFT JOIN members m ON m.id = c.author_member_id LEFT JOIN board_items i ON i.id = c.item_id
                                WHERE c.board_id = CAST(:b AS uuid) AND c.parent_comment_id IS NULL AND c.resolved_at IS NULL AND c.deleted_at IS NULL
                                ORDER BY c.created_at");
    $comments->execute(['b' => $boardId]);
    $item = static fn (array $r): string => '<li><a href="' . e('/boards/' . $boardId . '?item=' . $r['id']) . '" class="outline-item" data-item="' . e($r['id']) . '">'
        . e((string) $r['line']) . '</a></li>';
    $h = '';
    foreach ($frames as $f) {
        $h .= '<h2 class="fs-6 fw-semibold mt-3 mb-1"><a href="' . e('/boards/' . $boardId . '?item=' . $f['id']) . '" class="text-dark outline-item" data-item="' . e($f['id']) . '">'
            . e(trim((string) $f['frame_title']) !== '' ? (string) $f['frame_title'] : 'Untitled frame') . '</a></h2>';
        $kids = $children[(string) $f['id']] ?? [];
        $h .= $kids === [] ? '<p class="text-muted fs-12 mb-2">(empty)</p>' : '<ul class="outline-list mb-2">' . implode('', array_map($item, $kids)) . '</ul>';
    }
    if ($canvas !== []) {
        $h .= '<h2 class="fs-6 fw-semibold mt-3 mb-1">On the canvas</h2><ul class="outline-list mb-2">' . implode('', array_map($item, $canvas)) . '</ul>';
    }
    $l = $links->fetchAll();
    if ($l !== []) {
        $h .= '<h2 class="fs-6 fw-semibold mt-3 mb-1">Connections</h2><ul class="outline-list mb-2">' . implode('', array_map(static fn ($k): string =>
            '<li>' . e($k['a']) . ' → ' . e($k['b']) . ($k['caption'] !== null ? ' <span class="text-muted">(' . e($k['caption']) . ')</span>' : '') . '</li>', $l)) . '</ul>';
    }
    $c = $comments->fetchAll();
    if ($c !== []) {
        $h .= '<h2 class="fs-6 fw-semibold mt-3 mb-1">Open comments</h2><ul class="outline-list mb-2">' . implode('', array_map(static fn ($k): string =>
            '<li><strong>' . e($k['author'] ?? 'someone') . '</strong>: ' . e($k['text']) . ($k['on_item'] !== null ? ' — on "' . e($k['on_item']) . '"' : '') . '</li>', $c)) . '</ul>';
    }
    if ($h === '') {
        $h = '<p class="text-muted mb-0" id="outline-empty">This board is empty.</p>';
    }
    return $h;
}
