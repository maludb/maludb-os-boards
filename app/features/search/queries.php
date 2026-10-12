<?php
declare(strict_types=1);

/**
 * Search (slice 9; screen `search`, agents-search.md): ONE search across the boards, items and comments the caller can open — mcp_search (db/012) joins the wall, and
 * mcp_boards (NOT is_template) leaves every template out: a template is found in the gallery. The words are websearch_to_tsquery over the simple, unaccented index
 * (db/010); best first by ts_rank_cd; the excerpt is ts_headline with control-character markers that are escaped first and turned into <mark> after.
 */
const SEARCH_PAGE = 20;
const SEARCH_KINDS = ['board' => 'Boards', 'item' => 'Items', 'comment' => 'Comments'];
const SEARCH_MIN = 2;

/** An excerpt as escaped HTML: spaces collapsed (ts_headline drops markup a person typed and leaves its gap), the markers turned into <mark> AFTER escaping, so a person's text is never a mark. */
function search_marks_to_html(string $headline): string
{
    return str_replace(["\x01", "\x02"], ['<mark>', '</mark>'], e(trim((string) preg_replace('/\s+/u', ' ', $headline))));
}

/**
 * ['rows' => …, 'total' => n] for $q (at least SEARCH_MIN characters, else nothing), narrowed by $kind (board|item|comment) and $board (a uuid). Each row: kind, id,
 * board_id, board_title, item_type, author_id, author_name, at, title_html, excerpt_html (both escaped, matches in <mark>), rank.
 */
function search(PDO $pdo, string $q, ?string $kind, ?string $board, int $page): array
{
    $q = trim($q);
    if (mb_strlen($q) < SEARCH_MIN) {
        return ['rows' => [], 'total' => 0];
    }
    $where = ['s.tsv @@ q.tq', 'NOT b.is_template'];
    $args = ['q' => mb_substr($q, 0, 200)];
    if ($kind !== null && isset(SEARCH_KINDS[$kind])) { $where[] = 's.entity_kind = :kind'; $args['kind'] = $kind; }
    if ($board !== null) { $where[] = 's.board_id = CAST(:board AS uuid)'; $args['board'] = $board; }
    $w = implode(' AND ', $where);
    $from = "FROM (SELECT websearch_to_tsquery('simple', unaccent(:q)) AS tq) q, mcp_search s JOIN mcp_boards b ON b.board_id = s.board_id";
    $st = $pdo->prepare("SELECT count(*) $from WHERE $w");
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT s.entity_kind, s.entity_uuid, s.board_id, b.title AS board_title, s.item_type, s.author_member_id, a.display_name AS author_name, s.occurred_at,
            ts_rank_cd(s.tsv, q.tq) AS rank,
            ts_headline('simple', translate(COALESCE(s.title, ''), chr(1) || chr(2), ''), q.tq, :opts) AS hl_title,
            ts_headline('simple', translate(COALESCE(s.body, ''), chr(1) || chr(2), ''), q.tq, :opts) AS hl_body
          $from LEFT JOIN mcp_members a ON a.member_id = s.author_member_id
         WHERE $w ORDER BY rank DESC, s.occurred_at DESC, s.entity_uuid LIMIT " . SEARCH_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * SEARCH_PAGE));
    $st->execute($args + ['opts' => "StartSel=\x01, StopSel=\x02, MaxWords=30, MinWords=12, ShortWord=2"]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $kindOf = (string) $r['entity_kind'];
        $title = $kindOf === 'board' ? (string) $r['board_title'] : (string) $r['hl_title'];
        $titleHtml = $kindOf === 'board' ? search_marks_to_html((string) $r['hl_title'] !== '' ? (string) $r['hl_title'] : (string) $r['board_title']) : ($title !== '' ? search_marks_to_html($title) : '');
        $rows[] = ['kind' => $kindOf, 'id' => (string) $r['entity_uuid'], 'board_id' => (string) $r['board_id'], 'board_title' => (string) $r['board_title'],
                   'item_type' => $r['item_type'], 'author_id' => $r['author_member_id'] !== null ? (int) $r['author_member_id'] : null, 'author_name' => $r['author_name'],
                   'at' => $r['occurred_at'], 'title_html' => $titleHtml, 'excerpt_html' => search_marks_to_html((string) $r['hl_body']), 'rank' => (float) $r['rank']];
    }
    return ['rows' => $rows, 'total' => $total];
}

/** Where a result opens: a board its canvas, an item the canvas flown to it and selected, a comment its thread. */
function search_result_url(array $r): string
{
    return match ($r['kind']) {
        'item' => '/boards/' . $r['board_id'] . '?item=' . $r['id'],
        'comment' => '/boards/' . $r['board_id'] . '?comment=' . $r['id'],
        default => '/boards/' . $r['board_id'],
    };
}
