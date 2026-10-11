<?php
/**
 * Slice 7 — JSON mode on the screen and both handlers (docs/build-specs/history-versions.md "Proof" 8): anonymous callers are 401, a handler wants POST and the CSRF token, every handler answers
 * {ok, did, record_id, location …} or the kit's error as a person's session, a person's action token and an agent's run token; the screen answers a whitelist; the log vocabulary of the slice.
 * Run through tests/phase3/slice7/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = json_decode((string) file_get_contents(need('BD_S7_SEED')), true);
$b = $seed['phone'];
[$jp] = sign_on(26);
[$jb] = sign_on(28);
$since = last_activity_id();
$anon = ['Accept: application/json'];

echo "8. Every handler in JSON mode\n";
foreach (['version-save.php', 'version-restore.php'] as $h) {
    $r = req('POST', "/boards/$h", ['headers' => $anon, 'form' => ['board' => $b, 'label' => 'x', 'version' => 1]]);
    ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', "$h, anonymous: 401 unauthorized (JSON)");
    $g = req('GET', "/boards/$h", ['jar' => $jp, 'headers' => $anon]);
    ok($g['code'] === 405 && (body_json($g)['error']['code'] ?? '') === 'method_not_allowed', "$h wants POST (405)");
    $n = req('POST', "/boards/$h", ['jar' => $jp, 'headers' => $anon, 'form' => ['board' => $b, 'label' => 'x', 'version' => 1]]);
    ok($n['code'] === 403, "$h: a session POST without the CSRF token is refused (403)");
}
$r = save_version($jp, $b, 'JSON version');
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === "Saved version {$j['record_id']} — JSON version" && is_int($j['record_id']) && $j['refresh'] === 'versionChanged' && $j['board_id'] === $b && $j['version'] === $j['record_id']
    && $j['location'] === "/boards/$b/history?version={$j['record_id']}&notice=saved", 'version_save: ok, did, record_id the version, refresh, board_id, version, location');
$no = $j['record_id'];
$r = restore_version($jp, $b, $seed['phone_baseline']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['noop'] === false && is_int($j['record_id']) && $j['version'] === (int) $seed['phone_baseline'] && $j['changes'] === 2 && $j['saved_as'] > $no && $j['location'] === "/boards/$b/history?notice=restored"
    && preg_match('/^Restored version \d+ — 2 changes in one batch; the board as it was is version \d+$/', $j['did']) === 1, 'version_restore: ok, did, record_id the seq, noop false, changes 2, saved_as, location');
$r = restore_version($jp, $b, $seed['phone_baseline']);
$j = body_json($r);
ok($r['code'] === 200 && $j['noop'] === true && $j['record_id'] === null && $j['location'] === "/boards/$b/history?notice=nothing", 'and again: noop, no record_id, the "nothing" notice');
$r = post_as($jp, '/boards/version-save.php', ['board' => 'not-a-uuid', 'label' => 'x']);
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is not one: 404 not_found');
$r = restore_version($jp, $b, 'abc');
ok($r['code'] === 422 && body_json($r)['error']['code'] === 'invalid', 'a version that is not a number: 422 invalid');
$r = post_as($jp, '/boards/version-restore.php', ['board' => $b]);
$bj = body_json($r);
ok($r['code'] === 422 && $bj['error']['errors'][0] === 'Say which version to restore.' && isset($bj['error']['fields']['version']), 'a refusal: 422 invalid with errors[] and fields{version}');
// a person's action token (the command bar) and an agent's run token
$pt = req('POST', '/boards/version-save.php', ['headers' => as_agent(person_token(26)), 'form' => ['board' => $b, 'label' => 'From the command bar']]);
ok($pt['code'] === 200 && count(array_filter(activity('version.save', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) saves as the assistant');
share_as(26, $b, 'agent', 42, 'edit');
$a = agent_act(42, 8200, '/boards/version-save.php', ['board' => $b, 'label' => 'Agent moment']);
ok($a['code'] === 200 && body_json($a)['ok'] === true, 'an agent at edit saves a version');

echo "   the screen\n";
$s = body_json(page($jp, "/boards/$b/history", ['headers' => $anon]));
$d = $s['data'] ?? [];
ok(array_keys($d) === ['board_id', 'title', 'level', 'total', 'page', 'last_visit', 'rows', 'versions', 'preview', 'visits'] && $d['level'] === 'full', 'board-history (JSON): board_id, title, level, total, page, last_visit, rows, versions, preview, visits');
ok(array_keys($d['rows'][0]) === ['seq', 'who', 'source', 'what', 'items', 'at', 'show', 'undoes'] && array_keys($d['versions'][0]) === ['version_no', 'label', 'reason', 'reason_words', 'named', 'items', 'saved_by', 'at'], 'the rows and versions are whitelists');
ok(!array_key_exists('ops', $d['rows'][0]) && !array_key_exists('summary', $d['rows'][0]) && !str_contains(json_encode($d), 'snapshot'), 'no ops, summary or snapshot leaves the database through it');
$d2 = body_json(page($jp, "/boards/$b/history?version=$no", ['headers' => $anon]))['data'];
ok($d2['preview']['version']['version_no'] === $no && is_string($d2['preview']['outline']), 'with ?version=: the preview');
$d3 = body_json(page($jb, "/boards/$b/history", ['headers' => $anon]))['data'];
ok($d3['level'] === 'edit' && is_array($d3['visits']), 'as an editor: level edit and the visits');
ok(page(sign_on(31)[0], "/boards/$b/history", ['headers' => $anon])['code'] === 404, 'Lee, not shared on this board: 404');

echo "   the slice's log vocabulary\n";
$all = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND (action LIKE 'version.%')", ['s' => $since]), 'action'));
sort($all);
ok($all === ['version.restore', 'version.save'], 'version.save and version.restore are logged here: ' . implode(', ', $all));
$unboarded = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND action LIKE 'version.%' AND board_id IS NULL", ['s' => $since])[0]['c'];
ok($unboarded === 0, 'every version row carries the board');
$leak = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND action LIKE 'version.%' AND (after::text LIKE '%Alpha%' OR after::text LIKE '%Beta%' OR after::text LIKE '%snapshot%')", ['s' => $since])[0]['c'];
ok($leak === 0, 'no item text or snapshot in a log row');
finish();
