<?php
/**
 * Proofs 3, 5 (at the database), 6 (the Frames panel's door), 7 and 8 (docs/build-specs/connectors-frames.md "Proof"): the agents' actions with
 * an agent's run token (JSON) — connector_create with a caption and both arrows, to a connector and across boards refused, frame_create around
 * three stickies (two from another frame), around a frame refused "Frames do not nest", in empty space; one log row each; frame_read's SQL
 * (frame-relative children, the absolute data-cx), item_connections; deleting an item takes its connectors in the same batch and the inverse
 * brings both back; a frame deleted keeping or taking its children.
 */
require __DIR__ . '/lib.php';
echo "7. the agents' connectors and frames\n";
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Agents connect');
$other = make_board($jp, 'SMOKE Elsewhere');
share_as(26, $b, 'agent', 40, 'edit');
share_as(26, $other, 'agent', 40, 'edit');
$RUN = 8401;
kernel_state(function ($s) use ($RUN) { $s['facts'][(string) $RUN] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => $RUN, 'request_id' => 'r-' . $RUN,
    'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
$act = static fn (string $file, array $form, string $board = '') => agent_act(40, $RUN, $file, ['board' => $board ?: $b] + $form);

$s1 = item_op(['text' => 'Shipped late', 'x' => 0, 'y' => 0, 'z' => 'a1']);
$s2 = item_op(['text' => 'Retro actions', 'x' => 600, 'y' => 0, 'z' => 'a2']);
$s3 = item_op(['text' => 'Third', 'x' => 1200, 'y' => 0, 'z' => 'a3']);
$far = item_op(['text' => 'On another board', 'x' => 0, 'y' => 0]);
ops_post($jp, $b, [$s1, $s2, $s3]);
ops_post($jp, $other, [$far]);
[$id1, $id2, $id3] = [$s1['item']['id'], $s2['item']['id'], $s3['item']['id']];

$since = last_activity_id();
$r = $act('/items/connect.php', ['from' => $id1, 'to' => $id2, 'caption' => 'causes', 'arrow' => 'both', 'shape' => 'curved']);
$j = body_json($r);
$k = q("SELECT * FROM board_items WHERE id = CAST(:i AS uuid)", ['i' => $j['connector'] ?? '00000000-0000-4000-8000-000000000000'])[0] ?? [];
$st = json_decode((string) ($k['style'] ?? '{}'), true);
$da = json_decode((string) ($k['data'] ?? '{}'), true);
ok($r['code'] === 200 && $j['did'] === 'Connected Shipped late → Retro actions' && $k['start_item_id'] === $id1 && $k['end_item_id'] === $id2,
    'connector_create: "' . ($j['did'] ?? $r['body']) . '" — joined start to end');
ok($st['startStrokeCap'] === 'arrow' && $st['endStrokeCap'] === 'arrow' && $da['shape'] === 'curved' && $da['captions'] === [['content' => 'causes', 'position' => 0.5]]
   && $da['startSnap'] === 'auto' && $da['endSnap'] === 'auto', 'arrow both → both caps; the shape; the caption as captions[0]; the snaps auto');
ok((float) $k['x'] === 0.0 && (float) $k['width'] === 0.0 && one("SELECT bbox ~= box(point(-100,-100), point(700,100)) FROM board_items WHERE id = CAST(:i AS uuid)", ['i' => $k['id']]) === true,
    'its own x, y, width, height stay 0; its box is its ends\' (the trigger\'s)');
$log = activity('item.connect', $since);
ok(count($log) === 1 && $log[0]['board_id'] === $b && json_decode((string) $log[0]['after'], true)['connector'] === $k['id'] && (int) $log[0]['agent_run_id'] === $RUN,
    'one item.connect row with the connector, the board and the run');
$r = $act('/items/connect.php', ['from' => $id1, 'to' => $k['id']]);
ok($r['code'] === 422 && str_contains($r['body'], 'A connector joins items on its own board'), 'to a connector: refused with the database\'s sentence');
$r = $act('/items/connect.php', ['from' => $id1, 'to' => $far['item']['id']]);
ok($r['code'] === 422 && str_contains($r['body'], 'A connector joins items on its own board'), 'across boards: refused');
ok($act('/items/connect.php', ['from' => $id1, 'to' => $id1])['code'] === 422 && $act('/items/connect.php', ['from' => $id1, 'to' => $id2, 'shape' => 'zigzag'])['code'] === 422,
    'to itself, or a shape it does not have: refused');

// frames
$fx = frame_op('Old frame', 2000, 0, 800, 600);
ops_post($jp, $b, [$fx, ['op' => 'update', 'id' => $id2, 'fields' => ['parent_id' => $fx['item']['id'], 'x' => 100, 'y' => 100]],
                       ['op' => 'update', 'id' => $id3, 'fields' => ['parent_id' => $fx['item']['id'], 'x' => 300, 'y' => 100]]]);
$abs = fn (string $id) => array_map('floatval', q("SELECT (bbox[1])[0] AS x1, (bbox[1])[1] AS y1, (bbox[0])[0] AS x2, (bbox[0])[1] AS y2 FROM board_items WHERE id = CAST(:i AS uuid)", ['i' => $id])[0]);
$before = [$abs($id1), $abs($id2), $abs($id3)];
$since = last_activity_id();
$r = $act('/items/frame.php', ['title' => 'Went well', 'around' => [$id1, $id2, $id3]]);
$j = body_json($r);
$f = item_row($j['frame'] ?? '00000000-0000-4000-8000-000000000000');
$x1 = min(array_column($before, 'x1')) - 40; $y1 = min(array_column($before, 'y1')) - 80; $x2 = max(array_column($before, 'x2')) + 40; $y2 = max(array_column($before, 'y2')) + 40;
ok($r['code'] === 200 && $j['did'] === 'Made the frame Went well around 3 items' && abs((float) $f['width'] - ($x2 - $x1)) < 0.01 && abs((float) $f['height'] - ($y2 - $y1)) < 0.01
   && abs((float) $f['x'] - ($x1 + $x2) / 2) < 0.01, 'frame_create around three stickies: "' . ($j['did'] ?? $r['body']) . '" — their union + 40 around, 40 more on top');
$kids = q("SELECT id, parent_id FROM board_items WHERE id = ANY (CAST(:i AS uuid[]))", ['i' => pg_array_literal([$id1, $id2, $id3])]);
$after = [$abs($id1), $abs($id2), $abs($id3)];
ok(count(array_filter($kids, fn ($x) => $x['parent_id'] === $f['id'])) === 3 && $after == $before, 'it takes all three in — two left the old frame — and none of them moves on the canvas');
ok(json_decode((string) q('SELECT summary FROM board_ops WHERE seq = :s', ['s' => $j['record_id']])[0]['summary'], true)['counts'] === ['create' => 1, 'update' => 3]
   && count(activity('item.frame', $since)) === 1 && json_decode((string) activity('item.frame', $since)[0]['after'], true)['adopted'] === 3,
    'ONE batch: the create, then three adoptions; one item.frame row (3 adopted)');
$r = $act('/items/frame.php', ['title' => 'Nested', 'around' => [$f['id']]]);
ok($r['code'] === 422 && str_contains($r['body'], 'Frames do not nest'), 'around a frame: refused "Frames do not nest"');
$r = $act('/items/frame.php', ['title' => 'Empty space']);
$e = item_row(body_json($r)['frame'] ?? '00000000-0000-4000-8000-000000000000');
$ext = q("SELECT min((bbox[1])[0]) AS x1, max((bbox[0])[1]) AS y2 FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL AND id <> CAST(:e AS uuid)", ['b' => $b, 'e' => $e['id'] ?? $b])[0];
ok($r['code'] === 200 && (float) $e['width'] === 1200.0 && (float) $e['height'] === 800.0 && abs((float) $e['y'] - 400 - ((float) $ext['y2'] + 200)) < 0.01
   && str_contains(body_json($r)['did'], 'below the board'), 'without around: 1200 × 800 in empty space below the board');
$r = $act('/items/frame.php', ['title' => 'Placed', 'x' => '-3000', 'y' => '-3000', 'width' => '400', 'height' => '300']);
ok($r['code'] === 200 && (float) item_row(body_json($r)['frame'])['x'] === -3000.0 && (float) item_row(body_json($r)['frame'])['width'] === 400.0, 'at x, y with a size');
ok($act('/items/frame.php', ['around' => [$id1]])['code'] === 422, 'a title is required');

echo "8. frame_read and item_connections\n";
as_member(26);
$svg = (string) json_decode((string) one('SELECT bd_region_svg(CAST(:b AS uuid), 0, 0, 0, 0, 400, CAST(:f AS uuid))', ['b' => $b, 'f' => $f['id']]), true)['svg'];
$tlx = (float) $f['x'] - (float) $f['width'] / 2;
$c1 = $abs($id1);
ok(str_contains($svg, 'data-frame="' . $f['id'] . '"') && preg_match('/data-id="' . $id1 . '"[^>]*data-cx="([-0-9.]+)"[^>]*x="([-0-9.]+)"/', $svg, $m) === 1
   && abs((float) $m[1] - ($c1['x1'] + $c1['x2']) / 2) < 0.1 && abs((float) $m[2] - ($c1['x1'] - $tlx)) < 0.1,
    "frame_read's SQL: the frame's children frame-relative (x from the frame's top-left) with their ABSOLUTE data-cx");
require_once dirname(__DIR__, 3) . '/app/features/items/connectors.php';
$conn = item_connections(pdo(), $id1);
$conn2 = item_connections(pdo(), $id2);
ok(count($conn['outgoing']) === 1 && $conn['outgoing'][0]['to_id'] === $id2 && $conn['outgoing'][0]['caption'] === 'causes' && $conn['incoming'] === []
   && count($conn2['incoming']) === 1 && $conn2['incoming'][0]['from_excerpt'] === 'Shipped late', 'item_connections: the sticky\'s outgoing connector to Retro actions (causes), the other\'s incoming');

echo "3. a deleted item takes its connectors\n";
$del = body_json(ops_post($jp, $b, [['op' => 'delete', 'ids' => [$id2]]]));
ok(item_row($k['id'])['deleted_at'] !== null && json_decode((string) q('SELECT summary FROM board_ops WHERE seq = :s', ['s' => $del['seq']])[0]['summary'], true)['types'] === ['connector' => 1, 'sticky_note' => 1],
    'deleting an item deletes its connector in the same batch (the database\'s cascade)');
ops_post($jp, $b, $del['inverse']);
ok(item_row($k['id'])['deleted_at'] === null && item_row($id2)['deleted_at'] === null && item_row($k['id'])['end_item_id'] === $id2, 'its inverse brings both back, joined again');

echo "5. a frame deleted, keeping or taking its contents\n";
$keep = body_json(ops_post($jp, $b, [['op' => 'delete', 'ids' => [$f['id']], 'keep_children' => true]]));
$a1 = $abs($id1);
ok(item_row($id1)['parent_id'] === null && item_row($id1)['deleted_at'] === null && $a1 == $c1, 'keep: the children stay where they stood, on the canvas');
ops_post($jp, $b, $keep['inverse']);
ok(item_row($id1)['parent_id'] === $f['id'], 'undone: back in the frame');
$all = body_json(ops_post($jp, $b, [['op' => 'delete', 'ids' => [$f['id']]]]));
ok(item_row($id1)['deleted_at'] !== null && item_row($id3)['deleted_at'] !== null, 'delete: the children go with it');
ops_post($jp, $b, $all['inverse']);
ok(item_row($id1)['deleted_at'] === null && item_row($id1)['parent_id'] === $f['id'], 'undone: all back');

echo "6. the Frames panel\n";
$seed = json_decode((string) file_get_contents(getenv('BD_S4_SEED')), true);
$panel = page($jp, '/boards/' . $seed['frames'] . '/rail?panel=frames');
preg_match_all('/data-item="([0-9a-f-]{36})" data-fit="1"/', $panel['body'], $m);
ok($panel['code'] === 200 && $m[1] === $seed['order'] && str_contains($panel['body'], '1 item') && str_contains($panel['body'], 'empty'),
    'the Frames panel lists the frames top to bottom, then left to right, with what each holds');
finish();
