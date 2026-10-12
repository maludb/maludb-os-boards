<?php
/**
 * Slice 9 — JSON mode on the three screens and the two handlers (agents-search.md "Proof" 9): anonymous callers are 401, a handler wants POST and the CSRF token, every handler answers
 * {ok, did, record_id, location …} or the kit's error as a person's session, a person's action token and an agent's run token; the screens answer whitelists; the slice's log vocabulary and the
 * worker's passes in their own report. Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S9_SEED')), true);
[$jp] = sign_on(26);
[$jm] = sign_on(27);
$since = last_activity_id();
$anon = ['Accept: application/json'];
kernel_chat(finished('Fixture reply.', 3));

echo "9. Every handler in JSON mode\n";
foreach (['/items/arrange.php' => ['board' => $seed['arrange'], 'items' => $seed['arrange_items'], 'layout' => 'grid'], '/admin/dispatch-retry.php' => ['dispatch' => $seed['phone_failed']]] as $h => $form) {
    $r = req('POST', $h, ['headers' => $anon, 'form' => $form]);
    ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', "$h, anonymous: 401 unauthorized (JSON)");
    $g = req('GET', $h, ['jar' => $jm, 'headers' => $anon]);
    ok($g['code'] === 405 && (body_json($g)['error']['code'] ?? '') === 'method_not_allowed', "$h wants POST (405)");
    $n = req('POST', $h, ['jar' => $jm, 'headers' => $anon, 'form' => $form]);
    ok($n['code'] === 403, "$h: a session POST without the CSRF token is refused (403)");
}
$r = arrange_as($jp, $seed['arrange'], $seed['arrange_items'], 'grid', ['columns' => 3]);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Arranged 6 items as a grid', 'items_arrange: ok, did');
ok($r['code'] === 200 && is_int($j['record_id']) && $j['record_id'] > 0 && $j['location'] === '/boards/' . $seed['arrange'] . '?item=' . $seed['arrange_items'][0] && $j['items'] === $seed['arrange_items'] && $j['layout'] === 'grid' && $j['grown'] === false && $j['duplicate'] === false,
    'items_arrange: record_id the seq, location, items, layout, grown, duplicate');
$r = arrange_as($jp, $seed['arrange'], [$seed['arrange_items'][0]], 'frame');
$bj = body_json($r);
ok($r['code'] === 422 && $bj['error']['code'] === 'invalid' && isset($bj['error']['fields']['layout']) && $bj['error']['errors'][0] === 'The layout is one of: grid, column, row, cluster.', 'a refusal: 422 invalid with errors[] and fields{layout}');
$r = arrange_as($jp, uuid4(), $seed['arrange_items'], 'grid');
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is not there: 404 not_found');
$r = retry_as($jm, $seed['phone_failed']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['record_id'] === $seed['phone_failed'] && $j['dispatch_id'] === $seed['phone_failed'] && $j['location'] === '/admin/dispatches?notice=retried' && $j['refresh'] === 'dispatchChanged', 'dispatch_retry: ok, did, record_id, location, refresh');
// a person's action token (the command bar) and an agent's run token
$pt = req('POST', '/items/arrange.php', ['headers' => as_agent(person_token(26)), 'form' => ['board' => $seed['arrange'], 'items' => $seed['arrange_items'], 'layout' => 'row']]);
ok($pt['code'] === 200 && count(array_filter(activity('item.move', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) arranges as the assistant');
share_as(26, $seed['arrange'], 'agent', FACILITATOR, 'edit');
$a = agent_act(FACILITATOR, 9801, '/items/arrange.php', ['board' => $seed['arrange'], 'items' => $seed['arrange_items'], 'layout' => 'column']);
ok($a['code'] === 200 && body_json($a)['ok'] === true && (int) one("SELECT count(*) FROM activity_log WHERE action = 'item.move' AND source = 'agent' AND agent_run_id = 9801") === 1, 'an agent arranges (logged as agent, with its run)');
$a = req('POST', '/admin/dispatch-retry.php', ['headers' => as_agent(run_token(FACILITATOR, 9802)), 'form' => ['dispatch' => $seed['phone_failed']]]);
ok($a['code'] === 403, 'an agent\'s retry: 403 (people only)');

echo "   the screens\n";
$s = body_json(search_json($jp, 'q=quokka'))['data'];
ok(array_keys($s) === ['filters', 'page', 'page_size', 'total', 'min_length', 'kinds', 'rows'] && $s['kinds'] === ['board', 'item', 'comment'] && $s['filters'] == ['q' => 'quokka'] && $s['total'] === 2, 'search (JSON): filters, page, page_size, total, min_length, kinds, rows');
ok(array_keys($s['rows'][0]) === ['kind', 'id', 'board_id', 'board_title', 'item_type', 'title', 'excerpt', 'author', 'at', 'url'] && !isset($s['rows'][0]['tsv']) && !isset($s['rows'][0]['author_id']), 'a search row is a whitelist: no tsv, no author id');
$ag = body_json(page($jm, '/admin/agents', ['headers' => $anon]))['data'];
ok(array_keys($ag) === ['agents'] && !isset($ag['agents'][0]['member_id']) && isset($ag['agents'][0]['agent_id']), 'admin-agents (JSON): agents[] with agent_id, no raw column');
$dp = body_json(page($jm, '/admin/dispatches?status=awaiting_approval', ['headers' => $anon]))['data'];
ok($dp['rows'][0]['status_words'] === 'Waiting for approval' && $dp['rows'][0]['may_retry'] === true && !isset($dp['rows'][0]['acting_member_id']), 'admin-dispatches (JSON): the status in words, may_retry, no raw column');
foreach (['/search?q=quokka', '/admin/agents', '/admin/dispatches'] as $path) {
    $sv = req('GET', $path, ['jar' => $jm, 'headers' => ['Accept: application/json', 'X-Screen-View: 1']]);
    ok($sv['code'] === 200, "$path logs screen.view in JSON only with X-Screen-View");
}
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen IN ('search', 'admin-agents', 'admin-dispatches') AND id > :s", ['s' => $since]) === 3, 'exactly three screen.view rows (the JSON reads without the header logged none)');

echo "   the worker's report\n";
$w = worker_pass('dispatches,search_catch_up');
ok($w['rc'] === 0 && $w['errors'] === [] && array_keys($w['ran']['dispatches']) === ['called', 'answered', 'running', 'awaiting', 'refused', 'failed', 'polled'] && array_keys($w['ran']['search_catch_up']) === ['indexed', 'removed'],
    'the two passes report their counts and no stub: ' . json_encode($w['ran']));
echo "   the slice's log vocabulary\n";
$all = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND (action IN ('item.move', 'dispatch.retry') OR action LIKE 'agent.%')", ['s' => $since]), 'action'));
sort($all);
ok($all === ['agent.dispatch', 'agent.reply', 'dispatch.retry', 'item.move'], 'the events logged here (the retried dispatch was sent and answered by the worker pass): ' . implode(', ', $all));
$unboarded = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND action IN ('item.move', 'dispatch.retry') AND board_id IS NULL", ['s' => $since])[0]['c'];
ok($unboarded === 0, 'every one carries the board');
$allEv = q("SELECT DISTINCT action FROM activity_log WHERE action IN ('agent.dispatch', 'agent.reply', 'agent.fail', 'dispatch.retry', 'item.move') ORDER BY action");
ok(array_column($allEv, 'action') === ['agent.dispatch', 'agent.fail', 'agent.reply', 'dispatch.retry', 'item.move'], 'and across the slice\'s proofs all five events were written');
$noBoard = q("SELECT count(*) AS c FROM activity_log WHERE action LIKE 'agent.%' AND board_id IS NULL")[0]['c'];
ok($noBoard === 0, 'every agent.* row carries its board');
finish();
