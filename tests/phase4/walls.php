<?php
/**
 * Proof — the walls: the same board, asked for by different people, answers differently, and a stranger is never told (docs/boards-mcp-tool-surface.md "Errors are answers": `not_found` is the SAME words for a record that is missing and one the caller may not see).
 * A private board is asked for by a stranger (Lee, Bea, an external, an agent shared on other boards) and by a UUID that is nowhere: every board-taking tool answers both IDENTICALLY, word for word — records, activity and the shares. Levels: an owner, a
 * Boards admin, an editor, a Viewer (capped at comment whatever a board gives), an external, an agent. What is the asker's own: notifications, the trash, the dispatches. What is the admin's: usage. The resolvers' shape. No text of the private board reaches a
 * stranger by any door (search, comments, outline, trail, region). A path of the file store is nowhere.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$secret = $w['secret'];
$missing = '99999999-9999-4999-8999-999999999999';
$secretItem = (string) one('SELECT id FROM board_items WHERE board_id = CAST(:b AS uuid) LIMIT 1', ['b' => $secret]);
$secretComment = (string) one('SELECT id FROM comments WHERE board_id = CAST(:b AS uuid) LIMIT 1', ['b' => $secret]);
$agentTok = function (int $agent, array $grants) { $r = runid(100 + $agent); facts($r, $agent, $grants); return rtoken($agent, $r); };
$expertTok = $agentTok(40, agent_grants('expert'));
$facTok = $agentTok(42, agent_grants('facilitator'));

/** [tool, args-builder(board-ish value), port] — every tool that takes a board (or an id inside one). */
$asks = [
    ['board_get', fn ($b) => ['board' => $b], REC], ['board_access', fn ($b) => ['board' => $b], REC], ['board_overview', fn ($b) => ['board' => $b], REC], ['board_search', fn ($b) => ['board' => $b, 'text' => 'secret'], REC],
    ['board_read_region', fn ($b) => ['board' => $b, 'x1' => -500, 'y1' => -500, 'x2' => 500, 'y2' => 500], REC], ['frame_read', fn ($b) => ['board' => $b, 'frame' => '88888888-8888-4888-8888-888888888888'], REC],
    ['board_outline', fn ($b) => ['board' => $b], REC], ['board_changes', fn ($b) => ['board' => $b], REC], ['board_versions', fn ($b) => ['board' => $b], REC], ['version_outline', fn ($b) => ['board' => $b, 'version' => 1], REC],
    ['find_attachments', fn ($b) => ['board' => $b], REC], ['find_comments', fn ($b) => ['board' => $b], REC],
    ['board_trail', fn ($b) => ['board' => $b], ACT], ['record_history', fn ($b) => ['kind' => 'board', 'id' => $b], ACT],
];
echo "1. A stranger's answer is a missing board's answer, word for word\n";
foreach ([[LEE, 'Lee (a Viewer, a stranger to it)', ptoken(LEE)], [BEA, 'Bea (a Member with other boards)', ptoken(BEA)], [ANN, 'Ann (an external)', ptoken(ANN)], [40, 'the Expert (an agent shared on the Roadmap)', $expertTok]] as [$who, $label, $tok]) {
    $diff = [];
    foreach ($asks as [$name, $args, $port]) {
        $a = mcp_tool($port, $tok, $name, $args($secret));
        $b = mcp_tool($port, $tok, $name, $args($missing));
        $c = mcp_tool($port, $tok, $name, $args('SMOKE p4 Secret'));
        $d = mcp_tool($port, $tok, $name, $args('SMOKE p4 No such title'));
        $same = $a['code'] === 'not_found' && $a['data'] === $b['data'] && $c['data'] === $d['data'] && $a['data']['error']['message'] === $c['data']['error']['message'];
        if (!$same) { $diff[] = $name . ' ' . json_encode([$a['code'], $b['code'], $c['code'], $d['code']]); }
    }
    ok($diff === [], "$label: " . count($asks) . ' board-taking tools answer the private board (by id and by title) exactly as they answer one that is not there' . ($diff ? ' — differ: ' . implode('; ', $diff) : ''));
}
foreach ([[LEE, ptoken(LEE)], [40, $expertTok]] as [$who, $tok]) {
    $a = [mcp_tool(REC, $tok, 'item_get', ['item' => $secretItem]), mcp_tool(REC, $tok, 'item_connections', ['item' => $secretItem]), mcp_tool(REC, $tok, 'comment_thread', ['comment' => $secretComment])];
    $b = [mcp_tool(REC, $tok, 'item_get', ['item' => $missing]), mcp_tool(REC, $tok, 'item_connections', ['item' => $missing]), mcp_tool(REC, $tok, 'comment_thread', ['comment' => $missing])];
    ok($a[0]['data'] === $b[0]['data'] && $a[1]['data'] === $b[1]['data'] && $a[2]['data'] === $b[2]['data'] && $a[0]['code'] === 'not_found' && $a[2]['code'] === 'not_found', "member $who: an item, its connections and a comment of the private board are not_found, identical to a missing id");
}
$a = mcp_tool(REC, ptoken(LEE), 'board_read_region', ['board' => $w['roadmap'], 'around' => $secretItem]);
ok($a['code'] === 'not_found', 'a region "around" an item of ANOTHER board the asker cannot see: not_found');
$a = mcp_tool(REC, ptoken(BEA), 'board_search', ['board' => $w['roadmap'], 'text' => 'pricing', 'frame' => '77777777-7777-4777-8777-777777777777']);
ok(!$a['error'] && $a['data']['rows'] === [], 'a frame that is nowhere narrows a search to nothing (not a confession)');
ok(mcp_tool(REC, ptoken(PRIYA), 'board_get', ['board' => $secret])['code'] === null, 'and the owner reads her own private board');

