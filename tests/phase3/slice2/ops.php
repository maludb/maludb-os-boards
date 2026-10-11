<?php
/**
 * Proof 1 at the commit (docs/build-specs/canvas.md "Proof" 1; canvas-core.md "The op protocol"): a batch committed, retried (one seq,
 * `duplicate`), refused below edit (403), on a locked board for an editor (403), malformed (422), with an id taken (409), an item not on the
 * board (404); people only; per-property last-writer-wins and `replaced` at the database; ONE log row per batch named by its verbs — eight
 * fixtures, one per verb and a mixed one — a duplicate logging nothing; and the z keys: the PHP and TypeScript twins make the same keys.
 */
require __DIR__ . '/lib.php';
echo "1. the commit\n";
[$jp, ] = sign_on(26);
[$jb, ] = sign_on(28);
[$jd, ] = sign_on(30);
[$jl, ] = sign_on(31);
$b = make_board($jp, 'SMOKE Ops');
share_as(26, $b, 'member', 28, 'edit');
share_as(26, $b, 'member', 30, 'view');

$since = last_activity_id();
$batch = uuid4();
$op = item_op(['text' => 'Retry me']);
$r1 = ops_post($jb, $b, [$op], $batch);
$r2 = ops_post($jb, $b, [$op], $batch);
$a1 = body_json($r1);
$a2 = body_json($r2);
ok($r1['code'] === 200 && $a1['ok'] === true && $a1['duplicate'] === false && $a1['versions'][$op['item']['id']] === 1 && $a1['item_count'] === 1 && $a1['warnings'] === [],
    'a batch commits: its seq, versions, the item count, no warning');
ok($a1['inverse'] === [['op' => 'delete', 'ids' => [$op['item']['id']], 'cascade' => false]], 'the answer carries the batch\'s inverse, read from board_ops (decision 3)');
ok($r2['code'] === 200 && $a2['duplicate'] === true && $a2['seq'] === $a1['seq'] && (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid)', ['b' => $b]) === 1,
    'the same client_batch_id again: the first seq, duplicate, nothing twice');
ok(count(activity('item.create', $since)) === 1, 'a duplicate logs nothing');
ok($a2['inverse'] === $a1['inverse'], 'a duplicate still answers the inverse (a client whose first answer was lost can undo it)');

$r = ops_post($jd, $b, [item_op()]);
ok($r['code'] === 403 && str_contains($r['body'], 'You can see this board but not change it'), 'below edit: 403 with the database\'s sentence');
$r = ops_post($jl, $b, [item_op()]);
ok($r['code'] === 404, 'a stranger: 404');
$r = req('POST', "/boards/$b/ops", ['jar' => $jb, 'json' => ['ops' => [item_op()]], 'headers' => ['Accept: application/json', 'X-CSRF-Token: wrong']]);
ok($r['code'] === 403 && str_contains($r['body'], 'csrf'), 'without the page\'s CSRF token: 403');
share_as(26, $b, 'agent', 40, 'edit');
$r = req('POST', "/boards/$b/ops", ['headers' => as_agent(run_token(40, 7001)), 'json' => ['ops' => [item_op()]]]);
ok($r['code'] === 403 && str_contains($r['body'], 'for people'), 'an agent\'s token at the canvas\'s door: 403 — agents use /items/* (people only, ★1)');

pg("SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_lock('$b', 26, true) \\g /dev/null");
$r = ops_post($jb, $b, [item_op()]);
ok($r['code'] === 403 && str_contains($r['body'], 'locked'), 'a locked board, an editor: 403 "This board is locked; only its owners can change it."');
$r = ops_post($jp, $b, [item_op(['x' => 300])]);
ok($r['code'] === 200, 'a locked board, its owner (full): committed');
pg("SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_lock('$b', 26, false) \\g /dev/null");

