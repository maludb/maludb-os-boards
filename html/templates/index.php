<?php
declare(strict_types=1);
/**
 * /templates?category=&scope=&page= — screen `templates` (templates-export.md): the gallery. Chips for the category and for whose (shipped, the business's, mine), the templates as cards — the shipped first, then by title —
 * paged 24; Use opens the title-and-folder form in place for a person who may create boards. An external sees none of the shipped or the business's (the wall); a personal template only its maker. JSON: present_template_row().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/present.php';
require_login();
require_right('boards.view');
$pdo = db();
$filters = templates_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$r = find_templates($pdo, $filters, $page);
log_screen_view($pdo, 'templates');
$mayUse = has_right('boards.create');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => BOARD_PAGE, 'total' => $r['total'], 'rows' => array_map('present_template_row', $r['rows']), 'may' => ['use' => $mayUse],
        'categories' => array_keys(TEMPLATE_CATEGORIES), 'scopes' => array_keys(TEMPLATE_SCOPES)]);
}
$notices = ['saved' => ['success', 'Saved as a template of yours.'], 'published' => ['success', 'Published: every Member sees it now.']];
render_screen('Templates', view('templates/index.php', ['filters' => $filters, 'rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'may_use' => $mayUse,
    'notice' => bd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'templates', 'screen' => 'templates']);
