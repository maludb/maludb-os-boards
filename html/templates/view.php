<?php
declare(strict_types=1);
/**
 * /templates/{board} — screen `template-view` (templates-export.md): a template's header, its outline (outline_html over bd_board_outline — never a picture), Use (title and folder), Open on the canvas, Delete for whoever may
 * (not a shipped one). Level view; a board that is not a template is not found here. Logs screen.view with the board. JSON: present_template().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/present.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/queries.php';
require_login();
require_right('boards.view');
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$t = find_template($pdo, (string) $gate['board_id']);
if ($t === null) {
    refuse(404, 'There is no such template.');
}
$id = (string) $t['board_id'];
$markdown = board_outline_markdown($pdo, $id) ?? '';
log_screen_view($pdo, 'template-view', $id);
$mayUse = has_right('boards.create');
if (wants_json()) {
    respond_screen(present_template($t, $markdown) + ['may' => ['use' => $mayUse, 'delete' => $t['template_scope'] !== 'shipped' && bd_level_at_least((string) $t['my_level'], 'full')]]);
}
$notices = ['saved' => ['success', 'Saved as a template of yours.'], 'published' => ['success', 'Published: every Member sees it now.']];
render_screen((string) $t['title'] . ' · Template', view('templates/view.php', ['t' => $t, 'outline' => outline_html($markdown), 'level' => (string) $t['my_level'], 'may_use' => $mayUse,
    'notice' => bd_notice($_GET['notice'] ?? null, $notices), 'tz' => member_timezone()]), ['activeNav' => 'templates', 'screen' => 'template-view', 'entity' => 'board', 'recordId' => $id]);
