<?php
/**
 * Slice 10 — the settings (home-admin.md "Proof" 4): every field saved and read back; the two cross-field rules refused on their fields; an out-of-range UPDATE run directly as the application's role refused by the database
 * (23514) — the referee behind PHP's check; a Member 403; an agent's call paused by the registry; live updates off → the board page's mount carries no ticket and the island polls. Run through tests/phase3/slice10/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
[$jb] = sign_on(28);
settings_reset();
$since = last_activity_id();

echo "4. The form and the page\n";
$h = page($jm, '/admin/settings')['body'];
ok(!str_contains($h, 'id="nav-stub"') && str_contains($h, 'id="settings-form"') && str_contains($h, 'action="/admin/settings-save.php"'), 'Marco opens the settings: the form, no placeholder');
foreach (['business_name', 'item_soft_budget', 'item_hard_cap', 'batch_max_ops', 'max_upload_mb', 'op_retention_days', 'interval_version_days', 'daily_version_days', 'trash_days', 'live_enabled'] as $f) {
    $bad = !str_contains($h, 'id="settings-form-field-' . $f . '"');
    if ($bad) { ok(false, "the field $f has its id"); }
}
ok(str_contains($h, 'id="settings-form-field-default_everyone_level-none"') && str_contains($h, 'id="settings-form-field-default_everyone_level-view"') && str_contains($h, 'id="settings-form-field-default_everyone_level-comment"') && str_contains($h, 'id="settings-form-field-default_everyone_level-edit"')
    && preg_match('/id="settings-form-field-default_everyone_level-none" value="none" checked/', $h) === 1, 'the four levels are radios, Private checked, and every field has settings-form-field-{name}');
ok(str_contains($h, 'id="settings-fixed"') && str_contains($h, 'Set at install') && str_contains($h, 'id="settings-fixed-poll_seconds"') && !str_contains($h, 'name="poll_seconds"') && !str_contains($h, 'name="timezone"'), 'the rest is shown read-only, "set at install", with no input');
ok(str_contains($h, 'Lowering the hard cap never removes items') && str_contains($h, 'every board polls every 3 seconds'), 'the notes: lowering the hard cap removes nothing; live off means polling every poll_seconds');
$j = body_json(page($jm, '/admin/settings', ['headers' => ['Accept: application/json']]))['data'];
ok(array_keys($j) === ['settings', 'set_at_install', 'levels', 'updated_at'] && $j['settings']['item_soft_budget'] === 4000 && $j['settings']['live_enabled'] === false && !isset($j['settings']['timezone']) && $j['set_at_install']['poll_seconds'] === 3, 'JSON: settings (the editable), set_at_install (the rest), levels');
ok(page($jp, '/admin/settings')['code'] === 403 && page($jb, '/admin/settings')['code'] === 403, 'a Member: 403');
$r = page($jp, '/admin/settings', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 403 && (body_json($r)['error']['code'] ?? '') === 'forbidden' && str_contains(body_json($r)['error']['message'] ?? '', 'You may not change the settings.'), '…in JSON the words are "You may not change the settings."');

echo "4. Every field saved and read back\n";
$form = ['business_name' => 'SMOKE Subello Co', 'default_everyone_level' => 'view', 'item_soft_budget' => 2500, 'item_hard_cap' => 8000, 'batch_max_ops' => 300, 'max_upload_mb' => 40, 'op_retention_days' => 120,
         'interval_version_days' => 20, 'daily_version_days' => 400, 'trash_days' => 45, 'live_enabled' => 1];
$r = settings_as($jm, $form);
$d = body_json($r);
$row = settings_row();
ok($r['code'] === 200 && $d['ok'] === true && $d['record_id'] === 1 && $d['location'] === '/admin/settings?notice=saved' && $d['refresh'] === 'settingsChanged' && count($d['changed']) === 11, 'settings_save: ok, record_id 1, location, refresh settingsChanged, 11 changed');
ok($row['business_name'] === 'SMOKE Subello Co' && $row['default_everyone_level'] === 'view' && (int) $row['item_soft_budget'] === 2500 && (int) $row['item_hard_cap'] === 8000 && (int) $row['batch_max_ops'] === 300 && (int) $row['max_upload_mb'] === 40
    && (int) $row['op_retention_days'] === 120 && (int) $row['interval_version_days'] === 20 && (int) $row['daily_version_days'] === 400 && (int) $row['trash_days'] === 45 && $row['live_enabled'] === true, 'every field read back from bd_settings');
$log = activity('settings.save', $since);
$b = json_decode((string) $log[0]['before'], true);
$a = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['entity_type'] === 'settings' && (int) $log[0]['entity_id'] === 1 && $a['item_soft_budget'] === 2500 && $b['item_soft_budget'] === 4000 && $a['live_enabled'] === true && $b['live_enabled'] === false && $a['default_everyone_level'] === 'view' && $b['default_everyone_level'] === 'none' && count($a) === 11,
    'settings.save logs the old and new values of the changed fields');
$s2 = body_json(page($jm, '/admin/settings', ['headers' => ['Accept: application/json']]))['data']['settings'];
ok($s2['business_name'] === 'SMOKE Subello Co' && $s2['trash_days'] === 45 && $s2['live_enabled'] === true, 'the page reads them back');
$h = page($jm, '/admin/settings')['body'];
ok(preg_match('/id="settings-form-field-default_everyone_level-view" value="view" checked/', $h) === 1 && str_contains($h, 'value="2500"') && str_contains($h, 'value="SMOKE Subello Co"') && preg_match('/id="settings-form-field-live_enabled" value="1" checked/', $h) === 1, '…and the form shows them');
$before = last_activity_id();
$r = settings_as($jm, ['trash_days' => 60]);
$a = json_decode((string) activity('settings.save', $before)[0]['after'], true);
ok($r['code'] === 200 && (int) settings_row()['trash_days'] === 60 && (int) settings_row()['item_soft_budget'] === 2500 && $a === ['trash_days' => 60], 'a field left out stays as it was; the log holds the changed field only');
$before = last_activity_id();
$r = settings_as($jm, ['trash_days' => 60]);
ok($r['code'] === 200 && body_json($r)['did'] === 'Nothing changed' && body_json($r)['changed'] === [], 'saving what is already there changes nothing and says so');
$r = settings_as($jm, ['business_name' => '']);
ok($r['code'] === 200 && settings_row()['business_name'] === null, 'an emptied business name is cleared');
$r = settings_as($jm, ['live_enabled' => 'off']);
ok(settings_row()['live_enabled'] === false, 'live_enabled takes off');
$r = post_as($jm, '/admin/settings-save.php', ['live_enabled' => 'maybe']);
ok($r['code'] === 422, 'a yes/no that is neither is refused (422)');

echo "4. The refusals\n";
$cases = [
    'soft not below hard' => [['item_soft_budget' => 8000], 'item_soft_budget', 'The soft budget must be below the hard cap.'],
    'soft above hard' => [['item_soft_budget' => 9000, 'item_hard_cap' => 8500], 'item_soft_budget', 'The soft budget must be below the hard cap.'],
    'interval past daily' => [['interval_version_days' => 500], 'interval_version_days', 'Autosaves are thinned before the daily ones end.'],
    'daily below interval' => [['daily_version_days' => 10], 'interval_version_days', 'Autosaves are thinned before the daily ones end.'],
    'budget out of range' => [['item_soft_budget' => 50], 'item_soft_budget', 'The soft budget is a whole number from 100 to 100000.'],
    'cap out of range' => [['item_hard_cap' => 100001], 'item_hard_cap', 'The hard cap is a whole number from 100 to 100000.'],
    'batch' => [['batch_max_ops' => 0], 'batch_max_ops', 'The ops in a batch is a whole number from 1 to 5000.'],
    'upload' => [['max_upload_mb' => 2000], 'max_upload_mb', 'The upload size is a whole number from 1 to 1024.'],
    'retention' => [['op_retention_days' => 3], 'op_retention_days', 'The days the changes are kept is a whole number from 7 to 3650.'],
    'trash days' => [['trash_days' => 'many'], 'trash_days', 'The days a deleted board waits is a whole number from 1 to 3650.'],
    'level' => [['default_everyone_level' => 'full'], 'default_everyone_level', 'The everyone level is one of: none, view, comment, edit.'],
    'name' => [['business_name' => str_repeat('x', 121)], 'business_name', 'The business name is up to 120 characters.'],
];
$kept = settings_row();
foreach ($cases as $label => [$form, $field, $words]) {
    $r = settings_as($jm, $form);
    $e = body_json($r)['error'] ?? [];
    ok($r['code'] === 422 && ($e['code'] ?? '') === 'invalid' && ($e['fields'][$field] ?? null) === $words, "$label: 422 on $field — \"$words\"");
}
$now = settings_row();
unset($kept['updated_at'], $now['updated_at']);
ok($kept == $now, 'none of the refusals changed anything');
$r = post_as($jm, '/admin/settings-save.php', ['item_soft_budget' => 20000], ['Accept: text/html']);
ok($r['code'] === 422 && str_contains($r['body'], 'The soft budget must be below the hard cap.'), 'a browser is told the first sentence (422)');
echo "   the database as referee\n";
as_member(27);
$refused = static function (string $sql): string { try { pdo()->exec($sql); return 'allowed'; } catch (PDOException $e) { return (string) $e->getCode(); } };
ok($refused('UPDATE bd_settings SET item_soft_budget = 50') === '23514' && $refused('UPDATE bd_settings SET item_hard_cap = 200000') === '23514' && $refused('UPDATE bd_settings SET trash_days = 0') === '23514' && $refused("UPDATE bd_settings SET default_everyone_level = 'full'") === '23514',
    'out-of-range UPDATEs run directly as the application\'s role: refused (23514)');
ok($refused('UPDATE bd_settings SET item_soft_budget = 9000') === '23514' && $refused('UPDATE bd_settings SET interval_version_days = 500') === '23514', '…and the two cross-field rules (soft < hard, interval <= daily)');
$e = '';
try { pdo()->exec('UPDATE bd_settings SET item_soft_budget = 50'); } catch (PDOException $x) { $e = $x->getMessage(); }
ok(str_contains($e, 'bd_settings_item_soft_budget_check'), 'the constraint names its field (bd_settings_item_soft_budget_check)');

echo "4. Agents, rights and live updates\n";
share_as(26, $seedBoard = make_board($jp, 'SMOKE Live board'), 'agent', FACILITATOR, 'edit');
$a = req('POST', '/admin/settings-save.php', ['headers' => as_agent(run_token(FACILITATOR, 9901)), 'form' => ['trash_days' => 5]]);
ok($a['code'] === 403 && (int) settings_row()['trash_days'] === 60, 'an agent without settings.manage: refused (403), nothing changed');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['settings_save']['approval'] ?? '') === 'other' && ($reg['actions']['settings_save']['built'] ?? false) === true, 'the registry pauses settings_save for an agent (approval other) — the kernel\'s hook holds it before it arrives');
$r = post_as($jp, '/admin/settings-save.php', ['trash_days' => 5]);
ok($r['code'] === 403 && (int) settings_row()['trash_days'] === 60, 'a Member: 403, nothing changed');
$r = req('POST', '/admin/settings-save.php', ['headers' => as_agent(person_token(27)), 'form' => ['trash_days' => 61]]);
ok($r['code'] === 200 && (int) settings_row()['trash_days'] === 61 && count(array_filter(activity('settings.save', $since), static fn ($x) => $x['source'] === 'assistant')) === 1, 'a person\'s action token (the command bar) saves, logged as the assistant');
settings_as($jm, ['live_enabled' => 1, 'trash_days' => 30]);
$mount = page($jp, "/boards/$seedBoard")['body'];
ok(preg_match('/id="board-canvas"[^>]*data-ticket="/', $mount) === 1 || str_contains($mount, 'data-ticket='), 'live on: the board page\'s mount carries a ticket for the relay');
$t = req('POST', "/boards/$seedBoard/live-ticket", ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)]]);
ok(!empty(body_json($t)['ticket']), '…and the ticket door hands one out');
settings_as($jm, ['live_enabled' => 0]);
$mount = page($jp, "/boards/$seedBoard")['body'];
ok(!str_contains($mount, 'data-ticket=') && str_contains($mount, 'data-live-url="/live"') && str_contains($mount, 'data-poll-seconds="3"'), 'live off: no ticket in the mount, the island starts polling every poll_seconds');
$t = req('POST', "/boards/$seedBoard/live-ticket", ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jp)]]);
ok(body_json($t)['ticket'] === null && body_json($t)['live'] === false, '…and the ticket door says live is off (a setting, not an error)');
echo "   the other settings take hold\n";
settings_as($jm, ['default_everyone_level' => 'view']);
$nb = make_board($jp, 'SMOKE Default level');
ok(level_of($nb, 28) === 'view', 'a new board takes the everyone level from the settings (Bea sees it at view)');
settings_as($jm, ['default_everyone_level' => 'none']);
settings_reset();
finish();