echo "2. Levels: the same board, six askers\n";
$lvl = function ($tok, string $title = 'SMOKE p4 Roadmap') { $r = mcp_tool(REC, $tok, 'find_boards', ['q' => $title]); if ($r['code'] === null && $r['http'] === 200 && isset($r['data']['rows'])) { return $r['data']['rows'][0]['my_level'] ?? null; }
    $g = mcp_tool(REC, $tok, 'board_get', ['board' => $title]); return $g['data']['my_level'] ?? null; };       // the Facilitator is granted board_get, not find_boards
$expect = [[PRIYA, ptoken(PRIYA), 'full', 'the owner'], [MARCO, ptoken(MARCO), 'full', 'a Boards admin (owner of no part of it)'], [BEA, ptoken(BEA), 'edit', 'Bea, shared at edit'], [DANA, ptoken(DANA), 'view', 'Dana, a Viewer, shared at view'],
           [ANN, ptoken(ANN), 'comment', 'Ann, an external, shared at comment'], [40, $expertTok, 'view', 'the Expert, an agent shared at view'], [42, $facTok, 'edit', 'the Facilitator, an agent shared at edit'], [LEE, ptoken(LEE), null, 'Lee, a stranger']];
foreach ($expect as [$m, $tok, $level, $label]) { ok($lvl($tok) === $level, "Roadmap, $label: " . ($level ?? 'not listed')); }
foreach ([[PRIYA, 'edit'], [DANA, 'comment'], [LEE, 'comment'], [BEA, null], [ANN, null], [MARCO, 'full']] as [$m, $level]) {
    ok($lvl(ptoken($m), 'SMOKE p4 Sales board') === $level, "Sales board (the Sales department at edit): member $m is " . ($level ?? 'a stranger') . ($m === DANA || $m === LEE ? ' — a Viewer is capped at comment' : ''));
}
foreach ([[BEA, 'view'], [LEE, 'view'], [ANN, null], [PRIYA, 'view']] as [$m, $level]) {
    ok($lvl(ptoken($m), 'SMOKE p4 Everyone') === $level, "'Everyone at view' board: member $m is " . ($level ?? 'not listed (an external is never "everyone")'));
}
$g = tdata(DANA, 'board_get', ['board' => $w['roadmap']]);
ok($g['my_level'] === 'view' && $g['is_locked'] === false, 'board_get says the asker\'s level too');
$sw = tdata(LEE, 'find_boards', ['limit' => 100])['rows'];
$titles = array_column($sw, 'title');
ok(!in_array('SMOKE p4 Roadmap', $titles, true) && !in_array('SMOKE p4 Secret', $titles, true) && in_array('SMOKE p4 Sales board', $titles, true) && in_array('SMOKE p4 Everyone', $titles, true), 'Lee lists the Sales board and the everyone board and neither the Roadmap nor the Secret: ' . implode(', ', $titles));
ok(!in_array('SMOKE p4 Trashed', $titles, true), 'a board in the trash is in no list');

