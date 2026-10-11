<?php
/**
 * Proof 3 and 4 (boards-folders.md; walls-and-sharing.md "The share page"): Sales at edit and a Viewer in Sales capped at comment; everyone at
 * view (an external never through it; widening asks first, narrowing does not); Ann (external) by name and told once; the Facilitator (an
 * agent) at edit; the Watcher (no Boards grant) flagged; the owner's own row refused; a non-owner refused; unshare; the approvals; transfer.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
[$jb, ] = sign_on(28);
[$jd, ] = sign_on(30);
[$ja, ] = sign_on(29);
$id = make_board($jp, 'SMOKE Shared board');

echo "3. Sharing\n";
$before = last_activity_id();
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'department' => SALES, 'level' => 'edit']);
ok($r['code'] === 200 && body_json($r)['principal_kind'] === 'department' && level_of($id, 26) === 'full', 'Priya shares with Sales at edit');
ok(level_of($id, 31) === 'comment' && level_of($id, 30) === 'comment', 'Dana and Lee, Viewers in Sales, open it at comment — the role\'s cap, not the row\'s edit');
ok(str_contains(page($jd, '/boards')['body'], 'data-level="comment">Can comment<'), '…Dana\'s card says "Can comment"');
$log = activity('board.share', $before);
ok(count($log) === 1 && $log[0]['board_id'] === $id && str_contains((string) $log[0]['after'], '"principal_name": "Sales"') && str_contains((string) $log[0]['before'], '"level": "none"'), 'board.share logs the principal and the level before → after');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'share' AND board_id = CAST(:b AS uuid)", ['b' => $id]) === 0, 'a department shared is told nothing (no notice to Sales\' members)');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 29, 'level' => 'view']);
ok($r['code'] === 200 && level_of($id, 29) === 'view', 'Ann, an external, shared by name at view: she opens it');
$n = q("SELECT member_id, title, actor_member_id FROM notifications WHERE kind = 'share' AND board_id = CAST(:b AS uuid)", ['b' => $id]);
ok(count($n) === 1 && (int) $n[0]['member_id'] === 29 && $n[0]['title'] === 'SMOKE Priya shared SMOKE Shared board with you' && (int) $n[0]['actor_member_id'] === 26, 'Ann has one `share` notice: "SMOKE Priya shared SMOKE Shared board with you"');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 29 AND kind = 'share' AND channel = 'email'") === 1, '…and one email queued in the outbox, by her preferences');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 42, 'level' => 'edit']);
ok($r['code'] === 200 && body_json($r)['principal_kind'] === 'agent' && level_of($id, 42) === 'edit', 'the Facilitator (an agent) at edit: sent as its member id, written as kind agent');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 42") === 0, '…an agent is never sent a notice');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 41, 'level' => 'view']);
ok($r['code'] === 200 && body_json($r)['needs_grant'] === true && str_contains(body_json($r)['location'] ?? '', 'notice=needs_grant'), 'the Watcher (no Boards grant) is shared and flagged needs_grant');
$sp = page($jp, "/boards/$id/share");
ok(str_contains($sp['body'], 'id="share-row-agent-41-needs-grant"') && str_contains($sp['body'], 'cannot open Boards yet — ask a super-admin to grant it'), 'the share page flags it: "cannot open Boards yet — ask a super-admin"');
ok(str_contains($sp['body'], 'id="share-row-department-' . SALES . '"') && str_contains($sp['body'], 'id="share-row-member-29"') && str_contains($sp['body'], 'id="share-row-agent-42"')
    && str_contains($sp['body'], '>Agent</span>') && str_contains($sp['body'], '>External</span>') && str_contains($sp['body'], '>Department</span>'), 'the rows: Sales, Ann (External), the Facilitator (Agent), each chipped');
ok(str_contains($sp['body'], 'id="share-row-member-29-cap"'), 'Ann\'s row says "(a Viewer: at most comment)"');
ok(str_contains($sp['body'], 'id="share-row-member-29-level"') && str_contains($sp['body'], 'id="share-add-member-picker"') && str_contains($sp['body'], 'id="share-add-department-picker"') && str_contains($sp['body'], 'id="share-owner-transfer-to-picker"'),
    'the owner sees each level as a select, Add with the member and department pickers, Transfer with a picker');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 26, 'level' => 'edit']);
ok($r['code'] === 422 && msg($r) === 'The owner already has every right on the board.', 'the owner\'s own row is refused');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 28, 'department' => SALES, 'level' => 'edit']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['member']), 'a share naming both a person and a department is refused');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 99999, 'level' => 'edit']);
ok($r['code'] === 422 && msg($r) === 'There is no such person or agent.', 'a member the mirror does not know is refused');
$r = act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 28, 'level' => 'owner']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['level']), 'a level that is not view, comment, edit or full is refused');
act($jp, '/boards/share-set.php', ['board' => $id, 'member' => 28, 'level' => 'edit']);
$r = act($jb, '/boards/share-set.php', ['board' => $id, 'member' => 27, 'level' => 'view']);
ok($r['code'] === 403 && msg($r) === 'Only the board\'s owners can do that.', 'Bea (at edit, not an owner) sharing: 403 in the database\'s words');
$sb = page($jb, "/boards/$id/share");
ok($sb['code'] === 200 && str_contains($sb['body'], 'id="share-readonly"') && !str_contains($sb['body'], 'id="share-add"') && !str_contains($sb['body'], 'id="share-everyone-level"'), 'Bea sees the list read-only: "Only the board\'s owners change who can open it."');

echo "   everyone in the business\n";
$r = req('POST', '/boards/access-set.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'board' => $id, 'level' => 'view']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="share-everyone-confirm"') && str_contains($r['body'], 'will be able to view this board.') && level_of($id, 27) === 'full' && level_of($id, 40) === 'none',
    'widening from a browser asks first: the share page with the question, nothing changed yet');
$r = req('POST', '/boards/access-set.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'board' => $id, 'level' => 'view', 'confirm' => '1']]);
ok($r['code'] === 302 && level_of($id, 40) === 'view' && level_of($id, 31) === 'comment', 'Confirm: everyone at view — the Expert opens it at view (Lee keeps his Sales comment)');
[$jx, ] = sign_on(29);
pg("UPDATE board_permissions SET level = 'none' WHERE board_id = '$id' AND principal_kind = 'member' AND principal_id = 29");
ok(level_of($id, 29) === 'none', 'an external never sees a board through everyone (Ann with her own row at none: nothing)');
pg("UPDATE board_permissions SET level = 'view' WHERE board_id = '$id' AND principal_kind = 'member' AND principal_id = 29");
$before = last_activity_id();
$r = req('POST', '/boards/access-set.php', ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp), 'board' => $id, 'level' => 'none']]);
ok($r['code'] === 302 && level_of($id, 40) === 'none', 'narrowing asks nothing: everyone back to none at once');
$a = activity('board.access_set', $before);
ok(count($a) === 1 && str_contains((string) $a[0]['before'], '"view"') && str_contains((string) $a[0]['after'], '"none"'), 'board.access_set logs before → after');
$r = act($jp, '/boards/access-set.php', ['board' => $id, 'level' => 'full']);
ok($r['code'] === 422, 'everyone at full is refused');
$r = act($jp, '/boards/access-set.php', ['board' => $id, 'level' => 'edit']);
ok($r['code'] === 200 && level_of($id, 40) === 'edit', 'a JSON caller (the command bar, an agent — whose ✔ or pause came first) widens without the page\'s question');
act($jp, '/boards/access-set.php', ['board' => $id, 'level' => 'none']);

echo "   unshare\n";
$before = last_activity_id();
$r = act($jp, '/boards/share-remove.php', ['board' => $id, 'member' => 29]);
ok($r['code'] === 200 && level_of($id, 29) === 'none' && !str_contains(page($jp, "/boards/$id/share")['body'], 'id="share-row-member-29"'), 'unshare Ann: her row is gone, she opens nothing');
$u = activity('board.unshare', $before);
ok(count($u) === 1 && str_contains((string) $u[0]['before'], '"level": "view"'), 'board.unshare logs the level she had');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'share' AND member_id = 29") === 1, 'an unshare tells nobody');
$r = act($jp, '/boards/share-remove.php', ['board' => $id, 'member' => 29]);
ok($r['code'] === 404, 'unsharing someone with no row: 404');
$r = act($jp, '/boards/share-remove.php', ['board' => $id, 'department' => SALES]);
ok($r['code'] === 200 && level_of($id, 30) === 'none', 'unshare Sales: Dana opens nothing');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['board_share']['approval'] ?? null) === 'other' && ($reg['board_unshare']['approval'] ?? null) === 'other' && ($reg['board_access_set']['approval'] ?? null) === 'other'
    && ($reg['board_transfer']['approval'] ?? null) === 'other' && ($reg['board_delete']['approval'] ?? null) === 'deletion' && ($reg['folder_delete']['approval'] ?? null) === 'deletion',
    'an agent\'s board_share, unshare, access_set and transfer pause (other) and its deletes (deletion) — the registry the kernel reads');

echo "4. Transfer\n";
$t = make_board($jp, 'SMOKE To hand over');
$r = act($jp, '/boards/transfer.php', ['board' => $t, 'new_owner' => 30]);
ok($r['code'] === 422 && msg($r) === 'A board passes only to a person who may make boards.', 'to Dana (a Viewer): refused in the database\'s words');
$r = act($jp, '/boards/transfer.php', ['board' => $t, 'new_owner' => 42]);
ok($r['code'] === 422 && msg($r) === 'A board passes only to a person who may make boards.', 'to an agent: refused');
$before = last_activity_id();
$r = act($jp, '/boards/transfer.php', ['board' => $t, 'new_owner' => 28]);
ok($r['code'] === 200 && (int) one('SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $t]) === 28, 'to Bea (a Member): she owns it');
ok(level_of($t, 28) === 'full' && level_of($t, 26) === 'edit', '…and Priya keeps edit');
$tr = activity('board.transfer', $before);
ok(count($tr) === 1 && str_contains((string) $tr[0]['before'], '26') && str_contains((string) $tr[0]['after'], '28'), 'board.transfer logs the old and the new owner');
$r = act($jp, '/boards/transfer.php', ['board' => $t, 'new_owner' => 26]);
ok($r['code'] === 403, 'Priya cannot take it back — only the new owner hands it back');
$r = act($jb, '/boards/transfer.php', ['board' => $t, 'new_owner' => 26]);
ok($r['code'] === 200 && level_of($t, 26) === 'full' && level_of($t, 28) === 'edit', 'Bea hands it back: Priya owns it again, Bea keeps edit');
finish();
