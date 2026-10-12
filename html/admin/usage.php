<?php
declare(strict_types=1);
/**
 * /admin/usage?sort=&idle_days=&owner_left= — screen `admin-usage` (home-admin.md): every working board by size against the budget — a table at 1280, cards at 375 — with the owner
 * (a "left" badge when they have gone), idle days and open threads; sorted by items (default), idle or open threads; idle_days narrows to boards idle at least that long, owner_left=1
 * to boards whose owner has left, each with Transfer (the share page). Right boards.admin (mcp_boards_usage answers nothing to anyone else), 50 a page.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('boards.admin');
$pdo = db();
$f = usage_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$r = boards_usage($pdo, $f['sort'], $f['idle_days'] ?? null, !empty($f['owner_left']), $page);
$budget = usage_budget($pdo);
log_screen_view($pdo, 'admin-usage');
if (wants_json()) {
    respond_screen(['filters' => (object) $f, 'page' => $page, 'page_size' => USAGE_PAGE, 'total' => $r['total'], 'soft_budget' => $budget[0], 'hard_cap' => $budget[1],
        'rows' => array_map(static fn (array $x): array => present_usage_row($x, $budget), $r['rows'])]);
}
render_screen('Usage', view('admin/usage.php', ['filters' => $f, 'rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'budget' => $budget, 'tz' => member_timezone()]),
    ['activeNav' => 'admin', 'screen' => 'admin-usage']);
