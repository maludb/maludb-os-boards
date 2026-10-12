<?php
/**
 * Slice 10 — Purge now and the worker's trash pass (home-admin.md "Proof" 5): a trashed board with two fixture files under the store goes with its items, both files unlinked, files_left 0; a path outside the store left
 * and counted; a board not in the trash 404; a Member 403; an agent refused (people only); the admin's page lists the trash with Restore and Purge now. The worker's `trash` pass purges a board deleted past trash_days and keeps a
 * newer one. Run through tests/phase3/slice10/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
[$jb] = sign_on(28);
[$jd] = sign_on(30);
settings_reset();
$since = last_activity_id();

echo "5. The trash on the admin's page\n";
[$b, $files] = trashed_board($jp, 'SMOKE Purge me');
$h = page($jm, '/admin')['body'];
ok(str_contains($h, 'id="trash-row-' . $b . '"') && str_contains($h, 'id="trash-row-' . $b . '-restore"') && str_contains($h, 'id="trash-purge-' . $b . '"') && str_contains($h, 'Deleted by SMOKE Priya') && str_contains($h, 'Purged on '), 'Marco sees the deleted board: owner, who deleted it and when, when it is purged, Restore and Purge now');
ok(str_contains($h, 'hx-confirm="Purge SMOKE Purge me for good, with its 2 items and files? This cannot be undone."'), 'Purge now asks first: "Purge {title} for good, with its {n} items and files? This cannot be undone."');
$j = admin_data($jm)['trash'];
$row = array_values(array_filter($j, static fn ($t) => $t['board_id'] === $b))[0] ?? [];
ok(array_keys($row) === ['board_id', 'title', 'owner_name', 'item_count', 'deleted_at', 'deleted_by', 'purge_after'] && $row['owner_name'] === 'SMOKE Priya' && $row['item_count'] === 2 && $row['deleted_by'] === 'SMOKE Priya', 'JSON: each trashed board — title, owner, items, deleted by and when, purge_after');
ok(!str_contains(page($jm, '/admin')['body'], 'no-such') && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === 1, 'the board is still a row until it is purged');

echo "5. Purge now\n";
$r = purge_as($jp, $b);
ok($r['code'] === 403 && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === 1, 'Priya (a Member, the board\'s own owner): 403, nothing purged');
$r = purge_as($jb, $b);
ok($r['code'] === 403, 'Bea: 403');
$r = req('POST', '/admin/trash-purge.php', ['headers' => as_agent(run_token(FACILITATOR, 9951)), 'form' => ['board' => $b]]);
ok($r['code'] === 403 && str_contains($r['body'], 'This is for people') && count(array_filter(activity('trash.purge', $since))) === 0, 'an agent: refused — for people only');
$live = make_board($jp, 'SMOKE Not in the trash');
$r = purge_as($jm, $live);
ok($r['code'] === 404 && (body_json($r)['error']['message'] ?? '') === 'There is no such board in the trash.' && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $live]) === 1, 'a board that is not in the trash: 404');
ok(purge_as($jm, 'not-a-uuid')['code'] === 404 && purge_as($jm, uuid4())['code'] === 404, '…and a board that is not there, or not a uuid');
$r = req('POST', '/admin/trash-purge.php', ['jar' => $jm, 'headers' => ['Accept: application/json'], 'form' => ['board' => $b]]);
ok($r['code'] === 403 && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === 1, 'without the CSRF token: 403');
ok(file_exists($files[0]) && file_exists($files[1]), 'the two fixture files are on disk before the purge');
// a third row: a path OUTSIDE the store, which must be left and counted
$outside = dirname(rtrim(need('BD_STORAGE_DIR'), '/')) . '/bd10-outside-' . getmypid() . '.txt';
file_put_contents($outside, 'not the store\'s');
attach($b, '../' . basename($outside), 'outside.txt');
$items = (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid)', ['b' => $b]);
$before = last_activity_id();
$r = purge_as($jm, $b);
$d = body_json($r);
ok($r['code'] === 200 && $d['ok'] === true && $d['record_id'] === $b && $d['location'] === '/admin?notice=purged#trash' && $d['refresh'] === 'boardChanged' && $d['files_removed'] === 2 && $d['files_left'] === 1 && $d['items'] === 2 && $items === 2,
    'trash_purge: ok, record_id the board, location /admin#trash, 2 files removed, 1 left, 2 items');
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === 0 && (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid)', ['b' => $b]) === 0
    && (int) one('SELECT count(*) FROM attachments WHERE board_id = CAST(:b AS uuid)', ['b' => $b]) === 0 && (int) one('SELECT count(*) FROM board_ops WHERE board_id = CAST(:b AS uuid)', ['b' => $b]) === 0, 'the board is gone with its items, attachments and ops');
ok(!file_exists($files[0]) && !file_exists($files[1]), 'both fixture files in the store are unlinked');
ok(is_file($outside) && $d['files_left'] === 1, 'the path outside the store is left on disk and counted in files_left');
@unlink($outside);
$log = activity('trash.purge', $before);
$aft = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && $log[0]['board_id'] === $b && $log[0]['source'] === 'web' && $aft == ['title' => 'SMOKE Purge me', 'items' => 2, 'files_removed' => 2, 'files_left' => 1], 'trash.purge is logged with the board and the title and counts');
ok(!str_contains(page($jm, '/admin')['body'], 'trash-row-' . $b) && purge_as($jm, $b)['code'] === 404, 'it is gone from the admin\'s trash, and a second purge is 404');
// HTML: a browser lands on /admin#trash
[$b2] = trashed_board($jp, 'SMOKE Purge by form');
$r = req('POST', '/admin/trash-purge.php', ['jar' => $jm, 'form' => ['csrf_token' => page_csrf($jm), 'board' => $b2]]);
ok($r['code'] === 302 && str_contains($r['location'], '/admin?notice=purged') && str_ends_with($r['location'], '#trash'), 'from the form a browser lands on the admin\'s trash with a notice');
ok(str_contains(page($jm, '/admin?notice=purged')['body'], 'Purged for good, with everything on it.'), '…which says so');
// restore from the admin's page lands there
[$b3] = trashed_board($jp, 'SMOKE Restore from admin');
$r = req('POST', '/boards/restore.php', ['jar' => $jm, 'form' => ['csrf_token' => page_csrf($jm), 'board' => $b3, 'return_to' => '/admin#trash']]);
ok($r['code'] === 302 && str_contains($r['location'], '/admin?notice=restored') && str_ends_with($r['location'], '#trash') && one('SELECT deleted_at FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b3]) === null, 'Restore on the admin\'s page restores and lands back on it');
// a holder of trash.purge who is not a Boards admin: the database says no
give_role(30, 'purger', ['trash.purge']);
[$b4] = trashed_board($jp, 'SMOKE Not for the purger');
$r = purge_as($jd, $b4);
ok(in_array($r['code'], [403, 404], true) && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b4]) === 1, 'Dana with trash.purge alone (not a Boards admin, not the owner): she cannot reach the board — ' . $r['code']);
set_roles(30, '{viewer}');

echo "5. The worker's trash pass\n";
[$old, $oldFiles] = trashed_board($jp, 'SMOKE Trash 31 days');
[$new, $newFiles] = trashed_board($jp, 'SMOKE Trash yesterday');
pg("UPDATE boards SET deleted_at = now() - interval '31 days' WHERE id = '$old'");
pg("UPDATE boards SET deleted_at = now() - interval '1 day' WHERE id = '$new'");
$before = last_activity_id();
$w = worker_pass('trash');
ok($w['rc'] === 0 && $w['errors'] === [] && ($w['ran']['trash']['purged'] ?? -1) >= 1 && !isset($w['ran']['trash']['stub']) && array_keys($w['ran']['trash']) === ['purged', 'files_removed', 'files_left'], 'the pass reports purged / files_removed / files_left — no stub: ' . json_encode($w['ran']['trash'] ?? []));
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $old]) === 0 && !file_exists($oldFiles[0]) && !file_exists($oldFiles[1]) && $w['ran']['trash']['files_removed'] >= 2, 'the board deleted 31 days ago is purged with its files');
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]) === 1 && file_exists($newFiles[0]) && file_exists($newFiles[1]), '…the one deleted yesterday stays, files and all');
$cron = array_values(array_filter(activity('trash.purge', $before), static fn ($x) => $x['board_id'] === $old));
ok(count($cron) === 1 && $cron[0]['source'] === 'cron' && $cron[0]['actor_member_id'] === null, 'one trash.purge row (source cron, no actor) for it');
ok(($w2 = worker_pass('trash'))['ran']['trash']['purged'] === 0, 'the next pass finds nothing');
$w = worker_pass('trash', clock(31 * 86400));
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]) === 0 && !file_exists($newFiles[0]) && ($w['ran']['trash']['purged'] ?? 0) >= 1, 'with the clock 31 days on, the one deleted yesterday is due and goes too (BD_WORKER_NOW)');
// trash_days is honoured
[$t60, ] = trashed_board($jp, 'SMOKE Trash with 60 days', 0);
pg("UPDATE boards SET deleted_at = now() - interval '40 days' WHERE id = '$t60'");
pg('UPDATE bd_settings SET trash_days = 60');
worker_pass('trash');
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $t60]) === 1, 'trash_days 60: a board deleted 40 days ago stays');
pg('UPDATE bd_settings SET trash_days = 30');
worker_pass('trash');
ok((int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $t60]) === 0, 'trash_days back to 30: it goes');
// an outside path in the worker's pass is left as well
[$wo] = trashed_board($jp, 'SMOKE Worker outside', 1);
$outside = dirname(rtrim(need('BD_STORAGE_DIR'), '/')) . '/bd10-worker-outside-' . getmypid() . '.txt';
file_put_contents($outside, 'x');
attach($wo, '../' . basename($outside), 'o.txt');
pg("UPDATE boards SET deleted_at = now() - interval '35 days' WHERE id = '$wo'");
$w = worker_pass('trash');
ok(is_file($outside) && ($w['ran']['trash']['files_left'] ?? 0) === 1 && (int) one('SELECT count(*) FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $wo]) === 0 && str_contains($w['raw'], 'outside the store'), 'the pass leaves a file outside the store where it is, counts it and says so');
@unlink($outside);
finish();
