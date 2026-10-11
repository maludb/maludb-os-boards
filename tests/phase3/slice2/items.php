<?php
/**
 * Proof 2 (docs/build-specs/canvas.md "Proof" 2): the agents' item actions with an agent's action token (JSON, through the kernel's relay):
 * five stickies in a grid in a frame (frame-relative, parent_id set, the summary's box inside the frame, the frame growing), in empty space
 * below the extent, at x,y; colour and front on three items; text on two refused; delete two (the registry marks it `deletion`); every action
 * ONE batch with source agent, the run on the batch and the log, record_id the seq; a retried call commits once.
 */
require __DIR__ . '/lib.php';
echo "2. the agents' item actions\n";
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Agents');
share_as(26, $b, 'agent', 40, 'edit');       // the Expert at edit
share_as(26, $b, 'agent', 42, 'view');       // the Facilitator can only look
$RUN = 8201;
// the kernel's run facts name the run's request id: every row and batch of the run carries it (the join to the prompt ledger)
kernel_state(function ($s) use (&$RUN) { $s['facts'][(string) $RUN] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => $RUN, 'request_id' => 'r-' . $RUN,
    'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
$act = static fn (string $file, array $form, int $agent = 40) => agent_act($agent, $RUN, $file, ['board' => $b] + $form);

// a frame to fill (frames are drawn by slice 4; one exists here as an agent's or a template's would)
$frame = item_op(['type' => 'frame', 'x' => 500, 'y' => 300, 'width' => 400, 'height' => 300, 'style' => (object) [], 'data' => ['title' => 'Went well'], 'z' => 'a0']);
ops_post($jp, $b, [$frame, item_op(['x' => -400, 'y' => -200, 'z' => 'a1', 'text' => 'Already here'])]);
$fid = $frame['item']['id'];

$since = last_activity_id();
$r = $act('/items/create.php', ['type' => 'sticky_note', 'texts' => ['One', 'Two', 'Three', 'Four', 'Five'], 'frame' => $fid, 'color' => 'light_green']);
$j = body_json($r);
$ids = $j['items'] ?? [];
$rows = q("SELECT id, parent_id, x, y, width, height, style, plain_text, (bbox[1])[0] AS x1, (bbox[1])[1] AS y1, (bbox[0])[0] AS x2, (bbox[0])[1] AS y2 FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) ORDER BY y, x",
    ['i' => pg_array_literal($ids)]);
ok($r['code'] === 200 && $j['ok'] === true && count($ids) === 5 && $j['did'] === 'Added 5 stickies in a grid in Went well (the frame grew to hold them)',
    'five stickies in a grid in a frame: "' . ($j['did'] ?? $r['body']) . '"');
ok(count($rows) === 5 && count(array_filter($rows, static fn ($x) => $x['parent_id'] === $fid)) === 5 && array_column($rows, 'plain_text') === ['One', 'Two', 'Three', 'Four', 'Five'],
    'each in the frame (parent_id), in reading order');
ok((float) $rows[0]['x'] === 124.0 && (float) $rows[0]['y'] === 184.0 && (float) $rows[1]['x'] === 348.0 && (float) $rows[3]['y'] === 408.0,
    'frame-relative centres on a 3-column grid of 200 with gaps of 24, below the title band (24 + 100, 60 + 24 + 100)');
$f = item_row($fid);
ok((float) $f['width'] === 696.0 && (float) $f['height'] === 532.0 && (float) $f['x'] - (float) $f['width'] / 2 === 300.0 && (float) $f['y'] - (float) $f['height'] / 2 === 150.0,
    'the frame grew in the same batch to hold the block and a gap, its top-left fixed (400 × 300 → 696 × 532)');
$seq = (int) $j['record_id'];
$op = q('SELECT source, agent_run_id, member_id, summary FROM board_ops WHERE seq = :s', ['s' => $seq])[0];
$sum = json_decode((string) $op['summary'], true);
ok($op['source'] === 'agent' && (int) $op['agent_run_id'] === $RUN && (int) $op['member_id'] === 40 && $sum['counts'] === ['create' => 5, 'update' => 1],
    'ONE batch: source agent, the run, the agent as the member; record_id is its seq');
ok($sum['bbox'][0] >= 300 && $sum['bbox'][1] >= 150 && $sum['bbox'][2] <= 996 && $sum['bbox'][3] <= 682, 'the summary\'s box lies inside the frame');
ok(json_decode((string) $rows[0]['style'], true) === ['fillColor' => 'light_green'], 'the colour is the sticky\'s fill (decision 4)');
$log = activity('item.create', $since);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === $RUN && $log[0]['board_id'] === $b && json_decode((string) $log[0]['after'], true)['seq'] === $seq,
    'one item.create row: the agent\'s, with the run, the board and the seq');

// in empty space below the board
$ext = q("SELECT min((bbox[1])[0]) AS x1, max((bbox[0])[1]) AS y2 FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL", ['b' => $b])[0];
$r = $act('/items/create.php', ['type' => 'shape', 'texts' => ['A', 'B'], 'layout' => 'row', 'shape' => 'ellipse', 'color' => '#fac710']);
$j = body_json($r);
$two = q("SELECT x, y, width, height, data, style, parent_id FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) ORDER BY x", ['i' => pg_array_literal($j['items'] ?? [])]);
ok($r['code'] === 200 && str_contains($j['did'], 'below the board') && count($two) === 2 && (float) $two[0]['x'] === (float) $ext['x1'] + 100 && (float) $two[0]['y'] === (float) $ext['y2'] + 200 + 60
   && (float) $two[1]['x'] === (float) $ext['x1'] + 324 && $two[0]['parent_id'] === null,
    'with no place: a row below everything on the board, its top-left at (extent.x1, extent.y2 + 200)');
ok(json_decode((string) $two[0]['data'], true)['shape'] === 'ellipse' && json_decode((string) $two[0]['style'], true)['fillColor'] === '#fac710' && (float) $two[0]['width'] === 200.0 && (float) $two[0]['height'] === 120.0,
    'shapes: Miro\'s kind, the fill, 200 × 120');
$r = $act('/items/create.php', ['type' => 'text', 'texts' => ['Note at a place'], 'x' => '-900', 'y' => '-900']);
$t = item_row(body_json($r)['items'][0] ?? '00000000-0000-4000-8000-000000000000');
ok($r['code'] === 200 && (float) ($t['x'] ?? 0) === -900.0 && (float) ($t['y'] ?? 0) === -900.0 && json_decode((string) ($t['style'] ?? '[]'), true) === [] && str_contains(body_json($r)['did'], 'at -900, -900'),
    'at x, y: the first item\'s centre there (a text with no colour: style {} — an empty array never reaches the column)');
$e = $act('/items/create.php', ['type' => 'sticky_note']);
ok($e['code'] === 200 && plain(body_json($e)['items'][0]) === '', 'no texts: one empty sticky');

$retryForm = ['type' => 'sticky_note', 'texts' => ['Retried'], 'x' => '3000', 'y' => '3000'];
$r1 = $act('/items/create.php', $retryForm);
$r2 = $act('/items/create.php', $retryForm);
ok(body_json($r1)['record_id'] === body_json($r2)['record_id'] && body_json($r2)['duplicate'] === true && body_json($r2)['items'] === body_json($r1)['items']
   && (int) one("SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid) AND plain_text = 'Retried'", ['b' => $b]) === 1,
    'the kernel retrying a call commits it once and answers the first commit\'s items (the batch id from the run\'s request, the path and the params)');
$r3 = $act('/items/create.php', ['type' => 'sticky_note', 'texts' => ['Retried'], 'x' => '3300', 'y' => '3000']);
ok(body_json($r3)['record_id'] !== body_json($r1)['record_id'], 'a different call in the same run is a new batch (canvas-core decision 6, corrected)');

$bad = [
    [['type' => 'balloon'], 'type'], [['type' => 'sticky_note', 'color' => '#ff0000'], 'colour'], [['type' => 'shape', 'shape' => 'blob'], 'shape'],
    [['type' => 'sticky_note', 'x' => '5'], 'together'], [['type' => 'sticky_note', 'columns' => '40'], 'columns'], [['type' => 'sticky_note', 'frame' => uuid4()], 'no frame'],
    [['type' => 'sticky_note', 'texts' => array_fill(0, 51, 'x')], 'At most 50'],
];
$wrong = [];
foreach ($bad as [$form, $word]) {
    $r = $act('/items/create.php', $form);
    if ($r['code'] !== 422 || !str_contains($r['body'], $word)) { $wrong[] = json_encode($form) . " {$r['code']} {$r['body']}"; }
}
ok($wrong === [], 'bad fields are refused with their sentence: the type, a sticky\'s colour, the shape, x without y, the columns, a frame not on the board, over 50' . ($wrong ? ': ' . implode('; ', $wrong) : ''));
ok($act('/items/create.php', ['type' => 'sticky_note'], 42)['code'] === 403, 'an agent at view: 403 "You can see this board but not change it"');
ok(agent_act(41, $RUN, '/items/create.php', ['board' => $b, 'type' => 'sticky_note'])['code'] >= 401, 'an agent with no Boards grant is refused');
ok(req('POST', '/items/create.php', ['headers' => as_agent(run_token(40, $RUN)), 'form' => ['board' => make_board($jp, 'SMOKE Not shared'), 'type' => 'sticky_note']])['code'] === 404,
    'a board not shared with the agent: 404');

echo "   update\n";
$three = array_slice($ids, 0, 3);
$since = last_activity_id();
$r = $act('/items/update.php', ['items' => $three, 'color' => 'pink', 'front' => 'yes']);
$j = body_json($r);
$z = q("SELECT id, z, style FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL ORDER BY z DESC LIMIT 3", ['b' => $b]);
ok($r['code'] === 200 && str_contains($j['did'], 'Changed 3 stickies: colour, to the front') && count(array_intersect(array_column($z, 'id'), $three)) === 3,
    'colour and front on three items: "' . ($j['did'] ?? $r['body']) . '" — they are the board\'s highest now');
$zs = array_column(q("SELECT id, z FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) ORDER BY z", ['i' => pg_array_literal($three)]), 'id');
ok($zs === $three && count(array_filter(array_column($z, 'style'), static fn ($s) => json_decode((string) $s, true)['fillColor'] === 'pink')) === 3, 'their order among themselves kept; each pink');
ok(count(activity('item.update', $since)) === 1 && json_decode((string) activity('item.update', $since)[0]['after'], true)['fields'] === ['style', 'z'], 'one item.update row naming the fields');
$r = $act('/items/update.php', ['items' => array_slice($ids, 0, 2), 'text' => 'Both']);
ok($r['code'] === 422 && str_contains($r['body'], 'Give one item to set its text or its place'), 'text on two refused (decision 2)');
$r = $act('/items/update.php', ['items' => [$ids[4]], 'text' => 'Edited by the Expert', 'frame' => 'none']);
$row = item_row($ids[4]);
ok($r['code'] === 200 && plain($ids[4]) === 'Edited by the Expert' && $row['parent_id'] === null && (float) $row['x'] === 300 + 348.0 && (float) $row['y'] === 150 + 408.0,
    'one item\'s text, and out of its frame onto the canvas where it stands (its coordinates made absolute)');
$r = $act('/items/update.php', ['items' => [$ids[4]], 'frame' => $fid, 'x' => '50', 'y' => '60']);
$row = item_row($ids[4]);
ok($r['code'] === 200 && $row['parent_id'] === $fid && (float) $row['x'] === 50.0 && (float) $row['y'] === 60.0, 'into a frame at x, y relative to it');
ok($act('/items/update.php', ['items' => [uuid4()], 'color' => 'pink'])['code'] === 404 && str_contains($act('/items/update.php', ['items' => [uuid4()], 'color' => 'pink'])['body'], 'is not on this board'),
    'an item not on the board: 404 naming it');
ok($act('/items/update.php', ['items' => [$ids[0]]])['code'] === 422, 'nothing to change: 422');
$r = $act('/items/update.php', ['items' => [$ids[0]], 'locked' => 'yes']);
$r2 = $act('/items/update.php', ['items' => [$ids[0]], 'color' => 'blue']);
ok($r['code'] === 200 && $r2['code'] === 422 && str_contains($r2['body'], 'is locked'), 'a locked item is not changed (the database\'s rule) until unlocked');
$act('/items/update.php', ['items' => [$ids[0]], 'locked' => 'no']);

echo "   delete\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['item_delete_batch']['approval'] ?? null) === 'deletion' && ($reg['actions']['item_create_batch']['approval'] ?? null) === null,
    'the registry marks item_delete_batch `deletion` (the kernel pauses an agent there); create and update are free');
$since = last_activity_id();
$r = $act('/items/delete.php', ['items' => [$ids[1], $ids[2]]]);
$j = body_json($r);
ok($r['code'] === 200 && $j['did'] === 'Deleted 2 items — ops_undo brings them back' && (int) one("SELECT count(*) FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) AND deleted_at IS NOT NULL", ['i' => pg_array_literal([$ids[1], $ids[2]])]) === 2,
    'delete two: "' . ($j['did'] ?? $r['body']) . '"');
ok(count(activity('item.delete', $since)) === 1, 'one item.delete row');
$r = $act('/items/delete.php', ['items' => [$fid], 'keep_children' => 'yes']);
$kids = q("SELECT parent_id, deleted_at FROM board_items WHERE id = ANY (CAST(:i AS uuid[])) AND deleted_at IS NULL", ['i' => pg_array_literal([$ids[0], $ids[3], $ids[4]])]);
ok($r['code'] === 200 && count($kids) === 3 && array_filter(array_column($kids, 'parent_id')) === [] && item_row($fid)['deleted_at'] !== null,
    'a frame deleted keeping its children: they stay on the canvas where they stood');
finish();
