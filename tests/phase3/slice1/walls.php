<?php
/**
 * Proof 8, the wall (boards-folders.md; walls-and-sharing.md): a stranger's GET of every board page and POST to every board handler is 404 —
 * never 403, never a hint that the board exists; an agent acts as itself (its own row decides), logged as an agent; a super-admin is a
 * Boards admin (full everywhere); an external sees nothing not shared with them by name.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
[$jb, ] = sign_on(28);
[$ja, ] = sign_on(29);
[$jo, ] = sign_on(1);
echo "8. The wall\n";
$id = make_board($jp, 'SMOKE Private');
$bad = [];
foreach (['', '/settings', '/share', '/outline', '/comments', '/history'] as $p) {
    $r = page($jb, "/boards/$id$p");
    if ($r['code'] !== 404 || str_contains($r['body'], 'SMOKE Private')) { $bad[] = "GET $p {$r['code']}"; }
}
$posts = ['/boards/save.php' => ['title' => 'x'], '/boards/lock.php' => ['locked' => 'yes'], '/boards/delete.php' => [], '/boards/share-set.php' => ['member' => 28, 'level' => 'full'],
          '/boards/share-remove.php' => ['member' => 28], '/boards/access-set.php' => ['level' => 'edit'], '/boards/transfer.php' => ['new_owner' => 28], '/boards/favorite.php' => [],
          '/folders/add.php' => ['folder' => 1]];
foreach ($posts as $file => $form) {
    $r = act($jb, $file, ['board' => $id] + $form);
    if ($r['code'] !== 404 || str_contains($r['body'], 'SMOKE Private')) { $bad[] = "POST $file {$r['code']} " . msg($r); }
}
ok($bad === [], 'Bea, a stranger: every board page and every board handler answers 404, never 403, never its title' . ($bad ? ' — ' . implode('; ', $bad) : ''));
ok((int) one("SELECT count(*) FROM board_permissions WHERE board_id = CAST(:b AS uuid)", ['b' => $id]) === 0 && one('SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id]) === 'SMOKE Private', '…and nothing changed');
ok(page($jo, "/boards/$id/settings")['code'] === 200 && level_of($id, 1) === 'full', 'the super-admin (a Boards admin) opens it at full');
ok(page($ja, "/boards/$id/share")['code'] === 404 && !str_contains(page($ja, '/boards')['body'], 'board-card-'), 'Ann, an external, sees nothing not shared with her by name');
echo "   an agent acts as itself\n";
$tok = run_token(42, 8101);
kernel_state(function ($s) { $s['facts']['8101'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 8101, 'request_id' => 'r-8101', 'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
$r = req('POST', '/boards/save.php', ['headers' => as_agent($tok), 'form' => ['board' => $id, 'title' => 'SMOKE by the Facilitator']]);
ok($r['code'] === 404, 'the Facilitator, not shared: 404 — an agent\'s token is its member, and its level is the same function\'s answer');
act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 42, 'level' => 'edit']);
$before = last_activity_id();
$r = req('POST', '/boards/save.php', ['headers' => as_agent($tok), 'form' => ['board' => $id, 'title' => 'SMOKE by the Facilitator']]);
ok($r['code'] === 200 && body_json($r)['record_id'] === $id && one('SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $id]) === 'SMOKE by the Facilitator', 'shared at edit, it renames the board');
$u = activity('board.update', $before);
ok(count($u) === 1 && (int) $u[0]['actor_member_id'] === 42 && $u[0]['source'] === 'agent' && (int) $u[0]['agent_run_id'] === 8101, 'logged as the agent, source agent, with its run');
$r = req('POST', '/boards/delete.php', ['headers' => as_agent($tok), 'form' => ['board' => $id]]);
ok($r['code'] === 403 && msg($r) === 'Only the board\'s owners can do that.', 'its delete at edit: 403 (and a delete is paused by the kernel before it comes here — deletion)');
$r = req('POST', '/boards/save.php', ['headers' => as_agent($tok), 'form' => ['title' => 'SMOKE agent board']]);
ok($r['code'] === 200 && (int) one("SELECT owner_member_id FROM boards WHERE title = 'SMOKE agent board'") === 42, 'an agent holding boards.create makes a board of its own (the Facilitator, a Member)');
finish();
