<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-boards.conf; the manifest's canonical URLs). Meaningful under BD_APP=apache
 * tests/phase2/run.sh, where a real Apache serves the template rendered as the installer renders it; under php -S the same checks run against
 * tests/dev_router.php, which mirrors the rewrites. The receivers at their extension-less names; the shell's screens and every placeholder
 * at its canonical URL; a board by its uuid and its pages; a template by its uuid; the doors of later slices 404 until built; /api/v1/health
 * and nothing else under /api; /pick/; the live door and the MCP proxies (under Apache); the manifest and the assets with their types;
 * nothing outside html/.
 */
require __DIR__ . '/lib.php';
$board = seed_board();
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php (slice 6\'s placeholder)');
foreach (['boards', 'folders', 'templates', 'admin'] as $d) {
    ok(page($j, "/$d")['code'] === 200 && page($j, "/$d/")['code'] === 200, "/$d and /$d/ both reach $d/index.php");
}
foreach (['/trash', '/search', '/notifications', '/activity', '/admin/usage', '/admin/settings', '/admin/agents', '/admin/dispatches'] as $u) {
    ok(page($j, $u)['code'] === 200, "$u resolves to its own file");
}
ok(str_contains(page($j, '/boards/new')['body'], 'data-screen="board-add"') && str_contains(page($j, '/folders/new')['body'], 'data-screen="folder-add"'), '/x/new → x/form.php (New board, New folder)');
ok(str_contains(page($j, '/folders/7')['body'], 'data-screen="folder-view"') && str_contains(page($j, '/folders/7/edit')['body'], 'data-screen="folder-edit"'), '/folders/{id} → view.php, /folders/{id}/edit → form.php?id=');
[$jp, ] = sign_on(26);
foreach (['' => 'board-view', '/settings' => 'board-settings', '/share' => 'board-share', '/outline' => 'board-outline', '/comments' => 'board-comments', '/history' => 'board-history'] as $suffix => $screen) {
    $r = page($jp, "/boards/$board$suffix");
    ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="' . $screen . '"'), "/boards/{board}$suffix → boards/" . ($suffix === '' ? 'view' : ltrim($suffix, '/')) . '.php with the board');
}
ok(str_contains(page($jp, "/templates/$board")['body'], 'data-screen="template-view"'), '/templates/{board} → templates/view.php');
foreach (['/state', '/ops', '/live-ticket', '/thumbnail', '/export', '/rail'] as $door) {
    ok(page($jp, "/boards/$board$door")['code'] === 404, "the door /boards/{board}$door is rewritten to its file, which a later slice builds: 404 until then");
}
ok(req('POST', '/items/create.php', ['jar' => $jp])['code'] === 404 && req('POST', '/boards/save.php', ['jar' => $jp])['code'] === 404, 'the actions\' handlers: 404 until their slice');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && (body_json($h)['application'] ?? '') === 'boards' && (body_json($h)['storage'] ?? '') === 'ok', '/api/v1/health answers, its store ok');
ok(req('GET', '/api/v1/health.php')['code'] === 404, '/api/v1/health.php is 404: under /api the vhost answers only the canonical /api/v1/health');
ok(req('GET', '/api/v1/boards')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404 (Boards has no token API in v1)');
$p = req('GET', '/pick/?source=member');
ok($p['code'] === 302 && $p['location'] === LAUNCHER, '/pick/ without a session goes to the launcher');
if (getenv('BD_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $l = req('GET', '/live', ['headers' => ['Connection: Upgrade', 'Upgrade: websocket', 'Sec-WebSocket-Version: 13', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==']]);
    ok(in_array($l['code'], [502, 503], true), '/live is the relay\'s door through the proxy (502 until slice 3 starts it) — never PHP\'s (' . $l['code'] . ')');
    $i = req('GET', 'http://127.0.0.1:9107/api/v1/health');
    ok($i['code'] === 200 && (body_json($i)['application'] ?? '') === 'boards', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
    $i = req('GET', "http://127.0.0.1:9107/boards/$board");
    ok($i['code'] === 302 && $i['location'] === LAUNCHER, '…and rewrites a board\'s page the same way (no session: the launcher)');
    $conf = (string) file_get_contents(need('BD_DEV_STATE') . '/apache/site.conf');
    ok(str_contains($conf, 'LimitRequestBody 33554432') && str_contains($conf, 'upload_max_filesize 26M') && str_contains($conf, 'post_max_size 32M'), 'the rendered vhost carries LimitRequestBody 32 MB and the PHP upload limits (25 MB files, D9)');
    ok(str_contains($conf, 'ProxyPass        /live         http://127.0.0.1:9106/ upgrade=websocket timeout=120'), 'the live door: ProxyPass /live → the relay with upgrade=websocket');
    $hd = req('GET', '/api/v1/health')['headers'];
    ok(preg_match('/^X-Content-Type-Options: nosniff/mi', $hd) === 1 && preg_match('/^X-Frame-Options: SAMEORIGIN/mi', $hd) === 1, 'Apache sends nosniff and the frame option (mod_headers, guarded by <IfModule>)');
    ok(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/deploy/apache-boards.conf'), '__proof'), 'the proofs\' fixture door is the proof\'s alone — never in the deploy file');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && (json_decode($m['body'], true)['name'] ?? '') === 'Boards', '/manifest.webmanifest is served as JSON and names Boards');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/worker.php', '/mcp/db.py', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/__proof/gate.php?board=x'] as $p) {
    $r = req('GET', $p);
    $proofDoor = str_starts_with($p, '/__proof/');
    ok(in_array($r['code'], $proofDoor ? [302, 404] : [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
