<?php
/**
 * Proof 2's undo and the island's (docs/build-specs/canvas.md "Proof" 2, decisions 1 and 3; canvas-core.md "Undo/redo"): ops_undo takes back
 * the caller's last batches on the board within the hour — never an undo batch, never one already undone, never another member's — as ONE
 * new batch; a person's undo is the answer's inverse posted as a new batch, and it puts back exactly what was there (a frame and its children,
 * a style key that did not exist, a moved item). The command bar commits with source command_bar.
 */
require __DIR__ . '/lib.php';
echo "2. undo\n";
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Undo');
share_as(26, $b, 'agent', 42, 'edit');       // the Facilitator
$RUN = 8301;
kernel_state(function ($s) use ($RUN) { $s['facts'][(string) $RUN] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => $RUN, 'request_id' => 'r-' . $RUN,
    'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
$act = static fn (string $file, array $form) => agent_act(42, $RUN, $file, ['board' => $b] + $form);

$a = body_json($act('/items/create.php', ['type' => 'sticky_note', 'texts' => ['Keep'], 'x' => '0', 'y' => '0']));
$keep = $a['items'][0];
$c = body_json($act('/items/create.php', ['type' => 'sticky_note', 'texts' => ['Undo me 1', 'Undo me 2'], 'x' => '500', 'y' => '0']));
$u = body_json($act('/items/update.php', ['items' => [$keep], 'color' => 'pink']));
$priya = body_json(ops_post($jp, $b, [item_op(['text' => 'Priya\'s', 'x' => -600])]));
$since = last_activity_id();
$r = $act('/items/undo.php', ['count' => 2]);
$j = body_json($r);
ok($r['code'] === 200 && $j['undone'] === [$u['record_id'], $c['record_id']] && $j['did'] === 'Took back your last 2 changes on SMOKE Undo',
    'undo my last two: "' . ($j['did'] ?? $r['body']) . '" — the update and the create, newest first (Priya\'s batch in between is not mine)');
ok(json_decode((string) item_row($keep)['style'], true)['fillColor'] === 'light_yellow' && item_row($c['items'][0])['deleted_at'] !== null && item_row($c['items'][1])['deleted_at'] !== null
   && plain(q("SELECT id FROM board_items WHERE plain_text = 'Priya''s' AND board_id = CAST(:b AS uuid)", ['b' => $b])[0]['id']) === "Priya's",
    'the items are back as they were: the colour restored, the two stickies gone; Priya\'s sticky untouched');
$op = q('SELECT source, undoes_seq, member_id, agent_run_id FROM board_ops WHERE seq = :s', ['s' => $j['record_id']])[0];
ok($op['undoes_seq'] === '{' . $u['record_id'] . ',' . $c['record_id'] . '}' && $op['source'] === 'agent' && (int) $op['agent_run_id'] === $RUN, 'ONE undo batch naming what it undid, the agent\'s run on it');
$log = activity('ops.undo', $since);
ok(count($log) === 1 && json_decode((string) $log[0]['after'], true)['undone'] === [$u['record_id'], $c['record_id']], 'one ops.undo row with the seqs undone');
$r = $act('/items/undo.php', ['count' => 1]);
$j2 = body_json($r);
ok($r['code'] === 200 && $j2['undone'] === [$a['record_id']] && item_row($keep)['deleted_at'] !== null, 'the next undo skips the undo batch and the batches it already undid (decision 1): the first create goes');
$r = $act('/items/undo.php', ['count' => 1]);
ok($r['code'] === 422 && str_contains($r['body'], 'Nothing of yours to undo'), 'nothing of mine left — another member\'s batch is never mine to undo: 422');
$old = body_json($act('/items/create.php', ['type' => 'text', 'texts' => ['Old'], 'x' => '0', 'y' => '900']));
pg("UPDATE board_ops SET at = now() - interval '2 hours' WHERE seq = {$old['record_id']}");
ok($act('/items/undo.php', ['count' => 1])['code'] === 422, 'a batch older than the hour is not undone');
ok($act('/items/undo.php', ['count' => 11])['code'] === 422, 'count is 1 to 10');
$forged = req('POST', '/items/undo.php', ['jar' => $jp, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jp)], 'form' => ['board' => $b, 'count' => 1]]);
ok($forged['code'] === 200 && body_json($forged)['undone'] === [$priya['seq']], 'a person\'s ops_undo takes back their own batch only');
as_member(42);
$code = '';
try { one("SELECT bd_undo_ops(CAST(:b AS uuid), 42, ARRAY[{$priya['seq']}]::bigint[], 'agent')", ['b' => $b]); } catch (PDOException $e) { $code = (string) $e->getCode(); }
ok($code === '42501', 'bd_undo_ops refuses another member\'s batch outright (the database\'s rule, 42501)');

