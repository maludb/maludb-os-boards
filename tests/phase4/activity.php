<?php
/**
 * Proof — the activity server (docs/boards-mcp-tool-surface.md "Activity server", A1–A6): the seven tools against the log the world made. Every figure is checked against mcp_activity_log as the same member; the wall (the board's, bd_my_board_levels) is
 * the view's; an event that concerns no board is its actor's or a Boards admin's. A payload carries ids, names, counts and levels — never a comment's text, a token or a path — so nothing here can return one. Servers: activity :9105.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$A = $w['roadmap'];
$act = fn (int $m, string $name, array $args = []) => mcp_tool(ACT, ptoken($m), $name, $args);

echo "1. board_trail — who opened or worked on a board (A1)\n";
$view = (int) val_as(PRIYA, "SELECT count(*) FROM mcp_activity_log WHERE board_id = CAST('$A' AS uuid)");
$t = $act(PRIYA, 'board_trail', ['board' => $A, 'limit' => 200]);
$rows = $t['data']['rows'];
ok(!$t['error'] && count($rows) === $view && $view > 15 && $t['data']['truncated'] === false, "the trail is the log's rows of the board as Priya sees them: $view");
ok(array_keys($rows[0]) === ['at', 'member_name', 'member_kind', 'source', 'action', 'entity_type', 'board_id', 'counts', 'run_id', 'summary'], 'a row: at, member_name, member_kind, source, action, entity_type, board_id, counts, run_id, summary');
$at = array_column($rows, 'at'); $s = $at; rsort($s);
ok($at === $s, 'newest first');
$by = array_count_values(array_column($rows, 'action'));
ok(($by['item.create'] ?? 0) >= 2 && ($by['comment.create'] ?? 0) === 5 && ($by['comment.reply'] ?? 0) === 2 && ($by['version.save'] ?? 0) === 1 && ($by['board.share'] ?? 0) === 5 && ($by['file.upload'] ?? 0) === 1, 'item batches, five threads, two replies, a version, five shares, an upload — as they happened');
$names = array_unique(array_column($rows, 'member_name'));
ok(in_array('SMOKE Priya', $names, true) && in_array('SMOKE Bea', $names, true) && in_array('SMOKE Facilitator', $names, true), 'who: a person, a colleague, an agent — each by name');
$fa = find_row($rows, 'member_name', 'SMOKE Facilitator');
ok($fa['member_kind'] === 'agent' && $fa['source'] === 'agent' && $fa['run_id'] === 7101 && $fa['summary'] === 'SMOKE Facilitator replied in a thread', 'the agent\'s row says it is an agent, its source, its run (7101) and a sentence');
$im = find_row($rows, 'action', 'item.create');
ok(is_array($im['counts']) && ($im['counts']['create'] ?? 0) >= 1, 'an item batch carries its counts by verb');
ok(count(tdata(PRIYA, 'board_trail', ['board' => $A, 'since' => '2099-01-01'], ACT)['rows']) === 0 && count(tdata(PRIYA, 'board_trail', ['board' => $A, 'until' => '2099-01-01', 'limit' => 200], ACT)['rows']) === $view, 'since / until');
ok(count(tdata(PRIYA, 'board_trail', ['board' => 'SMOKE p4 Roadmap', 'limit' => 5], ACT)['rows']) === 5, 'named by title; limit');
ok(!in_array('share.read', array_column($rows, 'action'), true), 'a sibling\'s share read is not on the board\'s trail (the row names no board)');
$dn = $act(DANA, 'board_trail', ['board' => $A, 'limit' => 5]);
ok(!$dn['error'], 'a Viewer who can open the board reads its trail');

echo "2. member_activity (A2), record_history (A3)\n";
$ma = $act(PRIYA, 'member_activity', ['member' => 'SMOKE Facilitator']);
ok(!$ma['error'] && count($ma['data']['rows']) === 1 && array_unique(array_column($ma['data']['rows'], 'board_title')) === ['SMOKE p4 Roadmap'] && $ma['data']['rows'][0]['action'] === 'comment.reply', 'what the Facilitator did: its rows, on the Roadmap, never a screen view');
$ma = $act(PRIYA, 'member_activity', ['member' => 'me', 'limit' => 200]);
$a = array_column($ma['data']['rows'], 'action');
ok(!in_array('screen.view', $a, true) && !in_array('folder.create', $a, true) && !in_array('token.mint', $a, true) && count($ma['data']['rows']) > 20 && $ma['data']['rows'][0]['summary'] !== '', 'Priya\'s own: boards only (a folder or a token is no board), no screen view, each with a sentence');
ok(!$act(BEA, 'member_activity', ['member' => PRIYA, 'limit' => 200])['error'] && !in_array($w['secret'], array_column($act(BEA, 'member_activity', ['member' => PRIYA, 'limit' => 200])['data']['rows'], 'board_id'), true), 'Bea reads what Priya did — on the boards Bea can open, not the Secret');
ok(count($act(PRIYA, 'member_activity', ['member' => 'me', 'since' => '2099-01-01'])['data']['rows']) === 0 && $act(PRIYA, 'member_activity', ['member' => 'Nobody Here'])['code'] === 'not_found', 'since; an unknown person is not_found');
$rh = $act(PRIYA, 'record_history', ['kind' => 'board', 'id' => $A, 'limit' => 100]);
$acts = array_column($rh['data']['rows'], 'action');
ok(!$rh['error'] && !array_filter($acts, fn ($x) => str_starts_with($x, 'item.')) && in_array('board.share', $acts, true) && in_array('version.save', $acts, true) && in_array('board.favorite', $acts, true) && !in_array('screen.view', $acts, true), 'a board\'s history: when it was shared, versioned, starred — not its item batches, not screen views');
$sh = array_values(array_filter($rh['data']['rows'], fn ($r) => $r['action'] === 'board.share' && ($r['after']['principal_name'] ?? '') === 'SMOKE Bea'))[0] ?? null;
ok($sh !== null && $sh['after']['level'] === 'edit' && $sh['member_name'] === 'SMOKE Priya' && array_key_exists('before', $sh), 'a share: who, to whom, at which level — before and after');
$ch = $act(PRIYA, 'record_history', ['kind' => 'comment', 'id' => $w['thread_bea']]);
ok(!$ch['error'] && array_column($ch['data']['rows'], 'action') === ['comment.reply', 'comment.create'] && !str_contains($ch['text'], 'pricing idea final') && !str_contains($ch['text'], 'it is final'), 'a thread\'s history: the thread and its reply, newest first — and not a word of what was written');
$fh = $act(PRIYA, 'record_history', ['kind' => 'folder', 'id' => (string) $w['folder']]);
ok(array_column($fh['data']['rows'], 'action') === ['folder.add', 'folder.create'], 'a folder\'s history: made, a board put in');
$tf = $act(BEA, 'record_history', ['kind' => 'folder', 'id' => (string) $w['team_folder']]);
ok(!$tf['error'] && array_column($tf['data']['rows'], 'action') === ['folder.create'], 'a team folder\'s history is the business\'s to read');
ok($act(PRIYA, 'record_history', ['kind' => 'folder', 'id' => 'abc'])['code'] === 'invalid_argument' && $act(PRIYA, 'record_history', ['kind' => 'comment', 'id' => 'not-a-uuid'])['code'] === 'invalid_argument' && $act(PRIYA, 'record_history', ['kind' => 'page', 'id' => $A])['code'] === 'invalid_argument', 'a bad kind or id: invalid_argument');
ok($act(PRIYA, 'record_history', ['kind' => 'comment', 'id' => $missing = '99999999-9999-4999-8999-999999999999'])['code'] === 'not_found', 'a comment that has no history and is nowhere: not_found');

echo "3. most_active_boards (A4), agent_activity (A5), recent_activity (A6), activity_search (A6)\n";
$mo = $act(PRIYA, 'most_active_boards', ['limit' => 50]);
$ra = find_row($mo['data']['rows'], 'board_id', $A);
$sqlBatches = (int) val_as(PRIYA, "SELECT count(*) FROM mcp_activity_log WHERE board_id = CAST('$A' AS uuid) AND (action LIKE 'item.%' OR action IN ('ops.undo', 'version.restore'))");
ok($mo['data']['rows'][0]['board_id'] === $A && $ra['batches'] === $sqlBatches && $ra['members'] >= 3 && $ra['last_at'] !== null, 'the busiest board is the Roadmap: its batches equal the log\'s item batches (' . $sqlBatches . '), three or more people worked on it');
ok(!in_array($w['secret'], array_column($act(BEA, 'most_active_boards', ['limit' => 50])['data']['rows'], 'board_id'), true) && !in_array($w['roadmap'], array_column($act(LEE, 'most_active_boards', ['limit' => 50])['data']['rows'], 'board_id'), true), 'Bea\'s list holds no private board of Priya\'s; Lee\'s no board he cannot open');
ok($act(PRIYA, 'most_active_boards', ['since' => '2099-01-01'])['data']['rows'] === [] && count($act(PRIYA, 'most_active_boards', ['limit' => 2])['data']['rows']) === 2, 'since; limit');
$ag = $act(PRIYA, 'agent_activity', []);
ok(!$ag['error'] && count($ag['data']['rows']) >= 1 && array_unique(array_column($ag['data']['rows'], 'member_kind')) === ['agent'] && $ag['data']['rows'][0]['agent_name'] === 'SMOKE Facilitator' && $ag['data']['rows'][0]['board_title'] === 'SMOKE p4 Roadmap' && $ag['data']['rows'][0]['run_id'] === 7101, 'what agents did: the Facilitator\'s reply, on the Roadmap, with its run');
ok(count($act(PRIYA, 'agent_activity', ['agent' => 'SMOKE Facilitator', 'board' => $A])['data']['rows']) >= 1 && $act(PRIYA, 'agent_activity', ['agent' => 'SMOKE Expert'])['data']['rows'] === [] && $act(PRIYA, 'agent_activity', ['agent' => 'SMOKE Bea'])['data']['rows'] === [], 'by agent, by board; a human is no agent');
ok($act(LEE, 'agent_activity', [])['data']['rows'] === [], 'an agent\'s work on a board Lee cannot open is not his to read');
$rc = $act(BEA, 'recent_activity', ['limit' => 200]);
$ra = array_column($rc['data']['rows'], 'action');
ok(!in_array('screen.view', $ra, true) && count($rc['data']['rows']) > 20, 'recent activity: what happened on boards Bea can open, no screen view');
$times = array_column($rc['data']['rows'], 'at'); $ts = $times; rsort($ts);
ok($times === $ts, 'newest first');
$bf = array_unique(array_filter(array_column($rc['data']['rows'], 'board_id')));
$visible = array_column(sql_as(BEA, 'SELECT board_id FROM mcp_boards'), 'board_id');
ok(array_diff($bf, $visible) === [], 'every board named is one Bea can open');
$se = $act(PRIYA, 'activity_search', ['q' => 'baseline']);
ok(!$se['error'] && count($se['data']['rows']) === 1 && $se['data']['rows'][0]['action'] === 'version.save', 'activity_search finds a version by its label');
ok(count($act(PRIYA, 'activity_search', ['q' => 'roadmap share'])['data']['rows']) >= 5 && $act(PRIYA, 'activity_search', ['q' => 'roadmap zzzqq'])['data']['rows'] === [], 'every word must match (the action, the board\'s title, the names the payloads carry)');
ok(count($act(PRIYA, 'activity_search', ['q' => 'facilitator'])['data']['rows']) >= 2, 'by the actor\'s name, and by the name a share was made to');
ok($act(LEE, 'activity_search', ['q' => 'secret'])['data']['rows'] === [] && $act(BEA, 'activity_search', ['q' => 'secret'])['data']['rows'] === [] && count($act(PRIYA, 'activity_search', ['q' => 'secret'])['data']['rows']) >= 1, 'the Secret board\'s trail: Priya finds it, Lee and Bea do not');
ok($act(PRIYA, 'activity_search', ['q' => 'a'])['code'] === 'invalid_argument' && $act(PRIYA, 'activity_search', ['q' => 'roadmap', 'since' => '2099-01-01'])['data']['rows'] === [], 'one letter is refused; since');

echo "4. Events that concern no board\n";
$bea = $act(BEA, 'recent_activity', ['limit' => 200]);
$nb = array_filter($bea['data']['rows'], fn ($r) => $r['board_id'] === null);
ok(count($nb) >= 1 && array_unique(array_column($nb, 'member_name')) === ['SMOKE Bea'] && !array_intersect(['folder.create', 'directory.sync', 'share.read'], array_column($bea['data']['rows'], 'action')), 'an event that concerns no board (a folder, a sign-on, a directory sync, a sibling\'s read): Bea sees only her own (' . count($nb) . ') — not another person\'s, not the system\'s');
$mar = $act(MARCO, 'recent_activity', ['limit' => 200]);
ok(array_intersect(['folder.create', 'member.sign_on', 'share.read'], array_column($mar['data']['rows'], 'action')) !== [], 'a Boards admin sees them (' . implode(', ', array_intersect(['folder.create', 'member.sign_on', 'share.read'], array_column($mar['data']['rows'], 'action'))) . ')');
$me = $act(PRIYA, 'recent_activity', ['limit' => 200]);
ok(in_array('folder.create', array_column($me['data']['rows'], 'action'), true) && in_array('member.sign_on', array_column($me['data']['rows'], 'action'), true) === true, 'and the actor sees her own');
$sr = array_values(array_filter($mar['data']['rows'], fn ($r) => $r['action'] === 'share.read'))[0] ?? null;
ok($sr !== null && $sr['member_name'] === 'the system' && $sr['source'] === 'application' && $sr['summary'] === 'the system read a share for another application', 'a share read, to the admin: by the system, a sentence');

echo "5. Nothing written is read back\n";
$all = '';
foreach ([['board_trail', ['board' => $A, 'limit' => 200]], ['record_history', ['kind' => 'comment', 'id' => $w['thread_bea']]], ['recent_activity', ['limit' => 200]], ['member_activity', ['member' => 'me', 'limit' => 200]], ['activity_search', ['q' => 'comment']], ['agent_activity', []]] as [$n, $args]) {
    $all .= mcp_tool(ACT, ptoken(MARCO), $n, $args)['text'];
}
ok(!str_contains($all, 'Is the pricing idea final') && !str_contains($all, 'Yes, it is final') && !str_contains($all, 'cluster these stickies') && !str_contains($all, 'mcp_') && !str_contains($all, need('BD_STORAGE_DIR')), 'no comment text, no token, no path in any activity answer — even to a Boards admin');
$ex = $act(PRIYA, 'board_trail', ['board' => $A, 'limit' => 5])['text'];
ok(!str_contains($ex, 'ip_address') && !str_contains($ex, 'session') && !str_contains($ex, 'request_id'), 'no address, no session, no request id');
finish();
