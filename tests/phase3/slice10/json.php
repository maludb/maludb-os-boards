<?php
/**
 * Slice 10 — JSON mode on the four screens and the two handlers (home-admin.md "Proof" 8): anonymous callers are 401, a handler wants POST and the CSRF token, every handler answers {ok, did, record_id, location …} or the kit's
 * error as a person's session, a person's action token and an agent's run token; the screens answer whitelists; the slice's log vocabulary and the worker's pass in its own report. Run through tests/phase3/slice10/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
settings_reset();
[$tJson] = trashed_board($jp, 'SMOKE Trashed for JSON', 0);
[$tShell] = trashed_board($jp, 'SMOKE Trashed for the shell', 0);
$seed = ['trashed' => $tJson, 'trashed2' => $tShell];
$since = last_activity_id();
$anon = ['Accept: application/json'];

echo "8. Every handler in JSON mode\n";
foreach (['/admin/settings-save.php' => ['trash_days' => 31], '/admin/trash-purge.php' => ['board' => $seed['trashed']]] as $h => $form) {
    $r = req('POST', $h, ['headers' => $anon, 'form' => $form]);
    ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', "$h, anonymous: 401 unauthorized (JSON)");
    $g = req('GET', $h, ['jar' => $jm, 'headers' => $anon]);
    ok($g['code'] === 405 && (body_json($g)['error']['code'] ?? '') === 'method_not_allowed', "$h wants POST (405)");
    $n = req('POST', $h, ['jar' => $jm, 'headers' => $anon, 'form' => $form]);
    ok($n['code'] === 403, "$h: a session POST without the CSRF token is refused (403)");
}
$r = settings_as($jm, ['trash_days' => 31, 'live_enabled' => 'no']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Saved the settings: trash days' && $j['record_id'] === 1 && $j['location'] === '/admin/settings?notice=saved' && $j['refresh'] === 'settingsChanged' && $j['changed'] === ['trash_days'],
    'settings_save: ok, did, record_id, location, refresh, changed');
$r = settings_as($jm, ['item_hard_cap' => 50]);
$bj = body_json($r);
ok($r['code'] === 422 && $bj['error']['code'] === 'invalid' && $bj['error']['errors'][0] === 'The hard cap is a whole number from 100 to 100000.' && isset($bj['error']['fields']['item_hard_cap']), 'a refusal: 422 invalid with errors[] and fields{item_hard_cap}');
$r = settings_as($jp, ['trash_days' => 31]);
ok($r['code'] === 403 && body_json($r)['error']['code'] === 'forbidden', 'a Member: 403 forbidden');
$r = purge_as($jm, $seed['trashed']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['did'] === 'Purged SMOKE Trashed for JSON' && $j['record_id'] === $seed['trashed'] && $j['location'] === '/admin?notice=purged#trash' && $j['refresh'] === 'boardChanged' && $j['files_removed'] === 0 && $j['files_left'] === 0 && $j['items'] === 2,
    'trash_purge: ok, did, record_id, location, refresh, files_removed, files_left, items');
$r = purge_as($jm, $seed['trashed']);
ok($r['code'] === 404 && body_json($r)['error']['code'] === 'not_found', 'a board that is gone: 404 not_found');
$r = purge_as($jp, $seed['trashed2']);
ok($r['code'] === 403, 'trash_purge by a Member: 403');
// a person's action token (the command bar) and an agent's run token
$pt = req('POST', '/admin/trash-purge.php', ['headers' => as_agent(person_token(27)), 'form' => ['board' => $seed['trashed2']]]);
ok($pt['code'] === 200 && count(array_filter(activity('trash.purge', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) purges, logged as the assistant');
$a = req('POST', '/admin/trash-purge.php', ['headers' => as_agent(run_token(FACILITATOR, 9961)), 'form' => ['board' => $seed['trashed2']]]);
ok($a['code'] === 403, 'an agent\'s purge: 403 (people only)');
$a = req('POST', '/admin/settings-save.php', ['headers' => as_agent(run_token(FACILITATOR, 9962)), 'form' => ['trash_days' => 5]]);
ok($a['code'] === 403, 'an agent\'s settings_save without settings.manage: 403');

echo "   the screens\n";
$h = body_json(home_page($jp))['data'];
ok(array_keys($h) === ['recent', 'favourites', 'shared', 'team_folders', 'mentions_waiting', 'first_run', 'templates', 'may'] && !isset($h['recent'][0]['owner_member_id']), 'home (JSON): the whitelist');
$ad = admin_data($jm);
ok(array_keys($ad) === ['may', 'usage', 'trash', 'agents', 'most_active'] && !isset($ad['trash'][0]['owner_member_id']), 'admin (JSON): may, usage, trash, agents, most_active');
$us = admin_data($jm, '/admin/usage');
ok(array_keys($us['rows'][0]) === ['board_id', 'title', 'owner', 'item_count', 'over_soft', 'status', 'idle_days', 'open_threads', 'last_activity_at', 'url', 'transfer_url'] && !isset($us['rows'][0]['owner_member_id']) && !isset($us['rows'][0]['is_template']), 'admin-usage (JSON): rows are a whitelist, no raw column');
$st = admin_data($jm, '/admin/settings');
ok(array_keys($st['settings']) === ['business_name', 'default_everyone_level', 'item_soft_budget', 'item_hard_cap', 'batch_max_ops', 'max_upload_mb', 'op_retention_days', 'interval_version_days', 'daily_version_days', 'trash_days', 'live_enabled'] && !isset($st['settings']['storage_dir']), 'admin-settings (JSON): exactly the manifest\'s fields');
foreach (['/', '/admin', '/admin/usage', '/admin/settings'] as $path) {
    $sv = req('GET', $path, ['jar' => $jm, 'headers' => ['Accept: application/json', 'X-Screen-View: 1']]);
    ok($sv['code'] === 200, "$path logs screen.view in JSON only with X-Screen-View");
}
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen IN ('home', 'admin', 'admin-usage', 'admin-settings') AND id > :s", ['s' => $since]) === 4, 'exactly four screen.view rows (the JSON reads without the header logged none)');

echo "   the registry and the log\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['home', 'admin', 'admin-usage', 'admin-settings'];
ok(count(array_filter($screens, static fn ($k) => !empty($reg['screens'][$k]['built']) && empty($reg['screens'][$k]['stub']))) === 4 && !empty($reg['actions']['settings_save']['built']) && !empty($reg['actions']['trash_purge']['built']),
    'the registry reads the four screens and both actions built');
$all = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND action IN ('settings.save', 'trash.purge')", ['s' => $since]), 'action'));
sort($all);
ok($all === ['settings.save', 'trash.purge'], 'the events logged here: ' . implode(', ', $all));
$nb = q("SELECT count(*) AS c FROM activity_log WHERE id > :s AND action = 'trash.purge' AND board_id IS NULL", ['s' => $since])[0]['c'];
ok($nb === 0, 'every trash.purge row carries its board');
$w = worker_pass('trash');
ok($w['rc'] === 0 && $w['errors'] === [] && array_keys($w['ran']['trash']) === ['purged', 'files_removed', 'files_left'], 'the worker\'s trash pass reports its counts and no stub: ' . json_encode($w['ran']['trash'] ?? []));
$w = worker_pass('versions,outbox,dispatches,search_catch_up,thumbnails,trash,compaction,key_usage_prune');
$stubs = array_filter($w['ran'], static fn ($x) => isset($x['stub']));
ok($w['rc'] === 0 && $w['errors'] === [] && count($w['ran']) === 8 && $stubs === [], 'all eight passes run and not one is a stub any more');
finish();