echo "   the island's undo: the answer's inverse posted as a new batch\n";
$fr = item_op(['type' => 'frame', 'x' => 0, 'y' => 2000, 'width' => 600, 'height' => 400, 'data' => ['title' => 'F'], 'style' => (object) []]);
$k1 = item_op(['parent_id' => $fr['item']['id'], 'x' => 150, 'y' => 200, 'text' => 'child 1']);
$k2 = item_op(['parent_id' => $fr['item']['id'], 'x' => 400, 'y' => 200, 'text' => 'child 2']);
ops_post($jp, $b, [$fr, $k1, $k2]);
$del = body_json(ops_post($jp, $b, [['op' => 'delete', 'ids' => [$fr['item']['id']]]]));
ok((int) one('SELECT count(*) FROM board_items WHERE parent_id = CAST(:f AS uuid) AND deleted_at IS NOT NULL', ['f' => $fr['item']['id']]) === 2, 'deleting a frame takes its children (the database\'s cascade)');
$back = body_json(ops_post($jp, $b, $del['inverse']));
ok((int) one('SELECT count(*) FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) AND deleted_at IS NULL', ['i' => pg_array_literal([$fr['item']['id'], $k1['item']['id'], $k2['item']['id']])]) === 3,
    'its inverse posted back restores the frame and both children');
$redo = body_json(ops_post($jp, $b, $back['inverse']));
ok((int) one('SELECT count(*) FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) AND deleted_at IS NULL', ['i' => pg_array_literal([$fr['item']['id'], $k1['item']['id'], $k2['item']['id']])]) === 0,
    'and the undo\'s own inverse redoes it (redo)');
$s = item_op(['x' => 0, 'y' => 3000, 'style' => ['fillColor' => 'blue']]);
ops_post($jp, $b, [$s]);
$st = body_json(ops_post($jp, $b, [['op' => 'update', 'id' => $s['item']['id'], 'fields' => ['x' => 250, 'y' => 3100, 'style' => ['borderColor' => '#000000', 'fillColor' => 'red']]]]));
ops_post($jp, $b, $st['inverse']);
$row = item_row($s['item']['id']);
ok((float) $row['x'] === 0.0 && (float) $row['y'] === 3000.0 && json_decode((string) $row['style'], true) === ['fillColor' => 'blue'],
    'a move and a style change undone: the place back, the colour back, the key that was not there removed again');
ops_post($jp, $b, $redo['inverse']);                // the frame and its children back again (undo the redo)
$kept = body_json(ops_post($jp, $b, [['op' => 'delete', 'ids' => [$fr['item']['id']], 'keep_children' => true]]));
ok(item_row($k1['item']['id'])['parent_id'] === null && item_row($k1['item']['id'])['deleted_at'] === null, 'a frame deleted keeping its children: they stand on the canvas');
ops_post($jp, $b, $kept['inverse']);
ok(item_row($k1['item']['id'])['parent_id'] === $fr['item']['id'] && (float) item_row($k1['item']['id'])['x'] === 150.0, 'its inverse puts the frame back and the children in it, where they were');

echo "   the command bar\n";
$tok = person_token(26);
$r = req('POST', '/items/create.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok], 'form' => ['board' => $b, 'type' => 'sticky_note', 'texts' => ['From the command bar']]]);
$seq = (int) (body_json($r)['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT source FROM board_ops WHERE seq = :s', ['s' => $seq]) === 'command_bar', 'a person\'s action token (the command bar) commits with source command_bar');
finish();
