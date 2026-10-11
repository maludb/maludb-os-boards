<?php
/**
 * Proof 7 (boards-folders.md): folders — mine and a team folder; a folder shows only the boards its viewer can open (a private board in
 * Marco's team folder is invisible to Bea); add, remove, rename, delete (the boards remain); another member's folder change 403, a folder one
 * cannot see 404; a Viewer's folders their own; an external sees no team folder.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
[$jm, ] = sign_on(27);
[$jb, ] = sign_on(28);
[$jd, ] = sign_on(30);
[$ja, ] = sign_on(29);
echo "7. Folders\n";
$before = last_activity_id();
$r = act($jp, '/folders/save.php', ['name' => 'SMOKE Priya\'s folder']);
$mine = (int) (body_json($r)['record_id'] ?? 0);
ok($r['code'] === 200 && $mine > 0 && str_ends_with((string) body_json($r)['location'], '/folders/' . $mine . '?notice=created'), 'Priya makes a folder (record_id the folder, landing on it)');
ok(count(activity('folder.create', $before)) === 1, 'folder.create is logged');
$r = act($jm, '/folders/save.php', ['name' => 'SMOKE Team planning', 'team' => 'yes']);
$team = (int) (body_json($r)['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT is_team FROM board_folders WHERE id = :f', ['f' => $team]) === true, 'Marco makes a team folder');
$r = act($jd, '/folders/save.php', ['name' => 'SMOKE Dana team', 'team' => 'yes']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['team']), 'a Viewer\'s team folder is refused on the field — her folders are her own');
$r = act($jd, '/folders/save.php', ['name' => 'SMOKE Dana\'s']);
ok($r['code'] === 200, '…a folder of her own she may make');
$r = act($jp, '/folders/save.php', ['name' => str_repeat('n', 121)]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['name']), 'a name over 120 characters is refused');
$f = page($jb, '/folders');
ok(str_contains($f['body'], 'id="folder-card-' . $team . '"') && !str_contains($f['body'], 'id="folder-card-' . $mine . '"') && str_contains($f['body'], '>Team</span>'), 'Bea sees Marco\'s team folder, not Priya\'s');
ok(!str_contains(page($ja, '/folders')['body'], 'folder-card-' . $team), 'Ann, an external, sees no team folder');

$private = make_board($jm, 'SMOKE Marco private');
$open = make_board($jm, 'SMOKE Marco open');
act($jm, '/boards/access-set.php', ['board' => $open, 'level' => 'view']);
$before = last_activity_id();
ok(act($jm, '/folders/add.php', ['folder' => $team, 'board' => $private])['code'] === 200 && act($jm, '/folders/add.php', ['folder' => $team, 'board' => $open])['code'] === 200, 'Marco files both boards in the team folder');
$add = activity('folder.add', $before);
ok(count($add) === 2 && $add[0]['board_id'] === $private && (int) $add[0]['entity_id'] === $team, 'folder.add logs the folder and the board');
$v = page($jb, '/folders/' . $team);
ok($v['code'] === 200 && str_contains($v['body'], 'board-card-' . $open) && !str_contains($v['body'], 'board-card-' . $private) && str_contains($v['body'], 'id="boards-total">1 board'), 'Bea opens the team folder: the open board only — the private one is not there for her');
ok((int) one('SELECT board_count FROM mcp_folders WHERE folder_id = :f', ['f' => $team]) >= 0 && str_contains(page($jb, '/folders')['body'], '1 board · SMOKE Marco'), '…and its card counts 1 for her');
ok(str_contains(page($jm, '/folders/' . $team)['body'], 'id="boards-total">2 boards'), 'Marco sees both');
ok(!str_contains($v['body'], 'id="folder-rename-btn"') && !str_contains($v['body'], 'unfile'), 'Bea may not change Marco\'s folder: no Rename, Delete or Remove');
$r = act($jb, '/folders/save.php', ['folder' => $team, 'name' => 'SMOKE Bea renamed']);
ok($r['code'] === 403 && msg($r) === 'Only the folder\'s owner or a Boards admin changes it.', 'Bea renaming it: 403 in words');
$r = act($jb, '/folders/remove.php', ['folder' => $team, 'board' => $open]);
ok($r['code'] === 403, 'Bea taking a board out of it: 403');
$r = act($jb, '/folders/add.php', ['folder' => $team, 'board' => $open]);
ok($r['code'] === 404 && msg($r) === 'There is no such folder of yours.', 'Bea filing into it: 404 "There is no such folder of yours."');
$r = act($jb, '/folders/save.php', ['folder' => $mine, 'name' => 'x']);
ok($r['code'] === 404, 'Bea on Priya\'s folder (which she cannot see): 404');
ok(page($jb, '/folders/' . $mine)['code'] === 404 && page($jb, '/folders/' . $mine . '/edit')['code'] === 404, '…its page and its form: 404');
$r = act($jp, '/folders/add.php', ['folder' => $mine, 'board' => $private]);
ok($r['code'] === 404 && msg($r) === 'There is no such board.', 'Priya filing a board she cannot open: 404 "There is no such board."');
$r = act($jp, '/folders/add.php', ['folder' => $mine, 'board' => $open]);
ok($r['code'] === 200, 'Priya files Marco\'s open board in her own folder (she may open it)');

$before = last_activity_id();
$r = act($jm, '/folders/remove.php', ['folder' => $team, 'board' => $private]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM board_folder_entries WHERE folder_id = :f', ['f' => $team]) === 1 && count(activity('folder.remove', $before)) === 1, 'Marco takes the private board out (folder.remove logged)');
ok(one('SELECT deleted_at FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $private]) === null, '…the board is where it was');
$r = act($jm, '/folders/remove.php', ['folder' => $team, 'board' => $private]);
ok($r['code'] === 404 && msg($r) === 'That board is not in this folder.', 'taking it out again: 404');
$r = act($jm, '/folders/save.php', ['folder' => $team, 'name' => 'SMOKE Team Q4', 'team' => 'no']);
ok($r['code'] === 200 && one('SELECT name FROM board_folders WHERE id = :f', ['f' => $team]) === 'SMOKE Team Q4' && one('SELECT is_team FROM board_folders WHERE id = :f', ['f' => $team]) === false, 'Marco renames it and makes it his own');
ok(!str_contains(page($jb, '/folders')['body'], 'folder-card-' . $team), '…Bea no longer sees it');
$u = activity('folder.update');
ok(str_contains((string) end($u)['after'], 'SMOKE Team Q4') && str_contains((string) end($u)['before'], '"is_team": true'), 'folder.update logs the change');
$fe = page($jm, '/folders/' . $team . '/edit');
ok($fe['code'] === 200 && str_contains($fe['body'], 'value="SMOKE Team Q4"') && str_contains($fe['body'], 'id="folder-form-field-team"'), 'the edit form holds the name and the Team switch');
$r = act($jm, '/folders/delete.php', ['folder' => $team]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM board_folders WHERE id = :f', ['f' => $team]) === 0 && one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $open]) == 1,
    'Marco deletes it: the folder goes, its boards remain');
ok(count(activity('folder.delete')) === 1, 'folder.delete is logged');
$r = act($jm, '/folders/delete.php', ['folder' => $mine]);
ok($r['code'] === 200, 'a Boards admin may delete anyone\'s folder (Marco deletes Priya\'s)');
finish();