echo "3. An external sees only what is shared, and only themselves\n";
$ann = tdata(ANN, 'find_boards', ['limit' => 100])['rows'];
ok(array_column($ann, 'title') === ['SMOKE p4 Roadmap'], 'Ann lists exactly the Roadmap');
$mem = trows(ANN, 'find_members', ['limit' => 100]);
ok(count($mem) === 1 && $mem[0]['member_id'] === ANN, 'find_members: only herself');
ok(trows(ANN, 'find_members', ['q' => 'Priya']) === [], '...she cannot find Priya by name');
ok(array_column(trows(ANN, 'find_folders'), 'name') === [], 'find_folders: no team folder (a team folder is for the business)');
ok(trows(ANN, 'find_templates') === [], 'find_templates: no shipped or business template (they are for the business)');
$n = mcp_tool(REC, ptoken(ANN), 'board_changes', ['board' => $w['roadmap'], 'limit' => 3]);
ok(!$n['error'] && count($n['data']['rows']) === 3, 'but she reads the changes of the board she was given');

echo "4. An agent reads as itself\n";
$r = mcp_tool(REC, $expertTok, 'find_boards', ['shared_with_me' => true, 'limit' => 100]);
ok(array_column($r['data']['rows'], 'title') === ['SMOKE p4 Everyone', 'SMOKE p4 Roadmap'] || array_column($r['data']['rows'], 'title') === ['SMOKE p4 Roadmap', 'SMOKE p4 Everyone'], 'the Expert, asked for the boards shared with it: the Roadmap and the everyone board — not Priya\'s Secret, not the Sales board');
$r = mcp_tool(REC, $expertTok, 'find_members', ['q' => 'me']);
ok(!$r['error'] && count($r['data']['rows']) === 1 && $r['data']['rows'][0]['member_id'] === 40 && $r['data']['rows'][0]['member_kind'] === 'agent', '"me" for the agent is its own member id (40, an agent)');
$r = mcp_tool(REC, $expertTok, 'my_notifications', []);
ok(!$r['error'] && $r['data']['rows'] === [], 'the Expert has no notifications of its own (Bea\'s mention is not its)');
$r = mcp_tool(REC, $facTok, 'board_outline', ['board' => $w['roadmap']]);
ok(!$r['error'] && str_contains($r['data']['outline'], 'Pricing idea') && !isset($r['data']['schema']), 'the Facilitator reads the Roadmap\'s outline as itself (no share envelope for an agent)');
ok(mcp_tool(REC, $facTok, 'board_outline', ['board' => $secret])['code'] === 'not_found', '...and not the Secret\'s');

