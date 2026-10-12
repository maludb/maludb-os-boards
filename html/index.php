<?php
declare(strict_types=1);
/**
 * / — Home (screen `home`; home-admin.md): New board and New from template, then Recent, Favourites, Shared with me and Team folders as cards (at most eight each, See all),
 * the mentions waiting, and a first-run state for someone with no board. A Viewer gets no New buttons. JSON: present_home() — whitelisted.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/boards/queries.php';
require_once dirname(__DIR__) . '/app/features/boards/present.php';
require_once dirname(__DIR__) . '/app/features/folders/queries.php';
require_once dirname(__DIR__) . '/app/features/templates/queries.php';
require_once dirname(__DIR__) . '/app/features/templates/present.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/home/present.php';
require_login();
require_right('boards.view');
$pdo = db();
$s = home_sections($pdo, (int) current_member_id());
$templates = $s['first_run'] ? home_first_run_templates($pdo) : [];
$may = ['create' => has_right('boards.create')];
log_screen_view($pdo, 'home');
if (wants_json()) {
    respond_screen(present_home($s, $templates, $may));
}
render_screen('Home', view('home/index.php', ['s' => $s, 'templates' => $templates, 'may_create' => $may['create'], 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'home']);
