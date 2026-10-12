<?php
/**
 * Slice 9 — items_arrange (agents-search.md "Proof" 6): a grid of six inside a frame (frame-relative, the frame grown), a column where the items stand, a cluster by colour, a row, the refusals
 * (a frame or a connector among the items, items of two frames with no frame named, a locked item), ONE batch whose record_id is its seq, and ops_undo taking it back.
 * Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jb] = sign_on(28);
[$jd] = sign_on(30);
$since = last_activity_id();

echo "6. items_arrange\n";
[$b, $ids] = board_with_notes($jp, 'SMOKE Arrange board', 6);
share_as(26, $b, 'member', 28, 'edit');
share_as(26, $b, 'member', 30, 'comment');
$f = frame_op('Pile', 0, 1000, 400, 300);                  // small: the block will not fit
ops_post($jp, $b, [$f]);
$frame = $f['item']['id'];
$seq0 = (int) one('SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
$r = arrange_as($jp, $b, $ids, 'grid', ['frame' => $frame]);
$j = body_json($r);
$seq = (int) one('SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
ok($r['code'] === 200 && $j['ok'] === true && $j['record_id'] === $seq && $seq === $seq0 + 1 && $j['did'] === 'Arranged 6 items as a grid in the frame' && $j['grown'] === true && $j['layout'] === 'grid', 'a grid of 6 in the frame: ONE batch, record_id the seq (' . $seq . ')');
$fr = item_row($frame);
// 6 notes: 3 columns (ceil(sqrt 6)), cells 200x200, gap 40 -> block 680 x 440; frame needs 680+80 = 760 x 520, grown to the right and down from its top-left (-200, 850)
ok((float) $fr['width'] === 760.0 && (float) $fr['height'] === 520.0 && (float) $fr['x'] === 180.0 && (float) $fr['y'] === 1110.0, 'the frame grew to hold the block plus 40 on every side, its top-left fixed (' . $fr['width'] . ' x ' . $fr['height'] . ' at ' . $fr['x'] . ', ' . $fr['y'] . ')');
$pos = array_map('place_of', $ids);
$want = [[140, 140], [380, 140], [620, 140], [140, 380], [380, 380], [620, 380]];       // 40 + 100 + col * 240
ok(array_map(static fn ($p) => [$p[0], $p[1]], $pos) == $want && count(array_unique(array_column($pos, 2))) === 1 && $pos[0][2] === $frame, 'frame-relative centres in the order given, three columns, and every item is in the frame');
$ops = q('SELECT source, summary FROM board_ops WHERE board_id = CAST(:b AS uuid) AND seq = :s', ['b' => $b, 's' => $seq]);
ok($ops[0]['source'] === 'web' && json_decode($ops[0]['summary'], true)['counts']['update'] === 7, 'the batch is 7 updates (6 items and the frame), source web');
$lg = activity('item.move', $since);
$la = json_decode((string) end($lg)['after'], true);
ok(count($lg) === 1 && (string) $lg[0]['board_id'] === $b && $la['seq'] === $seq && $la['layout'] === 'grid' && $la['frame'] === $frame && $la['items'] === 6 && $la['grown'] === true, 'item.move is logged with the seq, the layout, the frame and the count');
// undo
$r = post_as($jp, '/items/undo.php', ['board' => $b]);
$aft = array_map('place_of', $ids);
ok($r['code'] === 200 && $aft[0][0] === 0.0 && $aft[0][2] === null && (float) item_row($frame)['width'] === 400.0, 'ops_undo takes the arrangement back: items where they were, out of the frame, the frame its size');
// a column where they stand
$r = arrange_as($jp, $b, array_slice($ids, 0, 3), 'column');
$p = array_map('place_of', array_slice($ids, 0, 3));
ok($r['code'] === 200 && $p[0] == [0.0, 0.0, null] && $p[1] == [0.0, 240.0, null] && $p[2] == [0.0, 480.0, null], 'a column where they stand: from the present top-left, 240 apart');
$r = arrange_as($jp, $b, array_slice($ids, 3, 3), 'row', ['gap' => 100]);
$p = array_map('place_of', array_slice($ids, 3, 3));
ok($r['code'] === 200 && $p[0][1] === $p[1][1] && $p[1][1] === $p[2][1] && $p[1][0] - $p[0][0] === 300.0, 'a row with a gap of 100: centres 300 apart');
$r = arrange_as($jp, $b, $ids, 'grid', ['columns' => 2, 'gap' => 20]);
$p = array_map('place_of', $ids);
ok($r['code'] === 200 && $p[1][0] - $p[0][0] === 220.0 && $p[2][1] - $p[0][1] === 220.0 && $p[2][0] === $p[0][0], 'a grid of 2 columns with a gap of 20');
// cluster by colour: two colours, two blocks
[$c, $cid] = board_with_notes($jp, 'SMOKE Cluster board', 5, ['light_yellow', 'light_blue', 'light_yellow', 'light_blue', 'light_yellow']);
$r = arrange_as($jp, $c, $cid, 'cluster');
$p = array_map('place_of', $cid);
// yellow = items 0,2,4 (3 -> 2 columns), blue = 1,3 (2 -> 2 columns); cells 200, gap 40: yellow block 440 wide; blue starts at 440 + 80 = 520
$yellowMax = max($p[0][0], $p[2][0], $p[4][0]);
$blueMin = min($p[1][0], $p[3][0]);
ok($r['code'] === 200 && $blueMin - $yellowMax === 280.0 && $p[0][1] === $p[1][1], 'a cluster by colour: two blocks side by side 2 x gap apart (blue starts 280 right of the last yellow centre)');
ok($p[0] == [0.0, 0.0, null] && $p[2] == [240.0, 0.0, null] && $p[4] == [0.0, 240.0, null] && $p[1][0] === 520.0 && $p[3][0] === 760.0, 'inside a block the items are a grid in their order (two columns), the colours in order of first appearance');
$sum = q("SELECT summary FROM board_ops WHERE board_id = CAST(:b AS uuid) ORDER BY seq DESC LIMIT 1", ['b' => $c]);
ok(json_decode($sum[0]['summary'], true)['counts']['update'] === 5, 'the cluster is one batch of 5 updates');

echo "   the refusals\n";
$con = ['op' => 'create', 'item' => ['id' => uuid4(), 'type' => 'connector', 'start_item_id' => $ids[0], 'end_item_id' => $ids[1], 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'z' => 'a9',
    'style' => ['endStrokeCap' => 'arrow'], 'data' => ['shape' => 'straight']]];
ops_post($jp, $b, [$con]);
$before = (int) one('SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
$r = arrange_as($jp, $b, [$ids[0], $frame], 'grid');
ok($r['code'] === 422 && msg($r) === 'Arrange moves items, not frames or connectors', 'a frame among the items: 422 "Arrange moves items, not frames or connectors"');
$r = arrange_as($jp, $b, [$ids[0], $con['item']['id']], 'row');
ok($r['code'] === 422 && msg($r) === 'Arrange moves items, not frames or connectors', 'a connector among them: the same 422');
$f2 = frame_op('Other', 800, 1000, 400, 300);
$g1 = item_op(['text' => 'In pile', 'x' => 100, 'y' => 100, 'parent_id' => $frame, 'z' => 'b1']);
$g2 = item_op(['text' => 'In other', 'x' => 100, 'y' => 100, 'parent_id' => $f2['item']['id'], 'z' => 'b2']);
ops_post($jp, $b, [$f2, $g1, $g2]);
$r = arrange_as($jp, $b, [$g1['item']['id'], $g2['item']['id']], 'grid');
ok($r['code'] === 422 && msg($r) === 'Name a frame, or arrange items that share one', 'items of two frames with no frame named: 422 "Name a frame, or arrange items that share one"');
$r = arrange_as($jp, $b, [$g1['item']['id'], $ids[0]], 'grid');
ok($r['code'] === 422 && msg($r) === 'Name a frame, or arrange items that share one', 'a frame\'s item and a free one: the same 422');
$r = arrange_as($jp, $b, [$g1['item']['id'], $g2['item']['id']], 'grid', ['frame' => $frame]);
ok($r['code'] === 200 && place_of($g2['item']['id'])[2] === $frame, 'naming a frame gathers items from two frames into it');
$r = arrange_as($jp, $b, $ids, 'grid', ['frame' => uuid4()]);
ok($r['code'] === 422 && str_contains(msg($r), 'There is no frame'), 'a frame that is not there: 422');
$r = arrange_as($jp, $b, [uuid4()], 'grid');
ok($r['code'] === 404, 'an item that is not on the board: 404 (' . msg($r) . ')');
$r = arrange_as($jp, $b, $ids, 'spiral');
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['layout']), 'a layout that is not one: 422 with the field');
$r = arrange_as($jp, $b, [], 'grid');
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['items']), 'no items: 422 with the field');
$r = arrange_as($jp, $b, $ids, 'grid', ['columns' => 40]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['columns']), 'columns past 20: 422');
$r = arrange_as($jp, $b, $ids, 'grid', ['gap' => 900]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['gap']), 'a gap past 500: 422');
pg("UPDATE board_items SET locked = true WHERE id = '" . $ids[2] . "'");
$seqL = (int) one('SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
$r = arrange_as($jp, $b, $ids, 'column');
ok($r['code'] >= 400 && $r['code'] < 500 && (int) one('SELECT last_seq FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === $seqL, 'a locked item among them refuses the whole batch (' . $r['code'] . ': ' . msg($r) . ') — nothing moved');
pg("UPDATE board_items SET locked = false WHERE id = '" . $ids[2] . "'");
$r = arrange_as($jd, $b, $ids, 'column');
ok($r['code'] === 403, 'a Viewer-level (comment) caller cannot: 403');
$r = req('POST', '/items/arrange.php', ['jar' => $jp, 'headers' => ['Accept: application/json'], 'form' => ['board' => $b, 'items' => $ids, 'layout' => 'row']]);
ok($r['code'] === 403, 'a session POST without the CSRF token: 403');
$lone = q("SELECT count(*) AS c FROM board_ops WHERE board_id = CAST(:b AS uuid) AND seq > :s", ['b' => $b, 's' => $before]);
ok((int) $lone[0]['c'] === 2, 'the refusals above wrote no batch (only the two that succeeded since: the frames\' own and the gathering)');

echo "   an agent arranges, once\n";
share_as(26, $b, 'agent', FACILITATOR, 'edit');
run_facts(9601, FACILITATOR);
$a = agent_act(FACILITATOR, 9601, '/items/arrange.php', ['board' => $b, 'items' => array_slice($ids, 0, 4), 'layout' => 'grid']);
$aj = body_json($a);
ok($a['code'] === 200 && $aj['ok'] === true && (int) $aj['record_id'] > 0, 'the Facilitator arranges under a run token');
$srcRow = q("SELECT source, member_id, agent_run_id FROM board_ops WHERE seq = :s AND board_id = CAST(:b AS uuid)", ['s' => $aj['record_id'], 'b' => $b])[0];
ok($srcRow['source'] === 'agent' && (int) $srcRow['member_id'] === FACILITATOR && (int) $srcRow['agent_run_id'] === 9601, 'the batch is source agent, as the agent, with its run');
$a2 = agent_act(FACILITATOR, 9601, '/items/arrange.php', ['board' => $b, 'items' => array_slice($ids, 0, 4), 'layout' => 'grid']);
ok($a2['code'] === 200 && body_json($a2)['record_id'] === $aj['record_id'] && body_json($a2)['duplicate'] === true, 'the same call again (a retry) commits once: the same record_id, duplicate');
$lg = q("SELECT * FROM activity_log WHERE action = 'item.move' AND source = 'agent' AND agent_run_id = 9601");
ok(count($lg) === 1, 'and logs once, as the agent');
finish();
