<?php
declare(strict_types=1);
/** /activity — my own trail here, in sentences (screen `activity`); ?since= days (1, 7, 30, 90), ?board= a board's uuid, ?page_no= the page. HTMX swaps the rows. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$since = request_integer('since');
$since = in_array($since, [1, 7, 30, 90], true) ? $since : null;
$board = strtolower(request_string('board'));
$board = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $board) ? $board : null;
$page = max(1, min(1000, request_integer('page_no') ?? 1));
$filters = ['since' => $since, 'board' => $board];
$r = find_my_activity($pdo, $me, $filters, $page);
log_screen_view($pdo, 'activity');
if (wants_json()) {
    respond_screen(['page' => $page, 'total' => $r['total'], 'rows' => array_map('present_activity_row', $r['rows'])]);
}
$boardTitle = $board === null ? null : one_value($pdo, 'SELECT title FROM mcp_boards WHERE board_id = CAST(:b AS uuid)', ['b' => $board]);
$query = array_filter(['since' => $since, 'board' => $board]);
$rowsHtml = view('activity/partials/rows.php', ['rows' => $r['rows'], 'page' => $page, 'total' => $r['total'], 'query' => $query, 'tz' => member_timezone()]);
if (is_htmx_request() && (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'activity-results')) {
    echo $rowsHtml;
    exit;
}
render_screen('My trail', view('activity/page.php', ['filters' => $filters, 'boardTitle' => $boardTitle, 'resultsHtml' => $rowsHtml]), ['activeNav' => 'activity', 'screen' => 'activity']);
