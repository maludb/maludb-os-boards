<?php
declare(strict_types=1);
/** /boards/new (screen `board-add`; prefill `folder`, `title` — boards.create): the full-page form for a new, blank board; Save lands on the board. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_login();
require_right('boards.create');
$pdo = db();
$folderId = request_integer('folder');
$folder = $folderId !== null ? find_folder($pdo, $folderId) : null;
$v = ['title' => mb_substr(request_string('title'), 0, 200), 'description' => '', 'folder' => $folder !== null && $folder['may_change'] ? (int) $folder['folder_id'] : null];
log_screen_view($pdo, 'board-add');
if (wants_json()) {
    respond_screen(['values' => $v]);
}
render_screen('New board', view('boards/form.php', ['v' => $v, 'errors' => []]), ['activeNav' => 'boards', 'screen' => 'board-add', 'entity' => 'board']);
