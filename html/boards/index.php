<?php
declare(strict_types=1);
/**
 * /boards — every board I can open, as cards (screen `boards-list`; boards-folders.md): chips All · Mine · Shared with me · Favourites, a
 * folder (the `folder` picker), the search field (title, owner's name), updated within; paged 24, newest activity first. The star toggles
 * inline. JSON: present_board_row() — whitelisted.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/present.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_login();
require_right('boards.view');
$pdo = db();
$filters = boards_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_boards($pdo, $filters, $page);
log_screen_view($pdo, 'boards-list');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => BOARD_PAGE, 'total' => $result['total'], 'rows' => array_map('present_board_row', $result['rows']),
        'may' => ['create' => has_right('boards.create')]]);
}
$folder = isset($filters['folder']) ? find_folder($pdo, (int) $filters['folder']) : null;
$notices = ['deleted' => ['success', 'Moved to the trash. It is purged after 30 days unless you bring it back.']];
$cardsHtml = view('boards/partials/cards.php', ['rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'filters' => $filters, 'base' => '/boards',
    'may_create' => has_right('boards.create'), 'tz' => member_timezone()]);
if (is_htmx_request() && (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'boards-cards')) {
    echo $cardsHtml;
    exit;
}
render_screen('Boards', view('boards/index.php', ['filters' => $filters, 'folder' => $folder, 'cardsHtml' => $cardsHtml, 'may_create' => has_right('boards.create'),
    'notice' => bd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'boards', 'screen' => 'boards-list']);
