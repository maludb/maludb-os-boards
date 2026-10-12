<?php
/**
 * Proof — the gate: the token shapes and what each is offered (docs/boards-mcp-tool-surface.md "The run-facts gate"). A person's own mcp_ token, and the command bar's action token, see every tool but the kernel's boards_index; a made-up
 * token, a forged, an expired one, a kernel token bound to another application -> 401; an unknown member is refused, never created; an agent's RUN token is offered exactly the tools the (fake) kernel's run-facts names for THIS endpoint, and
 * FAILS CLOSED when the kernel says invalid, names no endpoint here, does not know the run, or cannot be reached (the fake kernel is stopped for a moment); an agent the directory has not admitted is admitted at first contact only when the
 * kernel vouches; an evaluation run reads and writes nothing; the kernel's own token reaches app_roles, boards_index and board_outline only. Under Apache the same servers are reached through the vhost's own /mcp/records and /mcp/activity.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);

echo "1. The surface: every tool the document names, offered to a person, annotated read-only\n";
$all = mcp_tools(REC, ptoken(PRIYA));
$acts = mcp_tools(ACT, ptoken(PRIYA));
ok(count($S['records']) === 27 && count($S['activity']) === 7 && count($S['records']) + count($S['activity']) === 34, 'the surface names 27 records tools (22 questions + app_roles + the shares) and 7 activity tools');
ok($all !== null && $all === $S['person'] && count($all) === 26, 'a person is offered the 26 records tools of the surface — everything but boards_index, which is the kernel\'s alone');
ok($acts === $S['activity'], 'and the seven activity tools');
$defs = mcp_tool_defs(REC, ptoken(PRIYA));
$ro = array_filter($defs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true && ($t['annotations']['openWorldHint'] ?? true) === false);
ok(count($ro) === count($defs) && count($defs) === 26, 'every records tool carries readOnlyHint true and openWorldHint false: nothing writes, nothing reaches outside the business');
$actDefs = mcp_tool_defs(ACT, ptoken(PRIYA));
ok(count(array_filter($actDefs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true && ($t['annotations']['openWorldHint'] ?? true) === false)) === 7, 'the activity tools are all read-only and closed-world');
$noDesc = array_filter(array_merge($defs, $actDefs), fn ($t) => strlen((string) ($t['description'] ?? '')) < 40);
ok($noDesc === [], 'every tool says what it is for (a description an agent can choose by)');
$undescribed = []; $noExtra = [];
foreach (array_merge($defs, $actDefs) as $t) {
    foreach (($t['inputSchema']['$defs'] ?? []) as $name => $d) {
        foreach (($d['properties'] ?? []) as $pn => $pv) { if (empty($pv['description'])) { $undescribed[] = "$name.$pn"; } }
        if (($d['additionalProperties'] ?? true) !== false) { $noExtra[] = $name; }
    }
}
ok($undescribed === [], 'every field of every parameter model has a description' . ($undescribed ? ': ' . implode(', ', array_slice($undescribed, 0, 5)) : ''));
ok($noExtra === [], 'every parameter model forbids extra fields (extra="forbid")');
$bad = tool(PRIYA, 'find_boards', ['limit' => 0, 'bogus' => 'SECRET-ECHO-TEST']);
ok($bad['code'] === 'invalid_argument' && $bad['data']['error']['retry'] === false && in_array('limit', $bad['data']['error']['fields'], true) && in_array('bogus', $bad['data']['error']['fields'], true) && !str_contains($bad['text'], 'SECRET-ECHO-TEST'),
    'a parameter that fails validation is an ANSWER: invalid_argument naming the fields (limit, bogus), and what was sent is not echoed');
$bad2 = tool(PRIYA, 'board_changes', ['board' => $w['roadmap'], 'since' => 'last tuesday-ish']);
ok($bad2['code'] === 'invalid_argument' && in_array('since', $bad2['data']['error']['fields'] ?? [], true), 'a time that is not a time: invalid_argument naming it');
$lim = tool(PRIYA, 'find_comments', ['limit' => 101]);
$off = tool(PRIYA, 'find_comments', ['offset' => 10001]);
ok($lim['code'] === 'invalid_argument' && $off['code'] === 'invalid_argument', 'limit 1–100 and offset 0–10,000 are ranges: past them, invalid_argument');

echo "2. Who is refused\n";
foreach ([[REC, '/mcp/records', 'records'], [ACT, '/mcp/activity', 'activity']] as [$port, , $n]) {
    ok(mcp_tools($port, '') === null, "$n: no bearer: 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a token nobody minted: 401");
    $t = rtoken(40, runid(1));
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature: 401");
    ok(mcp_tools($port, rtoken(40, runid(2), -5)) === null, "$n: an expired run token: 401");
    ok(mcp_tools($port, atoken(PRIYA, -5)) === null, "$n: an expired action token: 401");
    $p = PRIYA . '.' . (time() + 300);
    ok(mcp_tools($port, $p . '.' . hash_hmac('sha256', $p, 'not-the-key')) === null, "$n: an action token signed with another key: 401");
    ok(mcp_tools($port, atoken(9999)) === null, "$n: an action token for a member the mirror does not know: 401 (refused, never created)");
    ok(mcp_tools($port, kernel_token('someone_else')) === null, "$n: the kernel's token bound to ANOTHER application: 401");
    ok(mcp_tools($port, kernel_token('boards', -5)) === null, "$n: an expired kernel token: 401");
    ok(mcp_tools($port, ptoken(PRIYA) . 'x') === null, "$n: a token with a letter changed: 401");
}
ok((int) one('SELECT count(*) FROM members WHERE id = 9999') === 0, 'and the unknown member was not created');
ok(mcp_tools(REC, rtoken(9999, runid(5))) === null && (int) one('SELECT count(*) FROM members WHERE id = 9999') === 0, 'a run token for an unknown member: 401, nobody created');
q("UPDATE members SET status = 'inactive' WHERE id = :m", ['m' => LEE]);
ok(mcp_tools(REC, ptoken(LEE)) === null && mcp_tools(REC, atoken(LEE)) === null, 'a member the directory made inactive: both token shapes are refused');
q("UPDATE members SET status = 'active' WHERE id = :m", ['m' => LEE]);

echo "3. A person: their own token, or the signed action token of the command bar\n";
ok(mcp_tools(REC, atoken(PRIYA)) === $all && mcp_tools(REC, ptoken(MARCO)) === $all && mcp_tools(REC, ptoken(ANN)) === $all && mcp_tools(REC, ptoken(DANA)) === $all && mcp_tools(REC, atoken(OWNER)) === $all,
    'a Member (own token and action token), a Boards admin, the owner, a Viewer and an EXTERNAL Viewer get the same tool list: the world differs, the tools do not');
ok(mcp_tools(ACT, atoken(BEA)) === $S['activity'], 'the action token on the activity server: the same seven');
$r = mcp_tool(REC, atoken(BEA), 'find_boards', ['q' => 'roadmap']);
ok(!$r['error'] && count($r['data']['rows']) === 1 && $r['data']['rows'][0]['board_id'] === $w['roadmap'] && $r['data']['rows'][0]['my_level'] === 'edit', 'the action token acts as its member: Bea finds the Roadmap at edit');
$r = mcp_tool(REC, atoken(LEE), 'find_boards', ['q' => 'roadmap']);
ok(!$r['error'] && $r['data']['rows'] === [], 'and Lee, a stranger to it, finds nothing');
$r = mcp_tool(REC, ptoken(PRIYA), 'boards_index', []);
ok($r['error'] && str_contains($r['text'], 'kernel'), 'boards_index is the kernel\'s alone: a person calling it is refused (' . substr($r['text'], 0, 70) . ')');

echo "4. An agent sees exactly what the kernel granted on this endpoint\n";
$exp = agent_grants('expert');
$r1 = runid(10); facts($r1, 40, $exp);
$tok = rtoken(40, $r1);
$want = array_keys($exp['Records MCP']); sort($want);
ok(mcp_tools(REC, $tok) === $want && count($want) === 22, 'the expert\'s grants: exactly its ' . count($want) . ' granted records tools (maludb-os.json)');
$wantA = array_keys($exp['Activity MCP']); sort($wantA);
ok(mcp_tools(ACT, $tok) === $wantA && count($wantA) === 7, 'activity: exactly its ' . count($wantA) . ' (a grant on one endpoint is not a grant on the other)');
$c = mcp_tool(REC, $tok, 'find_boards', ['q' => 'roadmap']);
ok(!$c['error'] && count($c['data']['rows']) === 1 && $c['data']['rows'][0]['my_level'] === 'view', 'a granted tool answers as the agent: the Expert sees the Roadmap it was shared at view');
$c = mcp_tool(REC, $tok, 'boards_usage', []);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($c['text'], 0, 90));
ok(mcp_tool(REC, $tok, 'boards_index', [])['error'], 'a share it was not granted is refused for an agent too');
$fac = agent_grants('facilitator');
$r2 = runid(11); facts($r2, 42, $fac);
$want = array_keys($fac['Records MCP']); sort($want);
ok(mcp_tools(REC, rtoken(42, $r2)) === $want && count($want) === 10, 'the Facilitator: exactly its ' . count($want) . ' granted records tools');
$c = mcp_tool(REC, rtoken(42, $r2), 'board_overview', ['board' => $w['roadmap']]);
ok(!$c['error'] && $c['data']['board_id'] === $w['roadmap'], 'board_overview answers as the Facilitator (shared at edit)');
$c = mcp_tool(REC, rtoken(42, $r2), 'board_overview', ['board' => $w['secret']]);
ok($c['code'] === 'not_found', 'and a board it was not shared on is not_found');

echo "5. Fail closed\n";
$r = runid(20); facts($r, 40, $exp, ['valid' => false]);
$t = rtoken(40, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
ok(mcp_tool(REC, $t, 'find_boards', [])['error'], 'and cannot call anything');
$r = runid(21); facts($r, 40, []);
ok(mcp_tools(REC, rtoken(40, $r)) === [], "facts with no endpoint for this application (another application's agent): nothing offered");
ok(mcp_tools(REC, rtoken(40, runid(22))) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = runid(23); facts($r, 40, ['Records MCP' => ['find_boards' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, rtoken(40, $r)) ?? []) === 26, 'facts that say is_agent false are a person: never filtered');
$r = runid(24); facts($r, 40, $exp);
$t = rtoken(40, $r);
kernel_ctl('stop');
ok(mcp_tools(REC, $t) === [], 'the kernel cannot be reached: an admitted agent (first contact for this run) is offered nothing');
ok(mcp_tool(REC, $t, 'find_boards', [])['error'], 'and cannot call');
ok(mcp_tools(ACT, $t) === [], 'on the activity server too');
ok(count(mcp_tools(REC, ptoken(MARCO)) ?? []) === 26 && count(mcp_tools(REC, atoken(MARCO)) ?? []) === 26, "while a person's own token and the command bar's action token still work with no kernel");
kernel_ctl('start');
ok(mcp_tools(REC, rtoken(40, $r)) !== [], 'the kernel is back: the same run is offered its tools again (the failure was not cached)');

echo "6. An agent the directory has not admitted yet\n";
$unadm = WATCHER;
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => $unadm]);
ok(one('SELECT capability FROM members WHERE id = 41') === null, 'the Watcher (41) is in the mirror with no capability');
$r = runid(30); $t = rtoken($unadm, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 41') === null, '...capability still null');
facts($r, $unadm, ['Records MCP' => ['find_boards' => [], 'find_members' => []]]);
ok(mcp_tools(REC, $t) === ['find_boards', 'find_members'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 41') === 'write', '...and its capability is now write');
q('UPDATE members SET capability = NULL WHERE id = :m', ['m' => $unadm]);
$r = runid(31); facts($r, $unadm, []);
ok(mcp_tools(REC, rtoken($unadm, $r)) === null && one('SELECT capability FROM members WHERE id = 41') === null, 'facts with NO endpoint here do not admit: 401, capability still null');

echo "7. An evaluation run may read, and writes nothing\n";
$count = fn () => [(int) one('SELECT count(*) FROM activity_log'), (int) one('SELECT count(*) FROM board_ops'), (int) one('SELECT count(*) FROM comments'), (int) one('SELECT count(*) FROM notifications'), (int) one('SELECT count(*) FROM notification_outbox'),
                   (int) one('SELECT count(*) FROM board_items'), (int) one('SELECT count(*) FROM boards')];
$before = $count();
$r = runid(40); facts($r, 40, $exp, ['trigger' => 'eval']);
$te = rtoken(40, $r);
$c = mcp_tool(REC, $te, 'find_boards', ['q' => 'roadmap'], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && count($c['data']['rows']) === 1, 'an eval run (facts trigger eval, X-Eval-Run header): a read tool answers');
$c = mcp_tool(REC, $te, 'board_outline', ['board' => $w['roadmap']], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && str_contains($c['data']['outline'], 'Pricing idea'), 'and the outline, a share a person reads as themselves');
$c = mcp_tool(ACT, $te, 'board_trail', ['board' => $w['roadmap']], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && count($c['data']['rows']) > 0, 'and the activity server');
ok($before === $count(), 'nothing was written: the log, the ops, the comments, the notifications, the outbox, the items and the boards are as they were');

echo "8. The kernel's own token\n";
$kt = kernel_token();
$ktools = mcp_tools(REC, $kt);
ok($ktools === $S['kernel_tools'], 'the kernel is offered exactly app_roles, board_outline and boards_index: ' . implode(', ', $ktools ?? []));
ok(mcp_tools(ACT, kernel_token()) === [], 'and nothing on the activity server');
$c = mcp_tool(REC, kernel_token(), 'find_boards', [], true);
ok($c['error'] && str_contains($c['text'], 'reaches'), "the kernel's token calling anything else is refused: " . substr($c['text'], 0, 90));
$c = mcp_tool(REC, kernel_token(), 'app_roles', [], true);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'app_roles answers it: os.app-roles/1');

echo "9. app_roles — the catalogue (kernel db/145, plugin 0.4.0)\n";
$ar = $c['data'];
$roles = array_column($ar['roles'], null, 'key');
ok(array_keys($roles) === ['viewer', 'user', 'admin'], 'three roles in order: viewer, user (Member), admin (Boards admin)');
ok(count($ar['rights']) === 11 && count(array_filter($ar['roles'], fn ($r) => $r['is_admin'])) === 1 && $roles['admin']['is_admin'] === true, 'eleven rights, one admin role');
$dbr = q('SELECT role_key, rights FROM mcp_app_roles ORDER BY sort_order');
$same = true;
foreach ($dbr as $x) { $set = array_filter(explode(',', trim((string) $x['rights'], '{}'))); sort($set); $mine = $roles[$x['role_key']]['rights']; sort($mine); if ($set !== $mine) { $same = false; } }
ok($same, 'each role\'s rights are exactly the database\'s (mcp_app_roles)');
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$mr = array_column($manifest['roles'], null, 'key');
$agree = true;
foreach ($roles as $k => $r) { $a = $r['rights']; $b = $mr[$k]['rights']; sort($a); sort($b); if ($a !== $b || $r['capability'] !== $mr[$k]['capability']) { $agree = false; } }
ok($agree, 'and they are maludb-os.json\'s roles[] (rights and capability)');
$pr = mcp_tool(REC, ptoken(PRIYA), 'app_roles', []);
ok(!$pr['error'] && ($pr['data']['schema'] ?? '') === 'os.app-roles/1', 'a person may read the catalogue too (it is about no one)');

echo "10. The agents' grants in maludb-os.json exist\n";
$S2 = $S; $bad = [];
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true)['actions'];
foreach ($manifest['agents'] as $a) {
    foreach ((array) ($a['tool_grants']['Records MCP'] ?? []) as $tl) { if (!in_array($tl, $S['person'], true)) { $bad[] = $a['key'] . ' Records ' . $tl; } }
    foreach ((array) ($a['tool_grants']['Activity MCP'] ?? []) as $tl) { if (!in_array($tl, $S['activity'], true)) { $bad[] = $a['key'] . ' Activity ' . $tl; } }
    foreach ((array) ($a['tool_grants']['Actions MCP'] ?? []) as $tl) { if (empty($reg[$tl]['built'])) { $bad[] = $a['key'] . ' Actions ' . $tl; } }
}
ok($bad === [], 'every tool an agent is granted exists on the server it is granted on, and every granted action is a built action of the registry' . ($bad ? ' — missing: ' . implode(', ', $bad) : ''));
$fa = $fac['Actions MCP'] ?? [];
$never = array_intersect(array_keys($fa), ['board_delete', 'board_share', 'board_unshare', 'board_access_set', 'board_transfer', 'comment_delete', 'settings_save', 'template_publish', 'trash_purge', 'version_restore', 'folder_delete']);
ok($never === [] && !array_intersect(array_keys($fac['Records MCP']), ['boards_usage', 'board_access', 'agent_dispatches', 'boards_index']), 'the Facilitator is granted nothing that shares, transfers, administers or reads who-can-open — and only item_delete_batch deletes (and pauses)');
ok(isset($fa['item_delete_batch']) && ($reg['item_delete_batch']['approval'] ?? null) === 'deletion', '...which is paused by a deletion approval in the registry');

if (apache()) {
    echo "11. Through the vhost's own proxy lines (Apache)\n";
    ok(mcp_tools(VHOST, ptoken(PRIYA), [], '/mcp/records') === $S['person'], '/mcp/records on the public vhost reaches the records server');
    ok(mcp_tools(VHOST, ptoken(PRIYA), [], '/mcp/activity') === $S['activity'], '/mcp/activity reaches the activity server');
    ok(mcp_tools(VHOST, '', [], '/mcp/records') === null, '...and a call with no bearer is refused there too');
    $c = mcp_tool(VHOST, ptoken(BEA), 'find_boards', ['q' => 'roadmap'], false, [], '/mcp/records');
    ok(!$c['error'] && count($c['data']['rows']) === 1, 'a tool call through /mcp/records answers');
    $c = mcp_tool(VHOST, ptoken(BEA), 'board_trail', ['board' => $w['roadmap']], false, [], '/mcp/activity');
    ok(!$c['error'] && count($c['data']['rows']) > 0, 'and through /mcp/activity');
    $c = mcp_tool(VHOST, kernel_token(), 'app_roles', [], true, [], '/mcp/records');
    ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'the kernel\'s token through the vhost reads app_roles');
}
finish();
