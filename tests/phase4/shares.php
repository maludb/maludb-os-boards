<?php
/**
 * Proof — the two shares[] as TOOLS (design §4, D14; K7): boards_index (os.boards-index/1) and board_outline (os.boards-outline/1), called through the records server as the KERNEL's token (flat arguments, as the kernel sends them; who asks is the
 * header X-OS-Consumer-Agent, K26 — the expert AGENT of the consuming application, never an argument) and as a PERSON or an agent for board_outline (they read as themselves). Both are SQL, so they read the database directly: every document equals,
 * row for row, what the views and bd_board_outline() answer for the SAME member. No agent named -> the index is empty with a note and the outline refused; a member that is not an agent, an agent not admitted -> refused. An `as_agent`/`member` argument
 * is never honoured. A kernel call logs `share.read` (source application, no actor, the consumer, the tool, the argument KEYS, the rows, the agent) — never a title; a person's call logs nothing. bd_log_mcp_read() refuses anything else.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);
$missing = '99999999-9999-4999-8999-999999999999';
/** The kernel's call: flat arguments, the consumer and (unless null) its expert agent in the headers the kernel adds. */
function kcall(string $tool, array $args, ?int $agent = 40, string $consumer = 'spaces', ?string $request = 'req-p4-1', int $port = REC, string $path = '/mcp'): array
{
    $h = ['X-OS-Consumer: ' . $consumer];
    if ($agent !== null) { $h[] = 'X-OS-Consumer-Agent: ' . $agent; }
    if ($request !== null) { $h[] = 'X-Request-Id: ' . $request; }
    return mcp_tool($port, kernel_token(), $tool, $args, true, $h, $path);
}
function share_rows(int $since): array { return q("SELECT * FROM activity_log WHERE action = 'share.read' AND id > :s ORDER BY id", ['s' => $since]); }

echo "1. The manifest, the surface and the server agree on the two\n";
$spec = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$tools = array_column($spec['shares'], 'tool'); sort($tools);
ok($tools === $S['shares'] && count($tools) === 2 && $spec['reads'] === [], 'maludb-os.json shares[] = boards_index and board_outline (reads[] empty in v1)');
ok(count(array_diff($S['shares'], mcp_tools(REC, kernel_token()) ?? [])) === 0, 'the kernel is offered both');
ok(in_array('board_outline', mcp_tools(REC, ptoken(PRIYA)) ?? [], true) && !in_array('boards_index', mcp_tools(REC, ptoken(PRIYA)) ?? [], true), 'a person is offered board_outline (they read as themselves) and not boards_index');

echo "2. boards_index as the kernel: the boards the consumer's agent may open\n";
$last = (int) one('SELECT COALESCE(max(id), 0) FROM activity_log');
$k = kcall('boards_index', ['limit' => 100], 42);
$d = $k['data'];
$t42 = array_column($d['rows'] ?? [], 'title'); sort($t42);
ok(!$k['error'] && $d['schema'] === 'os.boards-index/1' && $d['application'] === 'boards' && $d['as_member'] === 42 && $d['count'] === 3 && $t42 === array_column(sql_as(42, 'SELECT title FROM mcp_boards WHERE NOT is_template ORDER BY title'), 'title') && $t42 === ['SMOKE p4 Everyone', 'SMOKE p4 Replay', 'SMOKE p4 Roadmap'],
    'flat arguments, the agent named: os.boards-index/1, as_member 42 (the Facilitator) — the Roadmap and the Replay board shared with it and the everyone board, as the view shows member 42');
$row = find_row($d['rows'], 'board_id', $w['roadmap']);
ok(array_keys($row) === ['board_id', 'title', 'owner_name', 'item_count', 'updated_at', 'folder_names', 'url'] && $row['title'] === 'SMOKE p4 Roadmap' && $row['owner_name'] === 'SMOKE Priya' && $row['item_count'] === 18 && $row['folder_names'] === [] && str_ends_with($row['url'], '/boards/' . $w['roadmap']),
    'a row: board_id, title, owner_name, item_count, updated_at, folder_names (the agent sees none of Priya\'s own folders), url');