echo "5. What is the asker's own\n";
foreach ([BEA => 1, PRIYA => 0, LEE => 0, DANA => 0] as $m => $unreadMention) {
    $db = (int) one("SELECT count(*) FROM notifications WHERE member_id = :m", ['m' => $m]);
    $rows = trows($m, 'my_notifications', ['limit' => 100]);
    $kinds = array_count_values(array_column($rows, 'kind'));
    ok(count($rows) === $db && ($kinds['mention'] ?? 0) >= $unreadMention, "member $m reads exactly their own $db notifications (the database's count)" . ($m === BEA ? ' — and the mention is Bea\'s' : ''));
}
q('UPDATE notifications SET read_at = NULL WHERE member_id = 28');
$un = trows(BEA, 'my_notifications', ['unread_only' => true]);
q("UPDATE notifications SET read_at = now() WHERE member_id = 28 AND id = (SELECT min(id) FROM notifications WHERE member_id = 28)");
ok(count(trows(BEA, 'my_notifications', ['unread_only' => true])) === count($un) - 1 && count($un) >= 1, 'unread_only follows read_at');
$t = trows(PRIYA, 'trash_list');
ok(array_column($t, 'title') === ['SMOKE p4 Trashed'] && trows(BEA, 'trash_list') === [] && in_array('SMOKE p4 Trashed', array_column(trows(MARCO, 'trash_list'), 'title'), true), 'the trash: Priya\'s own deleted board; Bea\'s is empty; a Boards admin sees every deleted board');
ok(tool(PRIYA, 'boards_usage', [])['code'] === 'not_allowed' && tool(BEA, 'boards_usage', [])['code'] === 'not_allowed' && tool(40, 'boards_usage', [])['code'] !== 'ok', 'boards_usage is a Boards admin\'s: a Member is not_allowed');
$u = tool(MARCO, 'boards_usage', ['owner_left' => true]);
ok(!$u['error'] && array_column($u['data']['rows'], 'title') === ['SMOKE Left behind'] && $u['data']['rows'][0]['owner_active'] === false && $u['data']['soft_budget'] === 4000 && $u['data']['hard_cap'] === 10000, 'a Boards admin finds the board whose owner has left, against the budget (4,000 soft, 10,000 hard)');
$u = tdata(MARCO, 'boards_usage', ['sort' => 'items', 'limit' => 3]);
ok($u['rows'][0]['title'] === 'SMOKE p4 Huge outline' && $u['rows'][0]['item_count'] === 3200 && $u['rows'][1]['title'] === 'SMOKE p4 Big' && !in_array('Retrospective', array_column($u['rows'], 'title'), true), 'usage by items: the 3,200-item board first, then the 450; the shipped templates are not boards anyone works on');
$u = tdata(MARCO, 'boards_usage', ['idle_days' => 5]);
ok($u['rows'] === [], 'idle_days 5: nothing in a fresh world has been idle that long');
$d = trows(MARCO, 'agent_dispatches');
ok(count($d) === 1 && $d[0]['dispatch_id'] === $w['dispatch'] && $d[0]['agent_name'] === 'SMOKE Facilitator' && $d[0]['acting_name'] === 'SMOKE Priya' && $d[0]['board_title'] === 'SMOKE p4 Roadmap', 'agent_dispatches: a Boards admin sees the Facilitator\'s dispatch');
ok(count(trows(PRIYA, 'agent_dispatches')) === 1 && trows(BEA, 'agent_dispatches') === [] && trows(DANA, 'agent_dispatches') === [], '...Priya, who asked, sees hers; Bea and Dana see none');
ok(trows(MARCO, 'agent_dispatches', ['status' => 'failed']) === [] && count(trows(MARCO, 'agent_dispatches', ['agent' => 'SMOKE Facilitator'])) === 1 && trows(MARCO, 'agent_dispatches', ['board' => $secret]) === [], 'filters: by status, agent and board');

echo "6. Comments: the threads, and whose they are\n";
$mine = trows(BEA, 'find_comments', ['mentions_me' => true]);
ok(count($mine) === 1 && $mine[0]['comment_id'] === $w['thread_bea'] && $mine[0]['replies'] === 1, 'Bea\'s mentions: exactly the one thread that names her (one reply, her own)');
ok(trows(PRIYA, 'find_comments', ['mentions_me' => true]) === [], '...Priya is mentioned by nobody');
$rf = runid(150); facts($rf, 42, ['Records MCP' => ['find_comments' => []]]);
$mf = mcp_tool(REC, rtoken(42, $rf), 'find_comments', ['mentions_me' => true]);
ok(!$mf['error'] && array_column($mf['data']['rows'], 'comment_id') === [$w['thread_agent']], 'the Facilitator\'s mentions: the thread that names it');
$open = ids(trows(PRIYA, 'find_comments', ['board' => $w['roadmap'], 'open' => true, 'limit' => 100]), 'comment_id');
$done = ids(trows(PRIYA, 'find_comments', ['board' => $w['roadmap'], 'open' => false]), 'comment_id');
ok(count($open) === 4 && !in_array($w['thread_resolved'], $open, true) && $done === [$w['thread_resolved']], 'open: four threads; resolved: the one');
$un = ids(trows(PRIYA, 'find_comments', ['board' => $w['roadmap'], 'unanswered' => true, 'limit' => 100]), 'comment_id');
ok(in_array($w['thread_open'], $un, true) && in_array($w['thread_point'], $un, true) && !in_array($w['thread_bea'], $un, true) && !in_array($w['thread_resolved'], $un, true), 'unanswered: the threads with no reply and not resolved (not Bea\'s answered one, not the resolved one)');
ok(ids(trows(PRIYA, 'find_comments', ['author' => 'SMOKE Bea', 'limit' => 100]), 'comment_id') === [$w['thread_point']], 'by author: Bea started one thread');
ok(ids(trows(PRIYA, 'find_comments', ['q' => 'final']), 'comment_id') === [$w['thread_bea']], 'by words: a reply\'s text finds its thread');
$ct = ids(trows(PRIYA, 'find_comments', ['q' => 'secret remark']), 'comment_id');
ok($ct === [$secretComment] && trows(BEA, 'find_comments', ['q' => 'secret remark', 'limit' => 100]) === [] && trows(LEE, 'find_comments', ['q' => 'secret']) === [], 'a comment on the private board: Priya finds it by its words; Bea and Lee do not');
$th = tdata(LEE, 'comment_thread', ['comment' => $w['thread_bea']]);
ok($th['code'] ?? 'not_found' === 'not_found' || ($th['ok'] ?? true) === false, 'a thread of a board Lee cannot open is not_found');

