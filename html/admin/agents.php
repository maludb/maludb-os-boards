<?php
declare(strict_types=1);
/**
 * /admin/agents — screen `admin-agents` (agents-search.md): one card per agent admitted to Boards — its roles, how many boards it can open and the ones shared with it by name,
 * this week's batches and comments, its dispatches answered / failed / waiting. Hiring, grants, models and duties are the kernel's (a link to the OS). Right agents.manage.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_login();
require_right('agents.manage');
$pdo = db();
$cards = [];
foreach (agents_here($pdo) as $a) {
    $cards[] = ['agent' => $a, 'reach' => agent_reach($pdo, (int) $a['member_id']), 'week' => agent_week($pdo, (int) $a['member_id'])];
}
log_screen_view($pdo, 'admin-agents');
if (wants_json()) {
    respond_screen(['agents' => array_map(static fn (array $c): array => present_agent_card($c['agent'], $c['reach'], $c['week']), $cards)]);
}
$os = os_my_applications();
$osUrl = is_array($os) && !empty($os['os_url']) ? rtrim((string) $os['os_url'], '/') . '/agents' : os_switcher_launcher_url();
render_screen('Agents', view('admin/agents.php', ['cards' => $cards, 'os_url' => $osUrl]), ['activeNav' => 'admin', 'screen' => 'admin-agents']);
