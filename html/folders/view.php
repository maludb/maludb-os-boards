<?php
declare(strict_types=1);
/** /folders/{id} — one folder's boards as the list's cards (screen `folder-view`): only those I may open; Rename, Delete and Remove from folder for its owner or a Boards admin. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/present.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_login();
require_right('boards.view');
$pdo = db();
$f = ($id = request_integer('id')) === null ? null : find_folder($pdo, $id);
if ($f === null) {
    respond_not_found('There is no such folder.');
}
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = folder_boards($pdo, (int) $f['folder_id'], $page);
log_screen_view($pdo, 'folder-view');
if (wants_json()) {
    respond_screen(['folder' => present_folder($f), 'page' => $page, 'total' => $result['total'], 'rows' => array_map('present_board_row', $result['rows']), 'may_change' => (bool) $f['may_change']]);
}
$notices = ['saved' => ['success', 'Saved.'], 'created' => ['success', 'Made the folder. File a board here from its settings.'], 'removed' => ['success', 'Taken out of the folder — the board is where it was.'],
            'filed' => ['success', 'Added to the folder.']];
$cardsHtml = view('boards/partials/cards.php', ['rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'filters' => [], 'base' => '/folders/' . (int) $f['folder_id'],
    'may_create' => false, 'folder' => $f, 'tz' => member_timezone()]);
render_screen($f['name'], view('folders/view.php', ['f' => $f, 'cardsHtml' => $cardsHtml, 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'folders', 'screen' => 'folder-view', 'entity' => 'folder', 'recordId' => (string) $f['folder_id']]);