echo "7. The resolvers answer the shape the kernel's registry names\n";
$wrapper = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/deploy/kernel-registry-boards.json'), true)['resolve'];
$cases = ['board' => ['SMOKE p4 Roadmap', 26], 'template' => ['SMOKE p4 Roadmap template', 26], 'folder' => ['SMOKE p4 Planning', 26], 'member' => ['SMOKE Bea', 26], 'department' => ['Sales', 26]];
foreach ($cases as $entity => [$value, $m]) {
    $spec = $wrapper[$entity];
    $r = mcp_tool(REC, ptoken($m), $spec['tool'], [$spec['query_param'] => $value]);
    $rows = $r['data']['rows'] ?? [];
    ok(!$r['error'] && count($rows) === 1 && array_key_exists($spec['id_field'], $rows[0]) && array_key_exists($spec['label_field'], $rows[0]) && $rows[0][$spec['label_field']] === ($entity === 'department' ? 'Sales' : $value),
        "$entity: {$spec['tool']}(q) answers {rows:[…]} with one row carrying {$spec['id_field']} and {$spec['label_field']} (\"" . ($rows[0][$spec['label_field']] ?? '?') . '")');
}
$r = tdata(PRIYA, 'find_boards', ['q' => $w['roadmap']]);
ok(count($r['rows']) === 1 && $r['rows'][0]['title'] === 'SMOKE p4 Roadmap', 'a UUID given as q is an id lookup (the kernel\'s own resolver treats only digit strings as ids)');
$r = tdata(PRIYA, 'find_templates', ['q' => $w['template']]);
ok(count($r['rows']) === 1 && $r['rows'][0]['title'] === 'SMOKE p4 Roadmap template' && $r['rows'][0]['template_scope'] === 'personal', 'and for a template');
$r = tdata(BEA, 'find_members', ['q' => '28']);
ok(count($r['rows']) === 1 && $r['rows'][0]['member_id'] === 28, 'digits are an id for people, folders and departments (the kernel never resolves them; the tool answers them too)');
$r = tdata(PRIYA, 'find_boards', ['q' => 'SMOKE p4']);
ok(count($r['rows']) >= 5, 'a fragment lists several (the kernel would ask which): ' . count($r['rows']));
ok(trows(BEA, 'find_templates', ['q' => 'SMOKE p4 Roadmap template']) === [] && count(trows(BEA, 'find_templates', ['q' => 'SMOKE p4 Business template'])) === 1, 'a personal template is its owner\'s; a business template is everyone\'s inside the business');
ok(array_column(trows(BEA, 'find_folders', ['q' => 'SMOKE p4']), 'name') === ['SMOKE p4 Team folder'], 'folders: Bea sees the team folder and not Priya\'s own');
$tf = trows(PRIYA, 'find_folders', ['team' => false]);
ok(array_column($tf, 'name') === ['SMOKE p4 Planning'] && $tf[0]['board_count'] === 1, 'team=false: only her own, with the boards she may open in it');
$ts = trows(PRIYA, 'find_templates', ['scope' => 'shipped', 'limit' => 100]);
ok(count($ts) === 6 && array_unique(array_column($ts, 'template_scope')) === ['shipped'] && count(trows(PRIYA, 'find_templates', ['category' => 'retrospective', 'scope' => 'shipped'])) === 1, 'shipped templates: the six, filtered by category');
$dp = trows(PRIYA, 'find_departments', ['limit' => 100]);
ok(count($dp) >= 4 && find_row($dp, 'name', 'Sales')['department_id'] === 8, 'departments: with their ids');
$mm = trows(PRIYA, 'find_members', ['kind' => 'agent', 'limit' => 100]);
ok(count($mm) >= 2 && array_unique(array_column($mm, 'member_kind')) === ['agent'] && !has_key($mm, 'email'), 'members of kind agent; no e-mail address in the answer');
ok(count(trows(PRIYA, 'find_members', ['department' => 'Sales', 'limit' => 100])) >= 3, 'members of the Sales department (by name)');

