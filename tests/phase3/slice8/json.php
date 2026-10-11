<?php
/**
 * Slice 8 — JSON mode on the two screens and the four handlers (docs/build-specs/templates-export.md "Proof" 7): anonymous callers are 401, a handler wants POST and the CSRF token, every handler answers
 * {ok, did, record_id, location …} or the kit's error as a person's session, a person's action token and an agent's run token; the screens answer whitelists; the slice's log vocabulary.
 * Run through tests/phase3/slice8/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S8_SEED')), true);
$b = $seed['phone'];
[$jp] = sign_on(26);
[$jm] = sign_on(27);
$since = last_activity_id();
$anon = ['Accept: application/json'];

echo "7. Every handler in JSON mode\n";
foreach (['/boards/duplicate.php' => 'board', '/templates/use.php' => 'template', '/templates/save.php' => 'board', '/templates/publish.php' => 'board'] as $h => $field) {
    $form = [$field => $field === 'template' ? SHIPPED['retro'] : $b, 'title' => 'x'];
    $r = req('POST', $h, ['headers' => $anon, 'form' => $form]);
    ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', "$h, anonymous: 401 unauthorized (JSON)");
    $g = req('GET', $h, ['jar' => $jp, 'headers' => $anon]);
    ok($g['code'] === 405 && (body_json($g)['error']['code'] ?? '') === 'method_not_allowed', "$h wants POST (405)");
    $n = req('POST', $h, ['jar' => $jp, 'headers' => $anon, 'form' => $form]);
    ok($n['code'] === 403, "$h: a session POST without the CSRF token is refused (403)");
}
$r = duplicate_board($jp, $b, ['title' => 'SMOKE JSON copy']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Made a copy of SMOKE Export phone' && preg_match('/^[0-9a-f-]{36}$/', $j['record_id']) === 1 && $j['location'] === '/boards/' . $j['record_id']
    && $j['refresh'] === 'boardChanged' && $j['board_id'] === $j['record_id'] && $j['source_board'] === $b, 'board_duplicate: ok, did, record_id, location, refresh, board_id, source_board');
$r = use_template($jp, SHIPPED['weekly']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Made a board from Weekly planning' && $j['location'] === '/boards/' . $j['record_id'] && item_total($j['record_id']) === 11, 'template_use: ok, did, record_id the new board (11 items)');
$r = save_template($jp, $b, ['title' => 'SMOKE JSON template', 'category' => 'workshop']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['location'] === '/templates/' . $j['record_id'] . '?notice=saved' && $j['did'] === 'Saved SMOKE JSON template as a template of yours', 'template_save: ok, did, record_id, location with the notice');
$r = publish_template($jm, $b, ['title' => 'SMOKE JSON published', 'category' => 'workshop']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['location'] === '/templates/' . $j['record_id'] . '?notice=published', 'template_publish (an admin): ok, record_id, location with the notice');
$pub = $j['record_id'];
$r = post_as($jp, '/boards/duplicate.php', ['board' => 'not-a-uuid']);
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is not one: 404 not_found');
$r = post_as($jp, '/templates/save.php', ['board' => $b]);
$bj = body_json($r);
ok($r['code'] === 422 && $bj['error']['code'] === 'invalid' && $bj['error']['errors'][0] === 'Give the template a title.' && isset($bj['error']['fields']['title']), 'a refusal: 422 invalid with errors[] and fields{title}');
$r = post_as($jp, '/templates/use.php', ['template' => $b]);
ok($r['code'] === 404 && body_json($r)['error']['message'] === 'There is no such template.', 'use of a board that is not a template: 404 in words');
// a person's action token (the command bar) and an agent's run token
$pt = req('POST', '/templates/use.php', ['headers' => as_agent(person_token(26)), 'form' => ['template' => SHIPPED['brainstorm']]]);
ok($pt['code'] === 200 && count(array_filter(activity('template.use', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) uses a template as the assistant');
share_as(26, $b, 'agent', 42, 'view');
$a = agent_act(42, 8310, '/boards/duplicate.php', ['board' => $b, 'title' => 'SMOKE Agent copy']);
ok($a['code'] === 200 && body_json($a)['ok'] === true && (int) one("SELECT count(*) FROM activity_log WHERE action = 'board.duplicate' AND source = 'agent' AND agent_run_id = 8310") === 1, 'an agent duplicates a board it can view (logged as agent, with its run)');
$a = agent_act(42, 8311, '/templates/use.php', ['template' => SHIPPED['retro']]);
ok($a['code'] === 200 && body_json($a)['ok'] === true, 'an agent uses a shipped template');

echo "   the export door in JSON mode\n";
$r = req('GET', "/boards/$b/export?format=svg", ['jar' => $jp, 'headers' => $anon]);
ok($r['code'] === 200 && str_starts_with(header_of($r, 'Content-Type'), 'image/svg+xml'), 'a download is a download even when JSON is asked: 200 image/svg+xml');
$r = req('GET', "/boards/$b/export?format=svg", ['headers' => as_agent(person_token(26))]);
ok($r['code'] === 200 && str_starts_with($r['body'], '<svg'), 'a person\'s action token reads the SVG');
$r = req('GET', "/boards/" . uuid4() . "/export?format=svg", ['jar' => $jp, 'headers' => $anon]);
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is not there: 404 not_found (JSON)');

echo "   the screens\n";
$g = body_json(gallery($jp));
$d = $g['data'] ?? [];
ok(array_keys($d) === ['filters', 'page', 'page_size', 'total', 'rows', 'may', 'categories', 'scopes'] && $d['page_size'] === 24 && $d['categories'] === ['retrospective', 'brainstorm', 'strategy', 'planning', 'diagram', 'workshop', 'other']
    && $d['scopes'] === ['shipped', 'business', 'personal'], 'templates (JSON): filters, page, page_size, total, rows, may, categories, scopes');
ok(!isset($d['rows'][0]['owner_member_id']) && !isset($d['rows'][0]['thumbnail_id']) && !isset($d['rows'][0]['my_level']), 'a row is a whitelist: no owner id, no raw column');
$f = body_json(gallery($jp, 'category=diagram&scope=shipped'))['data'];
ok($f['filters'] == ['category' => 'diagram', 'scope' => 'shipped'] && $f['total'] === 1 && $f['rows'][0]['title'] === 'Flowchart', 'the filters echo: category=diagram&scope=shipped → the Flowchart');
$t = body_json(template_page($jp, SHIPPED['flow']))['data'];
ok(array_keys($t) === ['board_id', 'title', 'description', 'scope', 'scope_words', 'category', 'category_words', 'item_count', 'by', 'thumbnail_url', 'url', 'my_level', 'outline', 'open_url', 'created_at', 'may']
    && $t['item_count'] === 12 && str_contains($t['outline'], '→') && $t['may'] == ['use' => true, 'delete' => false], 'template-view (JSON): the row, my_level, outline (with the connections), open_url, may');
$pd = body_json(template_page($jm, $pub))['data'];
ok($pd['scope'] === 'business' && $pd['may']['delete'] === true && $pd['my_level'] === 'full', 'a business template\'s maker may delete it');
$sv = req('GET', '/templates', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-Screen-View: 1']]);
ok($sv['code'] === 200 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'templates' AND id > :s", ['s' => $since]) === 1, 'the gallery logs screen.view (JSON only with X-Screen-View)');

echo "   the slice's log vocabulary\n";
$all = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND (action IN ('board.duplicate', 'board.export') OR action LIKE 'template.%')", ['s' => $since]), 'action'));
sort($all);
ok($all === ['board.duplicate', 'board.export', 'template.publish', 'template.save', 'template.use'], 'the five events are logged here: ' . implode(', ', $all));
$unboarded = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND (action IN ('board.duplicate', 'board.export') OR action LIKE 'template.%') AND board_id IS NULL", ['s' => $since])[0]['c'];
ok($unboarded === 0, 'every one carries the board');
$noSource = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND (action = 'board.duplicate' OR action LIKE 'template.%') AND after->>'source_board' IS NULL", ['s' => $since])[0]['c'];
ok($noSource === 0, 'and every copy names its source board');
$leak = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND (action = 'board.duplicate' OR action LIKE 'template.%') AND (after::text LIKE '%Alpha%' OR after::text LIKE '%Gamma%' OR after::text LIKE '%snapshot%')", ['s' => $since])[0]['c'];
ok($leak === 0, 'no item text in a log row');
finish();
