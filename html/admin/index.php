<?php
declare(strict_types=1);
/**
 * /admin — screen `admin` (home-admin.md): opens on any one of boards.admin, settings.manage, agents.manage, trash.purge, and shows each section to its own right: Usage
 * (boards.admin), The trash with Restore and Purge now (trash.purge or boards.admin), Agents (agents.manage), Most active this month (boards.admin), Settings (settings.manage).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_any_right('boards.admin|settings.manage|agents.manage|trash.purge');
$pdo = db();
$may = ['usage' => has_right('boards.admin'), 'trash' => has_right('trash.purge') || has_right('boards.admin'), 'purge' => has_right('trash.purge'),
        'agents' => has_right('agents.manage'), 'active' => has_right('boards.admin'), 'settings' => has_right('settings.manage')];
$d = [
    'usage'  => $may['usage'] ? usage_summary($pdo) : null,
    'budget' => usage_budget($pdo),
    'trash'  => $may['trash'] ? trash_all($pdo) : [],
    'agents' => $may['agents'] ? failed_dispatches($pdo) : null,
    'active' => $may['active'] ? most_active_boards($pdo, 30, 5) : [],
];
log_screen_view($pdo, 'admin');
if (wants_json()) {
    respond_screen(['may' => $may, 'usage' => $d['usage'] === null ? null : present_usage_summary($d['usage'], $d['budget']), 'trash' => array_map('present_trash_admin_row', $d['trash']),
        'agents' => $d['agents'], 'most_active' => array_map('present_active_board', $d['active'])]);
}
$notices = ['purged' => ['success', 'Purged for good, with everything on it.'], 'restored' => ['success', 'Brought back, with everyone it was shared with.']];
render_screen('Admin', view('admin/index.php', ['may' => $may, 'd' => $d, 'tz' => member_timezone(), 'notice' => bd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin']);
