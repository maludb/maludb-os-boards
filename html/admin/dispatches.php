<?php
declare(strict_types=1);
/**
 * /admin/dispatches?status=&agent=&page= — screen `admin-dispatches` (agents-search.md): every mention of an agent and what became of it, newest first, paged 50; Retry on a failed one
 * or one waiting for approval. Right agents.manage. The board and the comment's words show only for a board the caller can open.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_login();
require_right('agents.manage');
$pdo = db();
$filters = dispatch_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$r = find_dispatches($pdo, $filters, $page);
log_screen_view($pdo, 'admin-dispatches');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => DISPATCH_PAGE, 'total' => $r['total'], 'statuses' => DISPATCH_STATUSES,
        'rows' => array_map('present_dispatch_row', $r['rows'])]);
}
$agentName = isset($filters['agent']) ? one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :a', ['a' => $filters['agent']]) : null;
render_screen('Dispatches', view('admin/dispatches.php', ['filters' => $filters, 'rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'agent_name' => $agentName,
    'tz' => member_timezone(), 'notice' => bd_notice($_GET['notice'] ?? null, ['retried' => ['success', 'Back in the queue: the next pass of the worker sends it.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-dispatches']);