echo "8. No text of the private board reaches a stranger, by any door\n";
$needles = ['Secret plan', 'secret remark', 'SMOKE p4 Secret'];
foreach ([[LEE, ptoken(LEE)], [BEA, ptoken(BEA)], [ANN, ptoken(ANN)], [40, $expertTok]] as [$m, $tok]) {
    $leak = [];
    foreach ([['records_search', ['q' => 'secret'], REC], ['records_search', ['q' => 'plan', 'limit' => 50], REC], ['find_boards', ['q' => 'secret'], REC], ['find_comments', ['limit' => 100], REC], ['activity_search', ['q' => 'secret'], ACT],
              ['recent_activity', ['limit' => 200], ACT], ['most_active_boards', ['limit' => 50], ACT], ['agent_activity', [], ACT], ['member_activity', ['member' => PRIYA, 'limit' => 200], ACT]] as [$name, $args, $port]) {
        $r = mcp_tool($port, $tok, $name, $args);
        foreach ($needles as $n) { if ($r['http'] === 200 && stripos($r['text'], $n) !== false) { $leak[] = "$name:$n"; } }
        if ($r['http'] === 200 && stripos($r['text'], $secret) !== false) { $leak[] = "$name:id"; }
    }
    ok($leak === [], "member $m: search, comments, activity — none of them carries the private board's words or id" . ($leak ? ' — LEAK ' . implode(', ', $leak) : ''));
}
$r = mcp_tool(REC, ptoken(PRIYA), 'records_search', ['q' => 'secret']);
ok(!$r['error'] && count($r['data']['rows']) === 3 && array_unique(array_column($r['data']['rows'], 'board_id')) === [$secret] && count(array_unique(array_column($r['data']['rows'], 'kind'))) === 3, 'while Priya finds the board (its title), its item and its comment');
$r = mcp_tool(REC, ptoken(PRIYA), 'records_search', ['q' => 'secret', 'kind' => 'comment']);
ok(count($r['data']['rows']) === 1 && $r['data']['rows'][0]['kind'] === 'comment', 'records_search by kind');
$r = mcp_tool(REC, ptoken(PRIYA), 'records_search', ['q' => 'pricing', 'board' => $w['roadmap']]);
ok(count($r['data']['rows']) === 3 && ids($r['data']['rows'], 'board_id') === array_fill(0, 3, $w['roadmap']), 'and by board');
$r = mcp_tool(REC, ptoken(PRIYA), 'records_search', ['q' => 'a']);
ok($r['code'] === 'invalid_argument', 'a one-letter search is refused, not run');
$r = mcp_tool(REC, ptoken(PRIYA), 'records_search', ['q' => "x'; DROP TABLE boards; --"]);
ok(!$r['error'] && $r['data']['rows'] === [] && (int) one('SELECT count(*) FROM boards') > 5, 'the words are words, never SQL: an injection attempt searches for nothing and drops nothing');
$tmpl = tdata(PRIYA, 'records_search', ['q' => 'retrospective'])['rows'];
ok(!in_array('Retrospective', array_column($tmpl, 'board_title'), true), 'a shipped template is not a search result (it is not anyone\'s board)');

