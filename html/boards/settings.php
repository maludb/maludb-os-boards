<?php
declare(strict_types=1);
/**
 * /boards/{board}/settings (screen `board-settings`; level view — an editor changes it, an owner locks and deletes): title, description, the
 * folders I keep it in (add and remove), background, the start view; Lock / Unlock and Delete (full); Copies and templates (slice 8). A viewer reads the
 * values. JSON: present_board().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/present.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$b = find_board($pdo, (string) $gate['board_id']);
$may = ['edit' => bd_level_at_least((string) $b['my_level'], 'edit'), 'full' => bd_level_at_least((string) $b['my_level'], 'full'), 'folders' => has_right('boards.view')];
log_screen_view($pdo, 'board-settings', (string) $b['board_id']);
if (wants_json()) {
    respond_screen(['board' => present_board($b), 'may' => $may, 'patterns' => array_keys(BOARD_PATTERNS), 'colors' => array_keys(BOARD_COLORS)]);
}
$notices = ['saved' => ['success', 'Saved.'], 'locked' => ['success', 'Locked: only its owners change it now.'], 'unlocked' => ['success', 'Unlocked.'],
            'filed' => ['success', 'Added to the folder.'], 'unfiled' => ['success', 'Taken out of the folder.']];
render_screen($b['title'] . ' · Settings', view('boards/settings.php', ['b' => $b, 'may' => $may, 'errors' => [], 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'boards', 'screen' => 'board-settings', 'entity' => 'board', 'recordId' => (string) $b['board_id']]);
