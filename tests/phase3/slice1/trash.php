<?php
/**
 * Proof 6 (boards-folders.md): delete → the trash (gone from every list and page, in /trash with its purge date); restore by the owner with its
 * shares intact; by another Member 404 "There is no such board in your trash."; a Boards admin sees every deleted board; delete is an owner's.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
[$jb, ] = sign_on(28);
[$jm, ] = sign_on(27);
echo "6. The trash\n";
$id = make_board($jp, 'SMOKE To the trash');
act($jp, '/boards/share-set.php', ['board' => $id, 'department' => SALES, 'level' => 'edit']);
act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 28, 'level' => 'edit']);
$r = act($jb, '/boards/delete.php', ['board' => $id]);
ok($r['code'] === 403 && msg($r) === 'Only the board\'s owners can do that.', 'Bea (at edit) deleting: 403');
$before = last_activity_id();
$r = act($jp, '/boards/delete.php', ['board' => $id]);
ok($r['code'] === 200 && body_json($r)['record_id'] === $id && str_ends_with((string) body_json($r)['location'], '/boards?notice=deleted'), 'Priya deletes it (record_id the board, back to Boards with a notice)');
$d = activity('board.delete', $before);
ok(count($d) === 1 && $d[0]['board_id'] === $id && str_contains((string) $d[0]['before'], 'SMOKE To the trash'), 'board.delete is logged with its title');
ok(!str_contains(page($jp, '/boards')['body'], 'board-card-' . $id) && !str_contains(page($jb, '/boards')['body'], 'board-card-' . $id), 'the card is gone from every list');
ok(page($jp, "/boards/$id/settings")['code'] === 404 && page($jb, "/boards/$id/share")['code'] === 404, 'its pages answer 404 to everyone, its owner too');
$t = page($jp, '/trash');
ok(str_contains($t['body'], 'id="trash-card-' . $id . '"') && str_contains($t['body'], '>In the trash<') && str_contains($t['body'], 'Deleted by SMOKE Priya'), 'in Priya\'s trash: "In the trash", who deleted it');
$purge = (string) one("SELECT to_char((deleted_at + interval '30 days') AT TIME ZONE 'UTC', 'Mon FMDD, YYYY') FROM boards WHERE id = CAST(:b AS uuid)", ['b' => $id]);
ok(str_contains($t['body'], 'Purged on ' . $purge), 'with its purge date, 30 days on (' . $purge . ')');
ok(!str_contains(page($jb, '/trash')['body'], 'trash-card-' . $id), 'not in Bea\'s trash (it is not hers)');
ok(str_contains(page($jm, '/trash')['body'], 'trash-card-' . $id), 'a Boards admin sees every deleted board');
$r = act($jb, '/boards/restore.php', ['board' => $id]);
ok($r['code'] === 404 && msg($r) === 'There is no such board in your trash.', 'Bea restoring: 404 "There is no such board in your trash."');
$before = last_activity_id();
$r = req('POST', '/boards/restore.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'board' => $id]]);
ok($r['code'] === 302 && str_contains($r['location'], '/trash?notice=restored'), 'Priya restores it from the trash page (lands there with a notice)');
ok(count(activity('board.restore', $before)) === 1 && str_contains(page($jp, '/boards')['body'], 'board-card-' . $id), 'board.restore is logged; the card is back');
ok(level_of($id, 28) === 'edit' && level_of($id, 30) === 'comment', '…with its shares intact (Bea edit, Dana through Sales at comment)');
$r = act($jp, '/boards/restore.php', ['board' => $id]);
ok($r['code'] === 404, 'restoring a board that is not in the trash: 404');
$id2 = make_board($jp, 'SMOKE Admin brings back');
act($jp, '/boards/delete.php', ['board' => $id2]);
$r = act($jm, '/boards/restore.php', ['board' => $id2]);
ok($r['code'] === 200 && one('SELECT deleted_at FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id2]) === null, 'a Boards admin restores anyone\'s');
$tj = page($jp, '/trash', ['headers' => ['Accept: application/json']]);
ok($tj['code'] === 200 && array_key_exists('rows', body_json($tj)['data'] ?? []), 'the trash answers JSON (trash_list\'s shape)');
act($jp, '/boards/delete.php', ['board' => $id2]);
$row = body_json(page($jp, '/trash', ['headers' => ['Accept: application/json']]))['data']['rows'][0] ?? [];
ok(($row['board_id'] ?? '') === $id2 && isset($row['purge_after'], $row['deleted_at']) && ($row['deleted_by'] ?? '') === 'SMOKE Priya', '…each row: the board, when deleted and by whom, when purged');
finish();