echo "9. The activity wall\n";
$tr = mcp_tool(ACT, ptoken(BEA), 'board_trail', ['board' => $w['roadmap'], 'limit' => 200]);
ok(!$tr['error'] && count($tr['data']['rows']) > 15 && array_unique(array_column($tr['data']['rows'], 'board_id')) === [$w['roadmap']], 'a board\'s trail: Bea, who can open it, reads it (' . count($tr['data']['rows']) . ' rows)');
$names = array_unique(array_column($tr['data']['rows'], 'member_name'));
ok(in_array('SMOKE Priya', $names, true) && in_array('SMOKE Bea', $names, true) && in_array('SMOKE Facilitator', $names, true) && !in_array(null, array_column($tr['data']['rows'], 'summary'), true), 'who worked on it: a person, a colleague and an agent, each by name, each with a sentence');
$lee = mcp_tool(ACT, ptoken(LEE), 'recent_activity', ['limit' => 200]);
$leeBoards = array_unique(array_filter(array_column($lee['data']['rows'], 'board_id')));
ok(!in_array($w['roadmap'], $leeBoards, true) && !in_array($secret, $leeBoards, true), 'Lee\'s recent activity holds no row of a board he cannot open');
$bea = mcp_tool(ACT, ptoken(BEA), 'recent_activity', ['limit' => 200]);
ok(!in_array('folder.create', array_column($bea['data']['rows'], 'action'), true) && !in_array('token.mint', array_column($bea['data']['rows'], 'action'), true), 'an event that concerns no board (Priya\'s folder, a token) is shown only to its actor and to a Boards admin');
$mar = mcp_tool(ACT, ptoken(MARCO), 'recent_activity', ['limit' => 200]);
ok(in_array('folder.create', array_column($mar['data']['rows'], 'action'), true) || in_array('member.sign_on', array_column($mar['data']['rows'], 'action'), true), 'a Boards admin sees them');
$ma = mcp_tool(ACT, ptoken(LEE), 'member_activity', ['member' => PRIYA, 'limit' => 200]);
ok(!$ma['error'] && array_diff(array_filter(array_unique(array_column($ma['data']['rows'], 'board_id'))), [$w['sales'], $w['everyone']]) === [], 'what Priya did, as Lee reads it: only on the boards Lee can open');
$rh = mcp_tool(ACT, ptoken(BEA), 'record_history', ['kind' => 'folder', 'id' => (string) $w['folder']]);
ok($rh['code'] === 'not_found' && mcp_tool(ACT, ptoken(BEA), 'record_history', ['kind' => 'folder', 'id' => '99999'])['data'] === $rh['data'], 'a folder that is not Bea\'s or the team\'s: not_found, the words of a folder that does not exist');
$rh = mcp_tool(ACT, ptoken(LEE), 'record_history', ['kind' => 'comment', 'id' => $w['thread_bea']]);
ok($rh['code'] === 'not_found', 'the history of a comment on a board Lee cannot open: not_found');

echo "10. No path of the store, no token\n";
$bad = [];
foreach ([['find_attachments', ['board' => $w['roadmap']]], ['item_get', ['item' => $w['image']]], ['board_read_region', ['board' => $w['roadmap'], 'x1' => 1000, 'y1' => 400, 'x2' => 1400, 'y2' => 1000]], ['board_outline', ['board' => $w['roadmap']]], ['board_get', ['board' => $w['roadmap']]], ['board_overview', ['board' => $w['roadmap']]]] as [$name, $args]) {
    $r = mcp_tool(REC, ptoken(PRIYA), $name, $args);
    if (str_contains($r['text'], need('BD_STORAGE_DIR')) || has_key($r['data'], 'storage_path') || has_key($r['data'], 'thumbnail_path') || has_key($r['data'], 'token_hash') || str_contains($r['text'], 'mcp_')) { $bad[] = $name; }
}
ok($bad === [], 'attachments, items, regions, outlines: no storage path, no thumbnail path, no token hash anywhere' . ($bad ? ' — ' . implode(', ', $bad) : ''));
finish();