$k40 = kcall('boards_index', ['limit' => 100], 40)['data'];
$view40 = array_column(sql_as(40, 'SELECT title FROM mcp_boards WHERE NOT is_template ORDER BY title'), 'title');
ok($k40['as_member'] === 40 && array_column($k40['rows'], 'title') !== [] && (function () use ($k40, $view40) { $t = array_column($k40['rows'], 'title'); sort($t); return $t === $view40; })() && count($view40) >= 2, 'another agent, another world: the Expert is shared the Roadmap and everyone-at-view — and the index is the view\'s rows for member 40 exactly: ' . implode(', ', $view40));
$k99 = kcall('boards_index', ['q' => 'roadmap'], 40)['data'];
ok($k99['count'] === 1 && $k99['rows'][0]['board_id'] === $w['roadmap'], 'q searches titles');
$kl = kcall('boards_index', ['limit' => 1], 40)['data'];
ok($kl['count'] === 1 && $kl['truncated'] === true && $kl['rows'][0]['updated_at'] >= ($k40['rows'][1]['updated_at'] ?? ''), 'limit 1, truncated, newest activity first');
$titles = array_column($k40['rows'], 'title');
ok(!in_array('SMOKE p4 Secret', $titles, true) && !in_array('SMOKE p4 Trashed', $titles, true) && !array_filter($k40['rows'], fn ($r) => str_contains($r['title'], 'template')) && !has_key($k40, 'owner_member_id') && !has_key($k40, 'email'), 'no private board, no trashed board, no template, no id of a person, no e-mail');
$none = kcall('boards_index', ['q' => 'roadmap'], null);
ok(!$none['error'] && $none['data']['rows'] === [] && $none['data']['count'] === 0 && $none['data']['as_member'] === null && str_contains($none['data']['note'], 'No requesting expert agent'), 'no agent named: the index is EMPTY (an application with no expert agent reads nothing), with a note');
foreach ([[26, 'a person'], [9999, 'a member that does not exist'], [41, 'an agent the directory has not admitted']] as [$a, $what]) {
    if ($a === 41) { q('UPDATE members SET capability = NULL WHERE id = 41'); }
    $r = kcall('boards_index', ['q' => 'x'], $a);
    ok($r['code'] === 'not_allowed' && str_contains($r['data']['error']['message'], 'not an active agent member'), "X-OS-Consumer-Agent naming $what: refused, in words (" . substr($r['data']['error']['message'] ?? $r['text'], 0, 70) . ')');
}
$r = mcp_tool(REC, kernel_token(), 'boards_index', ['q' => 'roadmap'], true, ['X-OS-Consumer: spaces', 'X-OS-Consumer-Agent: abc']);
ok(!$r['error'] && $r['data']['as_member'] === null && $r['data']['rows'] === [], 'an X-OS-Consumer-Agent that is not a member id is ignored by the gate: no agent, the index empty');
$r = kcall('boards_index', ['q' => 'roadmap', 'as_agent' => 26, 'member' => 26, 'consumer_agent_id' => 26], 40);
ok(!$r['error'] && $r['data']['as_member'] === 40 && $r['data']['count'] === 1, 'an as_agent / member argument is never honoured: the header decides (the kernel strips it, the gate drops it again)');
$r = kcall('boards_index', ['bogus' => 1], 40);
ok($r['code'] === 'invalid_argument' && in_array('bogus', $r['data']['error']['fields'], true), 'an unknown argument is refused, named');

