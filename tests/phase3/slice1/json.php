<?php
/**
 * Proof 8, JSON mode (the manifest's result contract): every screen of the slice answers its data to JSON, and every handler answers
 * {ok, did, record_id, location} — record_id the board for a board action, the folder for a folder action — or an error with its words.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
[$jp, ] = sign_on(26);
echo "8. JSON\n";
$id = make_board($jp, 'SMOKE JSON');
$fid = (int) body_json(act($jp, '/folders/save.php', ['name' => 'SMOKE JSON folder']))['record_id'];
$screens = ['/boards' => 'rows', '/boards/new' => 'values', "/boards/$id/settings" => 'board', "/boards/$id/share" => 'rows', '/folders' => 'rows', "/folders/$fid" => 'folder',
            '/folders/new' => 'values', "/folders/$fid/edit" => 'values', '/trash' => 'rows'];
$bad = [];
foreach ($screens as $u => $key) {
    $r = page($jp, $u, $json);
    if ($r['code'] !== 200 || !array_key_exists($key, body_json($r)['data'] ?? [])) { $bad[] = "$u {$r['code']}"; }
}
ok($bad === [], 'the nine screens answer JSON with their data' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$b = body_json(page($jp, "/boards/$id/settings", $json))['data']['board'];
ok($b['board_id'] === $id && $b['my_level'] === 'full' && $b['everyone_level'] === 'none' && $b['url'] === '/boards/' . $id && !array_key_exists('deleted_at', $b), 'a board\'s JSON is a whitelist: its id, level, everyone\'s level, its URL — no raw row');
$steps = [
    ['/boards/save.php', ['board' => $id, 'title' => 'SMOKE JSON 2'], $id],
    ['/boards/lock.php', ['board' => $id, 'locked' => 'no'], $id],
    ['/boards/share-set.php', ['board' => $id, 'member' => 28, 'level' => 'view'], $id],
    ['/boards/share-remove.php', ['board' => $id, 'member' => 28], $id],
    ['/boards/access-set.php', ['board' => $id, 'level' => 'view'], $id],
    ['/boards/favorite.php', ['board' => $id], $id],
    ['/boards/unfavorite.php', ['board' => $id], $id],
    ['/folders/add.php', ['folder' => $fid, 'board' => $id], $fid],
    ['/folders/remove.php', ['folder' => $fid, 'board' => $id], $fid],
    ['/folders/save.php', ['folder' => $fid, 'name' => 'SMOKE JSON folder 2'], $fid],
    ['/boards/transfer.php', ['board' => $id, 'new_owner' => 28], $id],
];
$bad = [];
foreach ($steps as [$file, $form, $rid]) {
    $r = act($jp, $file, $form);
    $j = body_json($r);
    if ($r['code'] !== 200 || ($j['ok'] ?? false) !== true || (string) ($j['record_id'] ?? '') !== (string) $rid || ($j['did'] ?? '') === '' || ($j['location'] ?? '') === '') { $bad[] = "$file {$r['code']} {$r['body']}"; }
}
ok($bad === [], 'eleven handlers answer {ok, did, record_id, location} with the record the manifest names' . ($bad ? ' — ' . implode(' | ', $bad) : ''));
[$jb, ] = sign_on(28);
foreach ([['/boards/delete.php', ['board' => $id], $id], ['/boards/restore.php', ['board' => $id], $id], ['/folders/delete.php', ['folder' => (int) body_json(act($jb, '/folders/save.php', ['name' => 'SMOKE Bea']))['record_id']], null]] as [$file, $form, $rid]) {
    $r = act($jb, $file, $form);
    ok($r['code'] === 200 && ($rid === null || body_json($r)['record_id'] === $rid), "$file answers ok" . ($rid ? ' with the board' : ' with the folder'));
}
$r = act($jp, '/boards/save.php', ['title' => 'SMOKE created by JSON']);
ok(preg_match('#^/boards/[0-9a-f-]{36}$#', (string) body_json($r)['location']) === 1 && str_ends_with((string) body_json($r)['location'], (string) body_json($r)['record_id']), 'a create\'s location ends in the new board\'s id');
$r = page($jp, '/boards?folder=abc', $json);
ok($r['code'] === 200, 'a malformed filter is ignored, never a 500');
finish();
