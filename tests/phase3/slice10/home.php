<?php
/**
 * Slice 10 — the home page (home-admin.md "Proof" 1): the sections in order, at most eight cards each, a private board of another never there, the mentions line, the first run (the six shipped templates) for a
 * member with nothing, no New buttons for a Viewer, an external sees only what names them. Run through tests/phase3/slice10/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jb] = sign_on(28);
[$jm] = sign_on(27);
[$jd] = sign_on(30);
[$jl] = sign_on(31);
[$ja] = sign_on(29);
$since = last_activity_id();

echo "1. The first run — a Member with nothing, a Viewer with nothing\n";
$h = home_page($jb, ['Accept: text/html']);
ok($h['code'] === 200 && str_contains($h['body'], 'id="home-first-run"') && str_contains($h['body'], 'Make your first board') && !str_contains($h['body'], 'id="nav-stub"'), 'Bea has no board: "Make your first board", no placeholder');
ok(str_contains($h['body'], 'id="home-new-board"') && str_contains($h['body'], 'href="/boards/new"') && str_contains($h['body'], 'id="home-new-from-template"') && str_contains($h['body'], 'href="/templates"'), '…with New board and New from template');
preg_match_all('/id="template-card-([0-9a-f-]{36})"/', $h['body'], $tc);
ok(count($tc[1]) === 6 && array_diff($tc[1], array_values(SHIPPED)) === [], 'the six shipped templates are cards: ' . count($tc[1]));
ok(!str_contains($h['body'], 'id="home-section-recent"') && !str_contains($h['body'], 'id="home-section-favourites"'), 'no empty Recent / Favourites sections on the first run');
$j = home_data($jb);
ok($j['first_run'] === true && count($j['templates']) === 6 && $j['recent'] === [] && $j['may'] === ['create' => true] && $j['mentions_waiting'] === 0, 'JSON: first_run, six templates, nothing else, may.create');
$hl = home_page($jl, ['Accept: text/html']);
ok($hl['code'] === 200 && str_contains($hl['body'], 'id="home-first-run"') && !str_contains($hl['body'], 'id="home-new-board"') && !str_contains($hl['body'], 'id="home-new-from-template"') && str_contains($hl['body'], 'id="home-first-run-viewer"'),
    'Lee (a Viewer) with nothing: the first run without the New buttons');
ok(home_data($jl)['may'] === ['create' => false], '…and may.create false in JSON');

echo "1. The sections\n";
$mine = [];
for ($i = 1; $i <= 10; $i++) {
    $mine[$i] = make_board($jp, sprintf('SMOKE Home %02d', $i));
    pg("INSERT INTO board_recents (member_id, board_id, visited_at) VALUES (26, '{$mine[$i]}', now() - interval '" . (60 - $i) . " minutes') ON CONFLICT (member_id, board_id) DO UPDATE SET visited_at = EXCLUDED.visited_at");
    set_idle($mine[$i], 60 - $i);
}
foreach ([1, 4, 7] as $i) { act($jp, '/boards/favorite.php', ['board' => $mine[$i]]); }
$theirs = [];
foreach ([1, 2, 3] as $i) {
    $theirs[$i] = make_board($jb, "SMOKE Bea shares $i");
    if ($i < 3) { share_as(28, $theirs[$i], 'member', 26, $i === 1 ? 'view' : 'edit'); }
}
$marco = make_board($jm, 'SMOKE Marco private');
$h = home_page($jp, ['Accept: text/html'])['body'];
$secs = [];
preg_match_all('/<section class="mb-4" id="home-section-([a-z-]+)">/', $h, $sm);
ok($sm[1] === ['recent', 'favourites', 'shared', 'team-folders'], 'the four sections in order: ' . implode(', ', $sm[1]));
$recent = section_ids($h, 'recent');
ok(count($recent) === 8 && $recent[0] === $mine[10] && $recent[7] === $mine[3], 'Recent: eight cards, the newest visit first (Home 10 … Home 03)');
ok(section_ids($h, 'favourites') === [$mine[7], $mine[4], $mine[1]] || array_diff(section_ids($h, 'favourites'), [$mine[1], $mine[4], $mine[7]]) === [] && count(section_ids($h, 'favourites')) === 3, 'Favourites: the three starred boards');
$shared = section_ids($h, 'shared');
ok(count($shared) === 2 && in_array($theirs[1], $shared, true) && in_array($theirs[2], $shared, true) && !in_array($theirs[3], $shared, true), 'Shared with me: the two Bea shared, not the one she did not, none of my own');
ok(!str_contains($h, $marco) && !str_contains($h, $theirs[3]), 'a private board of another appears nowhere on the page');
ok(str_contains($h, 'id="home-section-recent-all"') && str_contains($h, 'href="/boards"') && str_contains($h, 'href="/boards?favorites=1"') && str_contains($h, 'href="/boards?shared=1"') && str_contains($h, 'href="/folders"'), 'each section has See all (Boards, favourites, shared, Folders)');
ok(str_contains($h, 'id="home-new-board"') && str_contains($h, 'id="home-new-from-template"') && !str_contains($h, 'id="home-first-run"'), 'New board and New from template are there, the first run is not');
$j = home_data($jp);
ok(array_keys($j) === ['recent', 'favourites', 'shared', 'team_folders', 'mentions_waiting', 'first_run', 'templates', 'may'] && count($j['recent']) === 8 && $j['first_run'] === false && $j['templates'] === [] && isset($j['recent'][0]['my_level'], $j['recent'][0]['url'])
    && !isset($j['recent'][0]['owner_member_id']), 'JSON: a whitelist — recent (8), favourites, shared, team_folders, mentions_waiting, first_run, templates, may');
ok(count($j['favourites']) === 3 && count($j['shared']) === 2 && $j['shared'][0]['owner']['display_name'] === 'SMOKE Bea', '…favourites 3, shared 2 owned by Bea');
$more = make_board($jp, 'SMOKE Home 11');
for ($i = 12; $i <= 20; $i++) { make_board($jp, "SMOKE Home $i"); }
act($jp, '/boards/favorite.php', ['board' => $more]);
for ($i = 2; $i <= 10; $i++) { act($jp, '/boards/favorite.php', ['board' => $mine[$i]]); }
$j = home_data($jp);
ok(count($j['favourites']) === 8 && count($j['shared']) === 2, 'with eleven starred boards the Favourites section still holds eight');

echo "1. Team folders and mentions\n";
$f = body_json(act($jm, '/folders/save.php', ['name' => 'SMOKE Home team', 'team' => '1']));
$fid = (int) ($f['record_id'] ?? 0);
act($jm, '/folders/add.php', ['folder' => $fid, 'board' => $marco]);
$own = body_json(act($jp, '/folders/save.php', ['name' => 'SMOKE Priya own']));
$h = home_page($jp, ['Accept: text/html'])['body'];
ok($fid > 0 && str_contains($h, 'id="home-folder-' . $fid . '"') && str_contains($h, 'Team') && str_contains($h, 'href="/folders/' . $fid . '?back='), 'the team folder is a card: its name, "Team", a link to the folder carrying ?back=');
ok(!str_contains($h, 'id="home-folder-' . (int) ($own['record_id'] ?? 0) . '"'), 'a folder of my own is not a team folder: not here');
$jf = home_data($jp)['team_folders'];
ok($jf !== [] && $jf[0]['name'] === 'SMOKE Home team' && $jf[0]['is_team'] === true && $jf[0]['url'] === '/folders/' . $fid, 'JSON: team_folders[] with name, is_team, url');
ok(!str_contains($h, 'id="home-mentions"'), 'no mentions line while none are waiting');
pg("INSERT INTO notifications (member_id, kind, title, body, read_at) VALUES (26, 'mention', 'SMOKE m1', 'x', NULL), (26, 'mention', 'SMOKE m2', 'x', NULL), (26, 'mention', 'SMOKE m3 read', 'x', now()), (26, 'reply', 'SMOKE r1', 'x', NULL)");
$h = home_page($jp, ['Accept: text/html'])['body'];
ok(str_contains($h, '2 mentions waiting') && str_contains($h, 'href="/notifications"') && home_data($jp)['mentions_waiting'] === 2, 'the mentions line counts unread mentions only (2: not the read one, not the reply)');
ok(!str_contains(home_page($jb, ['Accept: text/html'])['body'], 'mentions waiting'), 'and only mine — Bea has none');

echo "1. A Viewer and an external\n";
share_as(26, $mine[1], 'member', 30, 'view');
share_as(26, $mine[2], 'member', 29, 'comment');
$hd = home_page($jd, ['Accept: text/html'])['body'];
ok(str_contains($hd, 'id="home-section-shared"') && in_array($mine[1], section_ids($hd, 'shared'), true) && !str_contains($hd, 'id="home-new-board"') && !str_contains($hd, 'id="home-new-from-template"') && !str_contains($hd, 'id="home-first-run"'),
    'Dana (a Viewer) with a shared board: the sections, no New buttons');
$ha = home_page($ja, ['Accept: text/html'])['body'];
$ja_ids = array_merge(section_ids($ha, 'recent'), section_ids($ha, 'favourites'), section_ids($ha, 'shared'));
ok($ja_ids === [$mine[2]] || array_unique($ja_ids) === [$mine[2]], 'Ann (an external) sees only the board that names her: ' . implode(',', array_unique($ja_ids)));
ok(!str_contains($ha, 'id="home-folder-' . $fid . '"') && !str_contains($ha, $mine[1]), '…not the team folder, not the other boards');
$r = req('GET', '/', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'the home to an anonymous caller (JSON): 401');
$r = home_page($jp, ['Accept: text/html', 'HX-Request: true']);
ok($r['code'] === 200 && !str_contains($r['body'], '<html') && str_contains($r['body'], 'id="home-section-recent"') && preg_match('/^X-Screen: home\r?$/mi', $r['headers']) === 1, 'over HTMX the home is the partial, the screen stamped home');
$v = q("SELECT count(*) AS c FROM activity_log WHERE action = 'screen.view' AND screen = 'home' AND id > :s", ['s' => $since])[0]['c'];
ok($v >= 3, 'screen.view is logged for the home (' . $v . ')');
finish();