$cases = [
    'an empty batch' => [[], 'at least one op'],
    'an op the database does not know' => [[['op' => 'explode', 'id' => $op['item']['id']]], 'no op called'],
    'a field an item does not have' => [[['op' => 'update', 'id' => $op['item']['id'], 'fields' => ['colour' => 'red']]], 'no field colour'],
    'an update naming no field' => [[['op' => 'update', 'id' => $op['item']['id'], 'fields' => (object) []]], 'names the fields'],
    'a create without an id' => [[['op' => 'create', 'item' => ['type' => 'sticky_note']]], 'names its id'],
];
foreach ($cases as $what => [$ops, $words]) {
    $r = ops_post($jb, $b, $ops);
    ok($r['code'] === 422 && str_contains($r['body'], $words), "malformed — $what: 422 \"…{$words}…\"");
}
$r = req('POST', "/boards/$b/ops", ['jar' => $jb, 'raw' => '{not json', 'headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jb)]]);
ok($r['code'] === 422 && str_contains($r['body'], 'not JSON'), 'a body that is not JSON: 422');
$r = ops_post($jb, $b, ['op' => 'create'], null);
ok($r['code'] === 422, 'ops that are not a list: 422');
$r = ops_post($jb, $b, [item_op()], 'not-a-uuid');
ok($r['code'] === 422 && str_contains($r['body'], 'uuid'), 'a client_batch_id that is not a uuid: 422');
$r = ops_post($jb, $b, [item_op(['type' => 'balloon'])]);
ok($r['code'] === 422, 'a type the board does not have: 422 (the column\'s rule)');
$r = ops_post($jb, $b, [item_op(['id' => $op['item']['id']])]);
ok($r['code'] === 409 && str_contains($r['body'], 'exists already'), 'an item id another item holds: 409 conflict (canvas.md\'s door)');
$r = ops_post($jb, $b, [['op' => 'update', 'id' => uuid4(), 'fields' => ['x' => 1]]]);
ok($r['code'] === 404 && str_contains($r['body'], 'is not on this board'), 'an item not on the board: 404 naming it');
$r = ops_post($jb, $b, [['op' => 'update', 'id' => $op['item']['id'], 'fields' => ['x' => 5]], ['op' => 'update', 'id' => uuid4(), 'fields' => ['x' => 1]]]);
ok($r['code'] === 404 && (float) item_row($op['item']['id'])['x'] === 0.0, 'a batch is all or nothing: a refused op takes the batch\'s others with it');

echo "   last writer wins, per property\n";
$id = $op['item']['id'];
$v0 = (int) item_row($id)['version'];
ops_post($jp, $b, [['op' => 'update', 'id' => $id, 'fields' => ['x' => 120], 'base_version' => $v0]]);
ops_post($jb, $b, [['op' => 'update', 'id' => $id, 'fields' => ['style' => ['fillColor' => 'pink']], 'base_version' => $v0]]);
$row = item_row($id);
ok((float) $row['x'] === 120.0 && json_decode((string) $row['style'], true)['fillColor'] === 'pink', 'two people, two properties, at once: both survive');
ops_post($jp, $b, [['op' => 'update', 'id' => $id, 'fields' => ['style' => ['borderColor' => '#000000']]]]);
ops_post($jb, $b, [['op' => 'update', 'id' => $id, 'fields' => ['style' => ['borderColor' => null]]]]);
ok(json_decode((string) item_row($id)['style'], true) === ['fillColor' => 'pink'], 'style merges by key; a null removes a key');
$v = (int) item_row($id)['version'];
$rt = body_json(ops_post($jp, $b, [['op' => 'update', 'id' => $id, 'fields' => ['data' => ['content' => [['type' => 'text', 'text' => ['content' => 'Priya wrote', 'link' => null], 'plain_text' => 'Priya wrote']]]], 'base_version' => $v]]));
ok($rt['replaced'] === [], 'text written from the latest version: nothing replaced');
$rb = body_json(ops_post($jb, $b, [['op' => 'update', 'id' => $id, 'fields' => ['data' => ['content' => [['type' => 'text', 'text' => ['content' => 'Bea overwrote', 'link' => null], 'plain_text' => 'Bea overwrote']]]], 'base_version' => $v]]));
ok(count($rb['replaced']) === 1 && $rb['replaced'][0]['id'] === $id && $rb['replaced'][0]['by'] === 26 && plain($id) === 'Bea overwrote', 'text written over someone\'s newer text: kept, and `replaced` names who (the toast\'s "You replaced Priya\'s edit")');

echo "   one log row a batch, named by its verbs\n";
$f = item_op(['text' => 'Log me', 'x' => 900]);
$fid = $f['item']['id'];
$g = item_op(['x' => 1200]);
$fixtures = [
    'item.create' => [$f],
    'item.move' => [['op' => 'update', 'id' => $fid, 'fields' => ['x' => 950, 'y' => 40]]],
    'item.move ' => [['op' => 'update', 'id' => $fid, 'fields' => ['z' => 'b1']]],
    'item.text' => [['op' => 'update', 'id' => $fid, 'fields' => ['data' => ['content' => [['type' => 'text', 'text' => ['content' => str_repeat('long words ', 30), 'link' => null], 'plain_text' => str_repeat('long words ', 30)]]]]]],
    'item.update' => [['op' => 'update', 'id' => $fid, 'fields' => ['style' => ['fillColor' => 'blue'], 'width' => 240]]],
    'item.delete' => [['op' => 'delete', 'ids' => [$fid]]],
    'item.restore' => [['op' => 'restore', 'ids' => [$fid]]],
    'item.batch' => [$g, ['op' => 'update', 'id' => $fid, 'fields' => ['y' => 300]]],
];
$bad = [];
foreach ($fixtures as $event => $ops) {
    $since = last_activity_id();
    $a = body_json(ops_post($jb, $b, $ops));
    $rows = q("SELECT action, entity_type, board_id, after, source FROM activity_log WHERE id > :s AND action LIKE 'item.%'", ['s' => $since]);
    $after = json_decode((string) ($rows[0]['after'] ?? '{}'), true);
    if (count($rows) !== 1 || $rows[0]['action'] !== trim($event) || $rows[0]['board_id'] !== $b || $rows[0]['entity_type'] !== 'board' || $rows[0]['source'] !== 'web'
        || ($after['seq'] ?? 0) !== $a['seq'] || !isset($after['counts'], $after['types']) || !array_key_exists('box', $after)) {
        $bad[] = trim($event) . ' → ' . json_encode($rows);
    }
    if (trim($event) === 'item.text' && (($after['item'] ?? '') !== $fid || mb_strlen((string) ($after['text'] ?? '')) !== 200)) { $bad[] = 'item.text payload ' . json_encode($after); }
}
ok($bad === [], 'eight batches, eight rows, each named by its verbs (create, move by x/y and by z, text, update, delete, restore, mixed → item.batch) with the seq, counts, types and box' . ($bad ? ': ' . implode('; ', $bad) : ''));
ok(count(q("SELECT 1 FROM activity_log WHERE board_id = CAST(:b AS uuid) AND action = 'item.text' AND (after->>'text') LIKE 'long words%' AND length(after->>'text') = 200", ['b' => $b])) === 1,
    'item.text logs the item and the first 200 characters of the new text, never more');

echo "   the cap\n";
pg("UPDATE bd_settings SET item_soft_budget = 100, item_hard_cap = 150");
$have = (int) one('SELECT item_count FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
$many = [];
for ($i = 0; $i < 110 - $have; $i++) { $many[] = item_op(['x' => $i * 10]); }
$a = body_json(ops_post($jb, $b, $many));
ok(isset($a['warnings'][0]) && str_contains($a['warnings'][0], 'boards stay quick under 100'), 'past the soft budget: the answer warns (' . ($a['item_count'] ?? '?') . ' items)');
$many = [];
for ($i = 0; $i < 45; $i++) { $many[] = item_op(['y' => 900 + $i]); }
$r = ops_post($jb, $b, $many);
ok($r['code'] === 422 && str_contains($r['body'], 'This board is full') && (int) one('SELECT item_count FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]) === 110,
    'past the hard cap: a create is refused by the database (422) and nothing of the batch is kept');
pg("UPDATE bd_settings SET item_soft_budget = 4000, item_hard_cap = 10000");

echo "   the z keys: the PHP and TypeScript twins\n";
require dirname(__DIR__, 3) . '/app/features/items/fractional.php';
$frac = getenv('BD_FRACTIONAL_JS') ?: '';
if ($frac === '' || !is_file($frac)) {
    ok(false, 'the TypeScript twin was built for the comparison (run.sh builds it)');
} else {
    $cases = [[null, null], ['a0', null], [null, 'a0'], ['a0', 'a1'], ['a1', 'a2'], ['az', null], ['a0V', 'a1'], ['Zz', 'a0'], ['b00', null], ['a0', 'a0V'], ['a0000001', 'a0001']];
    $php = [];
    foreach ($cases as [$x, $y]) { $php[] = z_between($x, $y); }
    $k = null; for ($i = 0; $i < 3000; $i++) { $k = z_next($k); } $php[] = $k;
    $k = 'a0'; for ($i = 0; $i < 200; $i++) { $k = z_between(null, $k); } $php[] = $k;
    $a = 'a0'; $c = 'a1'; for ($i = 0; $i < 40; $i++) { $c = z_between($a, $c); } $php[] = $c;
    $js = trim((string) shell_exec('node ' . escapeshellarg(__DIR__ . '/zkeys.mjs') . ' ' . escapeshellarg($frac) . ' ' . escapeshellarg(json_encode($cases))));
    ok($js === json_encode($php), 'the same keys from both: ' . count($php) . ' cases, 3,000 placed after the highest (' . $php[11] . '), 200 before the lowest, 40 between two');
    ok(strlen($php[11]) <= 5, 'placing after the highest grows a key slowly: 3,000 keys, the last ' . strlen($php[11]) . ' characters');
}
finish();