echo "3. board_outline as the kernel\n";
$o = kcall('board_outline', ['board' => $w['roadmap']], 40);
$od = $o['data'];
ok(!$o['error'] && $od['schema'] === 'os.boards-outline/1' && $od['application'] === 'boards' && $od['as_member'] === 40 && $od['board_id'] === $w['roadmap'] && $od['title'] === 'SMOKE p4 Roadmap', 'os.boards-outline/1, application boards, as_member 40');
ok($od['outline'] === val_as(40, "SELECT bd_board_outline(CAST('{$w['roadmap']}' AS uuid))"), 'the outline is bd_board_outline() as the Expert: byte for byte');
ok(kcall('board_outline', ['board' => 'SMOKE p4 Roadmap'], 40)['data']['board_id'] === $w['roadmap'], 'named by its title');
$o2 = kcall('board_outline', ['board' => $w['roadmap'], 'as_agent' => 26], 42);
ok(!$o2['error'] && $o2['data']['as_member'] === 42, 'as_agent is not honoured here either');
$s1 = kcall('board_outline', ['board' => $w['secret']], 40);
$s2 = kcall('board_outline', ['board' => $missing], 40);
ok($s1['code'] === 'not_found' && $s1['data'] === $s2['data'], 'a board the consumer\'s agent cannot open: not_found, word for word the answer for a board that is not there');
$tr = kcall('board_outline', ['board' => $w['trashed']], 40);
ok($tr['code'] === 'not_found', 'a board in the trash: not_found');
$x = kcall('board_outline', ['board' => $w['roadmap']], null);
ok($x['code'] === 'not_allowed' && str_contains($x['data']['error']['message'], 'No requesting expert agent'), 'no agent named: refused');
$x = kcall('board_outline', ['board' => $w['roadmap']], 26);
ok($x['code'] === 'not_allowed', 'a person named as the agent: refused');
$x = kcall('board_outline', ['board' => $w['huge']], 42);
ok($x['code'] === 'not_found', 'a board the agent cannot open is not_found even when it is huge');
share_as(26, $w['huge'], 'agent', 40, 'view');
$x = kcall('board_outline', ['board' => $w['huge']], 40);
ok($x['code'] === 'too_large', 'over 256 kB: too_large');
$x = kcall('board_outline', [], 40);
ok($x['code'] === 'invalid_argument' && in_array('board', $x['data']['error']['fields'], true), 'no board: invalid_argument');
$pe = mcp_tool(REC, ptoken(PRIYA), 'board_outline', ['board' => $w['roadmap'], 'as_agent' => 40]);
ok($pe['code'] === 'invalid_argument', 'a person naming as_agent: the model forbids the extra field (it is the kernel\'s, never theirs)');

echo "4. A person or an agent reads the outline as themselves\n";
$ex = runid(60); facts($ex, 40, agent_grants('expert'));
$ag = mcp_tool(REC, rtoken(40, $ex), 'board_outline', ['board' => $w['roadmap']]);
ok(!$ag['error'] && !isset($ag['data']['schema']) && $ag['data']['outline'] === $od['outline'], 'an agent granted board_outline reads the same words as itself — and gets no share envelope');
ok(mcp_tool(REC, ptoken(DANA), 'board_outline', ['board' => $w['roadmap']])['data']['outline'] === val_as(DANA, "SELECT bd_board_outline(CAST('{$w['roadmap']}' AS uuid))"), 'Dana (a Viewer) reads the Roadmap\'s outline');
ok(mcp_tool(REC, ptoken(LEE), 'board_outline', ['board' => $w['roadmap']])['code'] === 'not_found', 'Lee, a stranger, does not');

