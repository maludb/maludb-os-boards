<?php
declare(strict_types=1);
/** /trash — my deleted boards (a Boards admin: everyone's) as cards (screen `trash`): who deleted it, when, when it is purged; Restore. Emptying it is slice 10's. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/boards/queries.php';
require_once dirname(__DIR__) . '/app/features/boards/present.php';
require_login();
require_right('boards.view');
$pdo = db();
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_trash($pdo, $page);
log_screen_view($pdo, 'trash');
if (wants_json()) {
    respond_screen(['page' => $page, 'total' => $result['total'], 'rows' => array_map('present_trash_row', $result['rows'])]);
}
$notices = ['restored' => ['success', 'Brought back, with everyone it was shared with.']];
render_screen('Trash', view('trash/index.php', ['result' => $result, 'page' => $page, 'tz' => member_timezone(), 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'trash', 'screen' => 'trash']);
