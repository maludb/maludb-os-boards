<?php
/**
 * JSON mode (the manifest's result contract): the slice's two screens answer their data; the five actions answer {ok, did, record_id (the
 * batch's seq), location} or {error: {code, message}}; the doors that are not screens answer JSON to the island.
 */
require __DIR__ . '/lib.php';
echo "JSON\n";
[$jp, ] = sign_on(26);
$json = ['headers' => ['Accept: application/json']];
$b = make_board($jp, 'SMOKE JSON canvas');
$v = body_json(page($jp, "/boards/$b", $json));
ok(($v['data']['board']['board_id'] ?? '') === $b && $v['data']['board']['url'] === "/boards/$b" && !array_key_exists('deleted_at', $v['data']['board']) && !array_key_exists('thumbnail_attachment_id', $v['data']['board']),
    'board-view answers its board as a whitelist');
$o = body_json(page($jp, "/boards/$b/outline", $json));
ok(($o['data']['board_id'] ?? '') === $b && str_starts_with($o['data']['markdown'], '# SMOKE JSON canvas'), 'board-outline answers its Markdown');
$tok = person_token(26);
$hdr = ['Accept: application/json', 'X-Action-Token: ' . $tok];
$steps = [];
$c = req('POST', '/items/create.php', ['headers' => $hdr, 'form' => ['board' => $b, 'type' => 'sticky_note', 'texts' => ['J1', 'J2']]]);
$cj = body_json($c);
$steps[] = ['/items/create.php', $c];
$steps[] = ['/items/update.php', req('POST', '/items/update.php', ['headers' => $hdr, 'form' => ['board' => $b, 'items' => $cj['items'], 'color' => 'blue']])];
$steps[] = ['/items/delete.php', req('POST', '/items/delete.php', ['headers' => $hdr, 'form' => ['board' => $b, 'items' => [$cj['items'][0]]]])];
$steps[] = ['/items/undo.php', req('POST', '/items/undo.php', ['headers' => $hdr, 'form' => ['board' => $b, 'count' => 1]])];
$bad = [];
foreach ($steps as [$file, $r]) {
    $j = body_json($r);
    if ($r['code'] !== 200 || ($j['ok'] ?? false) !== true || !is_int($j['record_id'] ?? null) || ($j['did'] ?? '') === '' || !str_starts_with((string) ($j['location'] ?? ''), "/boards/$b")) {
        $bad[] = "$file {$r['code']} {$r['body']}";
    }
    if ($r['code'] === 200 && (int) one('SELECT count(*) FROM board_ops WHERE seq = :s AND board_id = CAST(:b AS uuid)', ['s' => (int) ($j['record_id'] ?? 0), 'b' => $b]) !== 1) { $bad[] = "$file: record_id is not the batch's seq"; }
}
ok($bad === [], 'the four item actions answer {ok, did, record_id = the batch\'s seq, location on the board}' . ($bad ? ': ' . implode('; ', $bad) : ''));
$p = ops_post($jp, $b, [item_op()]);
ok(is_int(body_json($p)['record_id'] ?? null) && body_json($p)['record_id'] === body_json($p)['seq'], 'ops_commit answers its seq as record_id');
$e = req('POST', '/items/update.php', ['headers' => $hdr, 'form' => ['board' => $b, 'items' => [uuid4()], 'color' => 'blue']]);
ok($e['code'] === 404 && (body_json($e)['error']['code'] ?? '') === 'not_found' && str_contains(body_json($e)['error']['message'], 'is not on this board'), 'a refusal answers {error: {code, message}}');
$e = req('POST', '/items/create.php', ['headers' => $hdr, 'form' => ['board' => $b, 'type' => 'nope']]);
ok($e['code'] === 422 && (body_json($e)['error']['fields']->type ?? body_json($e)['error']['fields']['type'] ?? '') !== '', 'a field refused names the field');
finish();
