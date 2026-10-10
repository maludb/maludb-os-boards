<?php
/**
 * Proof: the shell (sso-shell.md "Proof" 1, 2, 3, 6, 8): the sign-on refusals and the six people's sign-on with their roles; notification
 * preferences before anyone looks; the menu and the tab bar per role; every placeholder — 200 with its slice's line to a person, 501 to JSON
 * and to a POST, the board's wall in front of a board's page, `stub` in the registry; the command bar through the kernel's chat endpoint;
 * the worker's pass of eight steps; health with the relay down and still 200; a revoked grant shutting the door.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
$board = seed_board();

echo "1. Sign-on\n";
$refusals = [
    'replay'   => null,
    'expired'  => handoff(26, ['ttl' => -120]),
    'audience' => handoff(26, ['app' => 'other']),
    'unknown'  => handoff(999, ['claims' => ['member_id' => 999, 'display_name' => 'Nobody', 'status' => 'active']]),      // the kernel's claims hold no grant here
    'tampered' => handoff(26, ['key' => str_repeat('0', 64)]),
    'no grant' => handoff(41),
];
$url = handoff(26);
req('GET', $url, ['jar' => jar()]);
$refusals['replay'] = $url;
$before = last_activity_id();
$pages = [];
foreach ($refusals as $why => $u) {
    $r = req('GET', $u, ['jar' => jar()]);
    $pages[] = $r['body'];
    ok($r['code'] === 403, "a hand-off refused ($why): 403");
}
ok(count(array_unique($pages)) === 1, '…every refusal the same page, which never says which check failed');
ok(count(activity('member.sign_on.refused', $before)) === count($refusals), 'each refusal is logged as member.sign_on.refused (' . count($refusals) . ')');
$people = [26 => 'Member', 27 => 'Boards admin', 28 => 'Member', 29 => 'Viewer', 30 => 'Viewer', 31 => 'Viewer', 1 => 'Super-admin'];
$jars = [];
foreach ($people as $m => $badge) {
    [$j, $r] = sign_on($m);
    $jars[$m] = $j;
    ok($r['code'] === 302 && in_array($r['location'], ['/', BASE . '/'], true), "member $m signs on (302 to /)");
    ok(badge_of($j) === $badge, "member $m's header badge reads their highest role: $badge");
}
$roles = [];
foreach ([26, 27, 28, 29, 30, 31] as $m) { $roles[$m] = trim((string) one('SELECT bd_member_roles(:m)::text', ['m' => $m]), '{}'); }
ok($roles === [26 => 'user', 27 => 'user,admin', 28 => 'user', 29 => 'viewer', 30 => 'viewer', 31 => 'viewer'] || $roles === [26 => 'user', 27 => 'admin,user', 28 => 'user', 29 => 'viewer', 30 => 'viewer', 31 => 'viewer'],
    'the roles resolved: Priya Member, Marco Boards admin + Member, Bea Member by capability, Ann, Dana and Lee Viewers — ' . json_encode($roles));
$prefs = array_map('intval', array_column(q('SELECT member_id FROM notification_prefs WHERE member_id IN (1, 26, 27, 28, 29, 30, 31) ORDER BY member_id'), 'member_id'));
ok($prefs === [1, 26, 27, 28, 29, 30, 31], 'notification preferences exist for every person who signed on');
$admitted = (int) one("SELECT count(*) FROM members WHERE status = 'active' AND capability IS NOT NULL");
$withPrefs = (int) one("SELECT count(*) FROM members m JOIN notification_prefs p ON p.member_id = m.id WHERE m.status = 'active' AND m.capability IS NOT NULL");
ok($admitted > 0 && $admitted === $withPrefs, "…and for every admitted member, agents too, after one sync ($withPrefs of $admitted)");
ok((int) one('SELECT count(*) FROM notification_prefs WHERE member_id = 41') === 0, '…but not for an agent the kernel never admitted here (the Watcher)');
$j = jar(); $r = req('GET', handoff(26, ['next' => '/activity']), ['jar' => $j]);
ok($r['code'] === 302 && str_ends_with($r['location'], '/activity'), 'a hand-off with next=/activity lands there');
$j = jar(); $r = req('GET', handoff(26, ['next' => 'https://evil.example/']), ['jar' => $j]);
ok($r['code'] === 302 && (str_ends_with($r['location'], '/') && !str_contains($r['location'], 'evil')), '…and a next that is not a local path lands on the home');

echo "2. The menu and the tab bar per role\n";
$menu = static function (string $jar): array { preg_match_all('/<li class="nxl-item" id="nav-([a-z-]+)">/', page($jar, '/')['body'], $m); return $m[1]; };
$main = ['boards', 'templates', 'folders', 'trash'];
$me = ['notifications', 'my-settings', 'tokens', 'activity'];
ok($menu($jars[1]) === array_merge($main, ['admin'], $me), 'the super-admin: Boards · Templates · Folders · Trash · Admin, then Me');
ok($menu($jars[27]) === array_merge($main, ['admin'], $me), 'Marco (a Boards admin): the same, with Admin');
foreach ([26 => 'Priya (a Member)', 28 => 'Bea (a Member by capability)', 30 => 'Dana (a Viewer)', 29 => 'Ann (an external)'] as $m => $who) {
    ok($menu($jars[$m]) === array_merge($main, $me), "$who: Boards · Templates · Folders · Trash, no Admin");
}
$b = page($jars[26], '/')['body'];
preg_match_all('/id="tab-(home|boards|search|me|[a-z]+(?<!bar))"/', $b, $t);
ok($t[1] === ['home', 'boards', 'search', 'me'] && str_contains($b, 'class="app-tabbar d-lg-none" id="tab-bar"'), 'the phone\'s tab bar: Home · Boards · Search · Me (hidden from 992 px)');
ok(str_contains($b, 'id="header-search"') && str_contains($b, 'action="/search"') && str_contains($b, 'id="bell"') && str_contains($b, 'id="assistant-bar"'), 'the header carries the search box (GET /search), the bell, and the command bar is on the page');
ok(str_contains($b, 'id="header-trail-link"') && str_contains($b, 'id="header-tokens-link"') && str_contains($b, 'id="header-logout-btn"'), 'the user menu: My trail · My tokens · Sign out');
pg("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'share', 'SMOKE shared with you', 'SMOKE'), (26, 'mention', 'SMOKE mentioned you', 'SMOKE')");
ok(preg_match('/id="bell-count">2</', page($jars[26], '/')['body']) === 1 && !str_contains(page($jars[27], '/')['body'], 'id="bell-count"'), 'the bell counts my unread notices (2 for Priya) and hides at 0 (Marco)');

echo "3. The placeholders\n";
$ph = [
    ['/', 'home', 10], ['/boards', 'boards-list', 1], ['/boards/new', 'board-add', 1], ["/boards/$board", 'board-view', 2], ["/boards/$board/settings", 'board-settings', 1],
    ["/boards/$board/share", 'board-share', 1], ["/boards/$board/outline", 'board-outline', 2], ["/boards/$board/comments", 'board-comments', 6],
    ["/boards/$board/history", 'board-history', 7], ['/folders', 'folders', 1], ['/folders/12', 'folder-view', 1], ['/folders/new', 'folder-add', 1],
    ['/folders/12/edit', 'folder-edit', 1], ['/trash', 'trash', 1], ['/templates', 'templates', 8], ["/templates/$board", 'template-view', 8],
    ['/notifications', 'notifications', 6], ['/settings/', 'settings', 6], ['/search', 'search', 9], ['/admin', 'admin', 10], ['/admin/usage', 'admin-usage', 10],
    ['/admin/settings', 'admin-settings', 10], ['/admin/agents', 'admin-agents', 9], ['/admin/dispatches', 'admin-dispatches', 9],
];
$bad = [];
$badJson = [];
foreach ($ph as [$u, $screen, $slice]) {
    $who = str_starts_with($u, '/admin') ? $jars[27] : $jars[26];
    $r = page($who, $u);
    if (!($r['code'] === 200 && str_contains($r['body'], 'id="nav-stub"') && str_contains($r['body'], 'id="nav-stub-line">Built by slice ' . $slice . ' — ') && str_contains($r['body'], 'data-screen="' . $screen . '"'))) { $bad[] = "$u ({$r['code']})"; }
    $r = page($who, $u, $json);
    if (!($r['code'] === 501 && (body_json($r)['error']['code'] ?? '') === 'not_built' && str_contains(body_json($r)['error']['message'] ?? '', 'Built by slice ' . $slice))) { $badJson[] = "$u ({$r['code']})"; }
}
ok($bad === [], 'all ' . count($ph) . ' placeholders answer 200 to a person: the shell, one card "Built by slice N — what it will do", the screen stamped' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
ok($badJson === [], '…and 501 not_built to JSON, naming the slice' . ($badJson ? ' — wrong: ' . implode(', ', $badJson) : ''));
$r = req('POST', '/boards', ['jar' => $jars[26], 'form' => ['csrf_token' => page_csrf($jars[26])]]);
ok($r['code'] === 501 && str_contains($r['body'], 'Built by slice 1'), 'a POST to a placeholder: 501, in words');
$r = page($jars[26], '/boards', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="nav-stub"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Boards/mi', $r['headers']) === 1 && preg_match('/^X-Screen: boards-list\r?$/mi', $r['headers']) === 1,
    'over HTMX a placeholder is the partial with HX-Title and X-Screen, so the highlight and the command bar follow');
$r = page($jars[26], '/');
ok(str_contains($r['body'], 'id="home-boards-link"') && str_contains($r['body'], 'href="/boards"'), 'the home\'s placeholder keeps one live link: Boards');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'board-history' AND board_id = :b", ['b' => $board]);
page($jars[26], "/boards/$board/history");
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'board-history' AND board_id = :b", ['b' => $board]) === $v + 1, 'opening a board\'s placeholder logs screen.view with the screen id and the board');
echo "   the board's wall stands in front of a board's page\n";
$r = page($jars[28], "/boards/$board");
ok($r['code'] === 404 && str_contains($r['body'], 'There is no such board.'), 'Bea, a stranger to the private board: 404 "There is no such board." — never "forbidden"');
$r = page($jars[30], "/boards/$board/share");
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Q4 retro') && str_contains($r['body'], 'data-level="view">Can view<'), 'Dana, shared at view: the page names the board and her level badge (Can view)');
$r = page($jars[26], "/boards/$board/settings");
ok(str_contains($r['body'], 'data-level="full">Owner<'), 'Priya, its owner: the level badge reads Owner');
ok(page($jars[26], '/boards/not-a-uuid')['code'] === 404 && page($jars[26], '/boards/00000000-0000-4000-8000-000000000000')['code'] === 404, 'a board that is not one, or does not exist: 404');
echo "   the rights in front of the rest\n";
$want = ['/admin' => [1 => 200, 27 => 200, 26 => 403, 29 => 403], '/admin/usage' => [27 => 200, 26 => 403], '/admin/settings' => [27 => 200, 30 => 403],
         '/admin/agents' => [27 => 200, 26 => 403], '/admin/dispatches' => [1 => 200, 28 => 403], '/boards/new' => [26 => 200, 28 => 200, 30 => 403, 29 => 403],
         '/settings/tokens/' => [26 => 200, 29 => 200, 30 => 200]];
$wrong = [];
foreach ($want as $u => $codes) { foreach ($codes as $m => $code) { $got = page($jars[$m], $u)['code']; if ($got !== $code) { $wrong[] = "$m $u wanted $code got $got"; } } }
ok($wrong === [], 'the admin pages to a Boards admin only, New board to a Member, tokens to everyone with tokens.own' . ($wrong ? ': ' . implode('; ', $wrong) : ''));
$r = page($jars[30], '/boards/new');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not create a board.'), 'a Viewer on New board: 403 in words ("You may not create a board.")');
$stubs = (int) trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' | wc -l'));
ok($stubs === 23, "23 controllers call render_nav_stub — the 24 placeholders (folders/form.php answers two): $stubs");
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);          // run.sh's last step checks it is current
$stubScreens = array_keys(array_filter($reg['screens'] ?? [], static fn ($s) => !empty($s['stub'])));
sort($stubScreens);
$phScreens = array_column($ph, 1);
sort($phScreens);
ok($stubScreens === $phScreens, 'the registry counts exactly those 24 screens as stub (' . count($stubScreens) . ')');
$built = array_keys(array_filter($reg['screens'] ?? [], static fn ($s) => !empty($s['built'])));
sort($built);
ok($built === ['activity', 'settings-tokens'], 'and builds exactly the shell\'s two: ' . implode(', ', $built));

echo "6. The command bar\n";
@unlink(need('FAKE_KERNEL_STATE') . '.chat');
$before = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $jars[26], 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . page_csrf($jars[26])], 'form' => ['utterance' => 'Make a board for the Q4 retro', 'screen' => 'boards-list', 'entity' => '', 'record_id' => '']]);
$sent = json_decode(trim((string) @file_get_contents(need('FAKE_KERNEL_STATE') . '.chat')), true) ?: [];
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the bar\'s post reaches the kernel\'s chat endpoint and renders the expert\'s reply');
ok(($sent['acting'] ?? null) === '26' && ($sent['agent'] ?? null) === 'expert' && ($sent['utterance'] ?? null) === 'Make a board for the Q4 retro', '…as the acting person, to the expert, with the words');
$log = activity('assistant.ask', $before);
$after = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && ($after['length'] ?? 0) === 29 && ($after['screen'] ?? '') === 'boards-list' && !str_contains((string) $log[0]['after'], 'retro'), 'assistant.ask is logged with the length and the screen — never the words');
kernel_state(function ($s) { $s['chat_status'] = 409; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars[26], 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . page_csrf($jars[26])], 'form' => ['utterance' => 'Again']]);
ok($r['code'] === 409 && str_contains($r['body'], 'The expert is busy with another turn.'), 'a refusal from the kernel is said in its words (409 busy)');
kernel_state(function ($s) { unset($s['chat_status']); return $s; });
$tok = person_token(26);
$r = req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'x']]);
ok($r['code'] === 403 && str_contains($r['body'], 'The command bar is for a person at the screen.'), 'the bar is for a person\'s session: an action token\'s call is refused, in words (' . $r['code'] . ')');

echo "8. The worker and health\n";
$before = last_activity_id();
exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && php bin/worker.php 2>&1', $wout, $wrc);
$rep = json_decode((string) end($wout), true) ?: [];
ok($wrc === 0 && count($rep['ran'] ?? []) === 8 && isset($rep['ran']['key_usage_prune']['pruned']), 'the worker runs one pass of eight steps and exits 0');
$stubs = array_filter($rep['ran'] ?? [], static fn ($x) => isset($x['stub']));
ok(count($stubs) === 7 && ($rep['ran']['trash']['stub'] ?? '') === 'slice 10' && ($rep['ran']['thumbnails']['stub'] ?? '') === 'slice 5' && ($rep['ran']['versions']['stub'] ?? '') === 'slice 7',
    'seven of them are stubs naming their slice (trash 10, thumbnails 5, versions 7, …)');
ok(count(activity('worker.pass', $before)) === 1 && (int) one('SELECT count(*) FROM worker_passes') >= 1, 'the pass is logged as worker.pass and kept in worker_passes');
$h = req('GET', '/api/v1/health');
$hb = body_json($h);
ok($h['code'] === 200 && ($hb['application'] ?? '') === 'boards' && ($hb['live'] ?? '') === 'down' && ($hb['database'] ?? '') === 'ok', 'health answers 200 with the relay down (live: down) — reported, never a failure');

echo "The kernel's word shuts the door\n";
[$jl, ] = sign_on(31);
ok(page($jl, '/boards')['code'] === 200, 'Lee is in');
kernel_state(function ($s) {
    $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => 'y', 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [],
                         'deleted_departments' => [], 'scopes' => [], 'access' => [['member_id' => 31, 'capability' => null, 'roles' => [], 'scopes' => []]]];
    return $s;
});
exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && php bin/directory_sync.php 2>&1', $sout, $src);
$r = page($jl, '/boards');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'a grant the kernel revoked: Lee\'s next request goes to the launcher');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
