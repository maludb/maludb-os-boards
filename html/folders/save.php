<?php
declare(strict_types=1);
/**
 * Actions `folder_create` (no `folder`; log folder.create — boards.view) and `folder_update` (`folder`; log folder.update — its owner or a
 * Boards admin): **name** (≤ 120), team (yes or no — seen by every Member; making one needs boards.create, decision 4). Folders grant nothing.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/folders/write.php';
bd_handler_begin();
require_right('boards.view');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$id = null;
$cur = null;
if (req_has('folder') && (string) req_val('folder') !== '') {
    $id = ctype_digit((string) req_val('folder')) ? (int) req_val('folder') : 0;
    $cur = find_folder($pdo, $id);
    if ($cur === null) {
        refuse(404, 'There is no such folder.');
    }
    if (!$cur['may_change']) {
        refuse(403, 'Only the folder\'s owner or a Boards admin changes it.');
    }
}
$name = bd_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true);
if ($name === null && !isset($errors['name'])) {
    $errors['name'] = 'The name is required.';
}
$team = bd_yes('team', $cur !== null ? (bool) $cur['is_team'] : false);
if ($team && !($cur !== null && $cur['is_team']) && !has_right('boards.create')) {
    $errors['team'] = 'A team folder is made by a Member — yours stay your own.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$folderId = bd_guard($pdo, static function () use ($pdo, $id, $me, $name, $team, $cur): int {
    $pdo->beginTransaction();
    $folderId = folder_save($pdo, $id, $me, $name, $team);
    if ($cur === null) {
        log_activity($pdo, 'folder.create', 'folder', $folderId, ['after' => ['folder_id' => $folderId, 'name' => $name, 'is_team' => $team]]);
    } else {
        $diff = bd_diff(['name' => $cur['name'], 'is_team' => (bool) $cur['is_team']], ['name' => $name, 'is_team' => $team]);
        log_activity($pdo, 'folder.update', 'folder', $folderId, ['before' => ['folder_id' => $folderId] + $diff['before'], 'after' => ['folder_id' => $folderId, 'name' => $name] + $diff['after']]);
    }
    $pdo->commit();
    return $folderId;
});
bd_done(($cur === null ? 'Made the folder ' : 'Saved the folder ') . $name, $folderId, bd_land('/folders/' . $folderId, $cur === null ? 'created' : 'saved'), 'folderChanged');
