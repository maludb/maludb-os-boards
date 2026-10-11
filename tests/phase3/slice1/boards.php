<?php
/**
 * Proof 1, 2 and 5 (boards-folders.md): a board made from the form (and refused to a Viewer in the database's words, and on its field when
 * too long); the list per actor — the chips, the folder, the search by title and owner, the star toggling inline; the settings — updated,
 * read-only below edit, a forged POST refused, the lock an owner's, the background and the start view.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
[$jp, ] = sign_on(26);
[$jb, ] = sign_on(28);
[$jd, ] = sign_on(30);
[$jm, ] = sign_on(27);

echo "1. A new board\n";
$before = last_activity_id();
$r = req('POST', '/boards/save.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'title' => 'SMOKE Q4 retro', 'description' => 'What went well, and what did not.']]);
$id = preg_match('#^/boards/([0-9a-f-]{36})$#', $r['location'], $m) ? $m[1] : '';
ok($r['code'] === 302 && $id !== '', 'Priya makes a board from the form and lands on /boards/{board} (' . $r['location'] . ')');
$row = q('SELECT title, description, owner_member_id, created_by FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id])[0] ?? [];
ok(($row['title'] ?? '') === 'SMOKE Q4 retro' && (int) ($row['owner_member_id'] ?? 0) === 26 && ($row['description'] ?? '') === 'What went well, and what did not.', '…owned by her, with its title and description');
ok(level_of($id, 26) === 'full' && level_of($id, 27) === 'full' && level_of($id, 28) === 'none' && (int) one("SELECT count(*) FROM board_permissions WHERE board_id = CAST(:b AS uuid)", ['b' => $id]) === 0,
    'a new board is private: full for its owner (and a Boards admin), nothing for Bea, no permission row');
$log = activity('board.create', $before);
ok(count($log) === 1 && ($log[0]['board_id'] ?? '') === $id && str_contains((string) $log[0]['after'], '"length": 33') && !str_contains((string) $log[0]['after'], 'went well'), 'board.create is logged with the board — the description by its length, never its words');
$r = post_as($jd, '/boards/save.php', ['title' => 'SMOKE Dana\'s']);
ok($r['code'] === 403 && msg($r) === 'You may open boards shared with you but not make new ones.', 'a Viewer\'s POST is refused in the database\'s words (403)');
$r = post_as($jp, '/boards/save.php', ['title' => str_repeat('x', 201)]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['title']) && str_contains(msg($r), '200'), 'a 201-character title is refused on its field (422)');
$r = post_as($jp, '/boards/save.php', ['title' => '   ']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['title']), 'an empty title is refused on its field');
$r = post_as($jp, '/boards/save.php', ['title' => 'x', 'description' => str_repeat('d', 2001)]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['description']), 'a description over 2,000 characters is refused');
$f = page($jp, '/boards/new?title=' . rawurlencode('SMOKE prefilled'));
ok($f['code'] === 200 && str_contains($f['body'], 'value="SMOKE prefilled"') && str_contains($f['body'], 'id="board-form-template-link"') && str_contains($f['body'], 'id="board-form-field-folder-picker"'),
    'the form prefills the title, offers a folder of mine (the picker) and "Start from a template →"');
ok(page($jd, '/boards/new')['code'] === 403, 'a Viewer is refused the form (boards.create)');

echo "2. The list per actor\n";
$id2 = make_board($jp, 'SMOKE Pricing workshop');
$l = page($jp, '/boards');
ok(str_contains($l['body'], 'id="board-card-' . $id . '"') && str_contains($l['body'], 'id="board-card-' . $id2 . '"') && str_contains($l['body'], 'id="boards-total">2 boards'), 'Priya sees her two boards as cards');
ok(str_contains($l['body'], 'id="board-card-' . $id . '-link"') && str_contains($l['body'], 'data-level="full">Owner<') && str_contains($l['body'], '0 items'), '…each a link, with her level (Owner) and its items');
$lb = page($jb, '/boards');
ok(str_contains($lb['body'], 'id="boards-empty"') && !str_contains($lb['body'], 'board-card-'), 'Bea sees nothing until a board is shared with her');
act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 28, 'level' => 'edit']);
$lb = page($jb, '/boards');
ok(str_contains($lb['body'], 'id="board-card-' . $id . '"') && !str_contains($lb['body'], 'board-card-' . $id2) && str_contains($lb['body'], 'data-level="edit">Can edit<'), '…then she sees that one, at edit');
ok(str_contains(page($jb, '/boards?shared=1')['body'], 'board-card-' . $id) && !str_contains(page($jb, '/boards?owner=me')['body'], 'board-card-' . $id), 'Shared with me lists it for Bea; Mine does not');
ok(str_contains(page($jp, '/boards?owner=me')['body'], 'id="boards-total">2 boards') && str_contains(page($jp, '/boards?shared=1')['body'], 'id="boards-total">0 boards'), 'Mine lists Priya\'s two; Shared with me none');
ok(str_contains(page($jp, '/boards?q=pricing')['body'], 'board-card-' . $id2) && !str_contains(page($jp, '/boards?q=pricing')['body'], 'board-card-' . $id . '"'), 'the search finds a board by its title');
ok(str_contains(page($jb, '/boards?q=priya')['body'], 'board-card-' . $id), '…and by its owner\'s name (Bea searching "priya")');
$s = req('POST', '/boards/favorite.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: board-card-' . $id . '-star', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $id, 'return_to' => '/boards']]);
ok($s['code'] === 200 && str_starts_with(trim($s['body']), '<form') && str_contains($s['body'], 'id="board-card-' . $id . '-star"') && str_contains($s['body'], 'aria-pressed="true"') && str_contains($s['body'], '/boards/unfavorite.php'),
    'the star toggles inline: over HTMX the answer is the star itself, now on, posting to unfavorite');
ok(str_contains(page($jp, '/boards?favorites=1')['body'], 'board-card-' . $id) && !str_contains(page($jp, '/boards?favorites=1')['body'], 'board-card-' . $id2), 'Favourites lists only the starred board');
ok(count(activity('board.favorite')) === 1 && (one("SELECT board_id FROM activity_log WHERE action = 'board.favorite'") === $id), 'board.favorite is logged with the board');
$s = req('POST', '/boards/unfavorite.php', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: board-card-' . $id . '-star', 'X-CSRF-Token: ' . page_csrf($jp)], 'form' => ['board' => $id]]);
ok(str_contains($s['body'], 'aria-pressed="false"') && (int) one('SELECT count(*) FROM board_favorites WHERE member_id = 26') === 0, 'and off again');
$s = post_as($jd, '/boards/favorite.php', ['board' => $id2]);
ok($s['code'] === 404, 'starring a board one cannot open: 404');
$fid = (int) (body_json(post_as($jp, '/folders/save.php', ['name' => 'SMOKE Priya\'s']))['record_id'] ?? 0);
act($jp, '/folders/add.php', ['folder' => $fid, 'board' => $id2]);
$lf = page($jp, '/boards?folder=' . $fid);
ok(str_contains($lf['body'], 'board-card-' . $id2) && !str_contains($lf['body'], 'board-card-' . $id . '"') && str_contains($lf['body'], 'value="' . $fid . '"'), 'the folder filter lists that folder\'s boards (the picker keeps the choice)');
$id3 = make_board($jp, 'SMOKE In a folder', ['folder' => $fid]);
ok((int) one('SELECT count(*) FROM board_folder_entries WHERE folder_id = :f AND board_id = CAST(:b AS uuid)', ['f' => $fid, 'b' => $id3]) === 1, 'a board made with a folder lands in it');
pg("UPDATE boards SET last_activity_at = now() - interval '40 days' WHERE id = '$id3'");
ok(!str_contains(page($jp, '/boards?updated=30')['body'], 'board-card-' . $id3) && str_contains(page($jp, '/boards?updated=90')['body'], 'board-card-' . $id3), 'updated within: 30 days leaves out a board idle for 40, 90 keeps it');

echo "5. Settings\n";
$st = page($jp, "/boards/$id/settings");
ok($st['code'] === 200 && str_contains($st['body'], 'id="board-form-field-title"') && str_contains($st['body'], 'id="board-settings-lock"') && str_contains($st['body'], 'id="board-settings-delete"')
    && str_contains($st['body'], 'id="board-duplicate-form"') && str_contains($st['body'], 'id="board-template-form"'), 'the owner\'s settings: the form, Lock, Delete, and slice 8\'s Duplicate and Save as template');
$before = last_activity_id();
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'title' => 'SMOKE Q4 retro (final)', 'background' => 'grid', 'background_color' => '#e7f5ff', 'start_view_x' => '120', 'start_view_y' => '-40', 'start_view_zoom' => '1.5']);
ok($r['code'] === 200 && body_json($r)['record_id'] === $id && body_json($r)['changed'] === ['title', 'background', 'start_view'], 'an update saves the title, the background and the start view (changed named)');
$b = q('SELECT title, background, start_view FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id])[0];
ok($b['title'] === 'SMOKE Q4 retro (final)' && json_decode($b['background'], true) == ['pattern' => 'grid', 'color' => '#e7f5ff'] && json_decode($b['start_view'], true) == ['x' => 120, 'y' => -40, 'zoom' => 1.5], '…as the database holds them');
$u = activity('board.update', $before);
ok(count($u) === 1 && ($u[0]['board_id'] ?? '') === $id && str_contains((string) $u[0]['after'], 'SMOKE Q4 retro (final)'), 'board.update is logged with the board and what changed');
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'description' => str_repeat('a', 300)]);
$u = activity('board.update', $before);
ok(str_contains((string) end($u)['after'], '"length": 300') && !str_contains((string) end($u)['after'], 'aaaa'), 'a description is logged by its length');
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'start_view' => 'none']);
ok(one('SELECT start_view FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id]) === null, 'start_view none clears it (an agent\'s way)');
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'start_view' => '1 2 9']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['start_view']), 'a zoom of 9 is refused on the field');
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'background' => 'stripes']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['background']), 'a background that is not dots, grid or plain is refused');
$r = post_as($jp, '/boards/save.php', ['board' => $id, 'background_color' => '#123456']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['background_color']), 'a colour outside the theme\'s eight is refused');
$r = post_as($jp, '/boards/save.php', ['board' => $id]);
ok($r['code'] === 200 && body_json($r)['did'] === 'Nothing to change', 'an update with nothing in it changes nothing');
act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 30, 'level' => 'view']);
$sd = page($jd, "/boards/$id/settings");
ok($sd['code'] === 200 && str_contains($sd['body'], 'id="board-settings-readonly"') && str_contains($sd['body'], '<fieldset disabled>') && !str_contains($sd['body'], 'id="board-settings-lock"') && !str_contains($sd['body'], 'id="board-settings-delete"'),
    'Dana, shared at view, sees the values read-only — no Save, Lock or Delete');
$r = post_as($jd, '/boards/save.php', ['board' => $id, 'title' => 'forged']);
ok($r['code'] === 403 && msg($r) === 'You can see this board but not change it.', 'her forged POST: 403 in the database\'s words');
$r = post_as($jb, '/boards/save.php', ['board' => $id, 'title' => 'SMOKE Q4 retro (Bea)']);
ok($r['code'] === 200, 'Bea, at edit, changes the title');
$r = post_as($jb, '/boards/lock.php', ['board' => $id, 'locked' => 'yes']);
ok($r['code'] === 403 && msg($r) === 'Only the board\'s owners can do that.', 'an editor locking: 403 "Only the board\'s owners can do that."');
$before = last_activity_id();
$r = post_as($jp, '/boards/lock.php', ['board' => $id, 'locked' => 'yes']);
ok($r['code'] === 200 && one('SELECT is_locked FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id]) === true && count(activity('board.lock', $before)) === 1, 'the owner locks it (board.lock logged)');
ok(str_contains(page($jp, "/boards/$id/settings")['body'], 'id="board-settings-locked-badge"') && str_contains(page($jp, '/boards')['body'], 'id="board-card-' . $id . '-locked"'), '"Locked" on the settings and on its card');
$r = post_as($jp, '/boards/lock.php', ['board' => $id]);
ok($r['code'] === 422, 'a lock without yes or no: 422');
post_as($jp, '/boards/lock.php', ['board' => $id, 'locked' => 'no']);
ok(one('SELECT is_locked FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id]) === false, 'and unlocks it');
finish();
