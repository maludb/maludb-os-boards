<?php
declare(strict_types=1);
/**
 * /boards/{board}/share (screen `board-share`; level view to read, full to change — walls-and-sharing.md "The share page"): the owner and
 * Transfer; everyone in the business (none / view / comment / edit); people, agents and departments with their levels and Remove; Add with
 * the record picker (`member` — people and agents —, `department`); a principal with no Boards grant flagged. JSON: board_access's shape.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sharing/queries.php';
require_login();
$pdo = db();
$b = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$id = (string) $b['board_id'];
$perm = board_permissions($pdo, $id);
$may = bd_level_at_least((string) $b['my_level'], 'full');
log_screen_view($pdo, 'board-share', $id);
if (wants_json()) {
    respond_screen(['board_id' => $id, 'title' => $b['title'], 'my_level' => $b['my_level'], 'owner' => $perm['owner'], 'everyone_level' => $perm['everyone_level'],
        'rows' => array_map('present_permission', $perm['rows']), 'may_change' => $may]);
}
$notices = ['shared' => ['success', 'Shared.'], 'unshared' => ['success', 'Removed.'], 'access' => ['success', 'Saved who in the business can open it.'],
            'transferred' => ['success', 'Handed over. You keep edit.'], 'needs_grant' => ['warning', 'Shared — but they cannot open Boards yet: a super-admin grants it in the OS.']];
render_screen($b['title'] . ' · Share', view('boards/share.php', ['b' => $b, 'perm' => $perm, 'may' => $may, 'business' => (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: 'the business'),
    'notice' => bd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'boards', 'screen' => 'board-share', 'entity' => 'board', 'recordId' => $id]);
