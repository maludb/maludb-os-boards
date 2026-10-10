<?php
/**
 * Proof: the guard (sso-shell.md "Proof" 4; divergence 2): through the gate's fixture route (/__proof/gate.php — exactly as a slice's page
 * calls bd_board_or_404(), then the canvas's write path through bd_guard()): 404 "There is no such board." for a stranger, 403 with the
 * database's sentence for a Viewer asked for edit and for an owner's-only step, 422 for a malformed batch (22023) — never a 500. And
 * db_message() against the real schema: the sentences shown, PostgreSQL's own constraint and permission text never.
 */
require __DIR__ . '/lib.php';
require dirname(__DIR__, 2) . '/app/db.php';
ini_set('error_log', '/dev/null');                                     // db_message() logs every error it reads: these are expected
$json = ['headers' => ['Accept: application/json']];
$board = seed_board();
[$jp, ] = sign_on(26);
[$jd, ] = sign_on(30);
[$jb, ] = sign_on(28);
echo "4. The board's gate and the guard\n";
$g = static fn (string $jar, string $need, array $extra = [], array $o = []) => page($jar, '/__proof/gate.php?' . http_build_query(['board' => $board, 'need' => $need] + $extra), $o);
$r = $g($jp, 'full');
ok($r['code'] === 200 && str_contains($r['body'], '"my_level":"full"'), 'the owner at full: the gate admits her (my_level full)');
$r = $g($jd, 'view');
ok($r['code'] === 200 && str_contains($r['body'], '"my_level":"view"'), 'Dana, shared at view, asked for view: admitted');
$r = $g($jb, 'view');
ok($r['code'] === 404 && str_contains($r['body'], 'There is no such board.'), 'Bea, a stranger: 404 "There is no such board." — never forbidden');
$r = $g($jb, 'view', [], $json);
ok($r['code'] === 404 && (body_json($r)['error']['code'] ?? '') === 'not_found' && (body_json($r)['error']['message'] ?? '') === 'There is no such board.', '…and to JSON: not_found in the same words');
$r = $g($jd, 'edit');
ok($r['code'] === 403 && str_contains($r['body'], 'You can see this board but not change it.'), 'Dana asked for edit: 403 with the database\'s sentence (42501)');
$r = $g($jd, 'comment', [], $json);
ok($r['code'] === 403 && (body_json($r)['error']['message'] ?? '') === 'You can see this board but not comment on it.', 'Dana asked for comment: 403 forbidden, in the database\'s words');
$r = $g($jd, 'full');
ok($r['code'] === 403 && str_contains($r['body'], 'Only the board') && str_contains($r['body'], 'owners can do that.'), 'Dana asked for full: "Only the board\'s owners can do that."');
$r = $g($jp, 'edit', ['batch' => '[]'], $json);
ok($r['code'] === 422 && (body_json($r)['error']['message'] ?? '') === 'A batch holds at least one op.', 'the owner sends a malformed batch (an empty one): 422 with the database\'s sentence (22023)');
$r = $g($jp, 'edit', ['batch' => '{"op": "create"}']);
ok($r['code'] === 422 && str_contains($r['body'], 'A batch holds at least one op.'), 'a batch that is not a list: 422, never a 500');
$r = $g($jp, 'edit', ['batch' => '[{"op": "create", "item": {"type": "sticky_note"}}]'], $json);
ok($r['code'] === 422 && (body_json($r)['error']['message'] ?? '') === 'A new item names its id.', 'a create without an id: 422 "A new item names its id."');
$r = page($jp, '/__proof/gate.php?board=nope&need=view');
ok($r['code'] === 404, 'a board that is not a uuid: 404 before the database is asked');
$r = page($jp, '/__proof/gate.php?board=00000000-0000-4000-8000-000000000000&need=view');
ok($r['code'] === 404 && str_contains($r['body'], 'There is no such board.'), 'a uuid that names no board: 404 in the same words');
$log = (string) @file_get_contents(need('BD_DEV_STATE') . '/app.log') . (string) @file_get_contents(need('BD_DEV_STATE') . '/apache/apache.err');
ok(!preg_match('/PHP Fatal|Uncaught/', $log), 'no 500 behind any of them: no fatal error in the server\'s log');

echo "   db_message() against the schema\n";
$cases = [
    ['42501', 'You can see this board but not change it.', "SELECT bd_require_level('$board', 30, 'edit')"],
    ['P0002', 'There is no such board.', "SELECT bd_require_level('$board', 28, 'view')"],
    ['22023', 'There is no level called', "SELECT bd_board_share('$board', 26, 'member', 27, 'superuser')"],
    ['42501', '(fallback)', 'DELETE FROM bd_roles'],
    ['23502', '(fallback)', 'INSERT INTO notification_prefs (member_id) VALUES (NULL)'],
];
foreach ($cases as [$code, $want, $sql]) {
    as_member(26);
    $p = pdo();
    $p->beginTransaction();
    try { $p->exec($sql); $got = null; $msg = 'no error'; } catch (PDOException $e) { $got = (string) $e->getCode(); $msg = db_message($e, '(fallback)'); }
    $p->rollBack();
    ok($got === $code && str_starts_with($msg, $want), "$code answers \"" . mb_substr($msg, 0, 70) . '"' . ($want === '(fallback)' ? ' — PostgreSQL\'s own text never reaches a person' : ' — the schema\'s sentence'));
}
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/handler.php');
ok(str_contains($src, "=== '42501'") && str_contains($src, "refuse(403, db_message(\$e, 'You may not do that.'))") && str_contains($src, "=== '22023'") && str_contains($src, "refuse(422, db_message(\$e, 'That request is not well formed.'))"),
    'bd_guard() maps 42501 → 403 and 22023 → 422 with the database\'s sentence');
finish();
