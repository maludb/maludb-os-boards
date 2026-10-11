<?php
declare(strict_types=1);
/** /folders/new (screen `folder-add`; prefill `name`) and /folders/{id}/edit (screen `folder-edit` — its owner or a Boards admin): the name, and Team (boards.create). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_login();
require_right('boards.view');
$pdo = db();
$id = request_integer('id');
$may_team = has_right('boards.create');
if ($id === null) {
    $v = ['name' => mb_substr(request_string('name'), 0, 120), 'is_team' => false];
    log_screen_view($pdo, 'folder-add');
    if (wants_json()) {
        respond_screen(['values' => $v, 'may_team' => $may_team]);
    }
    render_screen('New folder', view('folders/form.php', ['f' => null, 'v' => $v, 'may_team' => $may_team, 'errors' => []]), ['activeNav' => 'folders', 'screen' => 'folder-add', 'entity' => 'folder']);
    exit;
}
$f = find_folder($pdo, $id);
if ($f === null) {
    respond_not_found('There is no such folder.');
}
if (!$f['may_change']) {
    refuse(403, 'Only the folder\'s owner or a Boards admin changes it.');
}
log_screen_view($pdo, 'folder-edit');
if (wants_json()) {
    respond_screen(['folder' => present_folder($f), 'values' => ['name' => $f['name'], 'is_team' => (bool) $f['is_team']], 'may_team' => $may_team]);
}
render_screen('Edit ' . $f['name'], view('folders/form.php', ['f' => $f, 'v' => ['name' => $f['name'], 'is_team' => (bool) $f['is_team']], 'may_team' => $may_team, 'errors' => []]),
    ['activeNav' => 'folders', 'screen' => 'folder-edit', 'entity' => 'folder', 'recordId' => (string) $f['folder_id']]);
