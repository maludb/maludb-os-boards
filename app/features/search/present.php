<?php
declare(strict_types=1);

/** The JSON shape of a search result (slice 9) — a whitelist; the excerpt is plain text here (the markers gone), the page's is escaped HTML with <mark>. */
function present_search_row(array $r): array
{
    $plain = static fn (string $html): string => html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return ['kind' => $r['kind'], 'id' => $r['id'], 'board_id' => $r['board_id'], 'board_title' => $r['board_title'], 'item_type' => $r['item_type'],
            'title' => $plain($r['title_html']) !== '' ? $plain($r['title_html']) : null, 'excerpt' => $plain($r['excerpt_html']) !== '' ? $plain($r['excerpt_html']) : null,
            'author' => $r['author_name'], 'at' => json_ts($r['at']), 'url' => search_result_url($r)];
}
