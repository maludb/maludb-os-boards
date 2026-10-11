<?php
declare(strict_types=1);
/** /folders — my folders and the team folders as cards (screen `folders`): name, "Team", the count of boards I may open in it; New folder. Folders grant nothing. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_login();
require_right('boards.view');
$pdo = db();
$folders = find_folders($pdo, null, false);
log_screen_view($pdo, 'folders');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_folder', $folders)]);
}
$notices = ['deleted' => ['success', 'Deleted the folder. Its boards are where they were.']];
render_screen('Folders', view('folders/index.php', ['folders' => $folders, 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'folders', 'screen' => 'folders']);
