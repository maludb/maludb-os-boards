<?php
declare(strict_types=1);
/**
 * /search?q=&kind=&board=&page= — screen `search` (agents-search.md): one search across the boards, items and comments I can open. The box wants two characters; kind chips (All · Boards ·
 * Items · Comments), a board filter (the record picker); results as cards, best first, paged 20; a board opens its canvas, an item the canvas flown to it, a comment its thread.
 * Templates are left out (the gallery finds them). The words searched are never logged — only their length.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_once dirname(__DIR__) . '/app/features/search/present.php';
require_login();
require_right('boards.view');
$pdo = db();
$q = trim(request_string('q'));
$kind = request_string('kind');
$kind = isset(SEARCH_KINDS[$kind]) ? $kind : null;
$board = strtolower(request_string('board'));
$board = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $board) ? $board : null;
$page = max(1, min(1000, request_integer('page') ?? 1));
$r = search($pdo, $q, $kind, $board, $page);
if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'search', 'after' => ['q_length' => mb_strlen($q), 'kind' => $kind, 'total' => $r['total']]]);
}
if (wants_json()) {
    respond_screen(['filters' => (object) array_filter(['q' => $q !== '' ? $q : null, 'kind' => $kind, 'board' => $board], static fn ($v) => $v !== null), 'page' => $page, 'page_size' => SEARCH_PAGE,
        'total' => $r['total'], 'min_length' => SEARCH_MIN, 'kinds' => array_keys(SEARCH_KINDS), 'rows' => array_map('present_search_row', $r['rows'])]);
}
$filters = array_filter(['q' => $q !== '' ? $q : null, 'kind' => $kind, 'board' => $board], static fn ($v) => $v !== null);
render_screen($q !== '' ? 'Search: ' . mb_substr($q, 0, 60) : 'Search', view('search/index.php', ['q' => $q, 'kind' => $kind, 'board' => $board, 'filters' => $filters, 'rows' => $r['rows'],
    'total' => $r['total'], 'page' => $page, 'tz' => member_timezone()]), ['activeNav' => 'search', 'screen' => 'search']);