echo "5. The trail of a share read\n";
$rows = share_rows($last);
ok(count($rows) >= 12 && count(array_filter($rows, fn ($l) => $l['source'] === 'application' && $l['actor_member_id'] === null && $l['entity_type'] === 'share' && $l['board_id'] === null)) === count($rows), 'every kernel call logged `share.read` (source application, no actor, no board): ' . count($rows) . ' rows, none for a person\'s or an agent\'s own read');
$l0 = json_decode($rows[0]['after'], true);
ok($l0['tool'] === 'boards_index' && $l0['direction'] === 'in' && $l0['application'] === 'spaces' && $l0['keys'] === ['limit'] && $l0['rows'] === 3 && $l0['agent_member_id'] === 42 && is_int($l0['ms']) && $rows[0]['kernel_request_id'] === 'req-p4-1', 'a row says the consumer, the tool, the argument KEYS given, the rows answered, the agent it ran as, the milliseconds and the kernel\'s request id');
$all = json_encode($rows);
ok(!str_contains($all, 'Roadmap') && !str_contains($all, 'roadmap') && !str_contains($all, 'Pricing') && !str_contains($all, 'Alpha'), 'never a title, a word searched or an answer in the trail');
$errs = array_filter(array_map(fn ($l) => json_decode($l['after'], true)['error'] ?? null, $rows));
ok(in_array('no_agent', $errs, true) && in_array('not_found', $errs, true) && in_array('not_allowed', $errs, true) && in_array('too_large', $errs, true), 'a refused read is logged too, by its code: ' . implode(', ', array_unique($errs)));
$before = (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'");
mcp_tool(REC, ptoken(PRIYA), 'board_outline', ['board' => $w['roadmap']]);
mcp_tool(REC, rtoken(40, $ex), 'board_outline', ['board' => $w['roadmap']]);
ok($before === (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'"), 'a person\'s or an agent\'s own outline logs nothing');
$bad = kcall('board_outline', ['board' => $w['roadmap']], 40, 'BAD CONSUMER');
$lr = json_decode((string) last_event('share.read')['after'], true);
ok($bad['data']['as_member'] === 40 && array_key_exists('application', $lr) && $lr['application'] === null, 'a consumer header that is not a key (a space in it) is ignored: the row names no application');

echo "6. The one write the servers make is narrow\n";
$dsn = 'pgsql:host=' . need('DB_HOST') . ';port=' . need('DB_PORT') . ';dbname=' . need('DB_NAME');
$ro = new PDO($dsn, need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$try = function (string $sql) use ($ro): string { try { $ro->exec($sql); return 'ok'; } catch (PDOException $e) { return (string) $e->getCode(); } };
ok($try("SELECT bd_log_mcp_read('screen.view', '{}'::jsonb)") === '23514', 'bd_log_mcp_read refuses any action but share.read (check_violation)');
$ro->exec("SELECT set_config('app.member_id', '26', false)");
ok($try("SELECT bd_log_mcp_read('share.read', '{}'::jsonb)") === '42501', 'and refuses a call that has a member: a share read is the kernel\'s alone');
$ro->exec("SELECT set_config('app.member_id', '', false)");
ok($try("SELECT bd_log_mcp_read('share.read', '{\"x\":1}'::jsonb)") === 'ok', 'while the kernel\'s (no member) is accepted');
ok($try("INSERT INTO activity_log (action) VALUES ('share.read')") === '42501' && $try('UPDATE activity_log SET action = action') === '42501' && $try('SELECT count(*) FROM activity_log') === '42501', 'the records role cannot write, update or even read the trail table itself');
ok($try('INSERT INTO boards (title) VALUES (\'x\')') === '42501' && $try("SELECT count(*) FROM board_items") === '42501' && $try('SELECT count(*) FROM mcp_boards') === 'ok', 'nor any base table: only the mcp_* views and the read functions');
pg("DELETE FROM activity_log WHERE action = 'share.read' AND after = '{\"x\": 1}'::jsonb");

if (apache()) {
    echo "7. The kernel's call through the vhost (Apache)\n";
    $r = kcall('boards_index', ['q' => 'roadmap'], 40, 'spaces', 'req-p4-apache', VHOST, '/mcp/records');
    ok(!$r['error'] && $r['data']['as_member'] === 40 && $r['data']['count'] === 1, 'boards_index through /mcp/records: the consumer headers cross the proxy');
    $r = kcall('board_outline', ['board' => $w['roadmap']], 42, 'spaces', null, VHOST, '/mcp/records');
    ok(!$r['error'] && $r['data']['as_member'] === 42 && str_contains($r['data']['outline'], 'Pricing idea'), 'board_outline through /mcp/records');
}
finish();
