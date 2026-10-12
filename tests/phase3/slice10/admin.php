<?php
/**
 * Slice 10 — the admin's page, usage and the most active boards (home-admin.md "Proof" 2, 3, 6): every section for a Boards admin; only Settings for a member who holds settings.manage alone (a fixture role); only the
 * trash for one who holds trash.purge alone; a plain Member 403. Usage: the bar's amber and red, the sorts, the filters, the owner who has left with Transfer, a Member 403 and the view answering nothing to them.
 * Most active: three boards in order, the window, and only the boards the caller can see. Run through tests/phase3/slice10/run.sh.
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
[$jb] = sign_on(28);
[$jd] = sign_on(30);
[$jl] = sign_on(31);
[$jo] = sign_on(1);
settings_reset();

echo "2. The admin's page by right\n";
$a = [];
foreach ([1 => 'the super-admin', 27 => 'Marco (a Boards admin)'] as $m => $who) {
    $h = page($m === 1 ? $jo : $jm, '/admin')['body'];
    $a[$m] = $h;
    ok(str_contains($h, 'id="admin-section-usage"') && str_contains($h, 'id="admin-section-trash"') && str_contains($h, 'id="admin-section-agents"') && str_contains($h, 'id="admin-section-active"') && str_contains($h, 'id="admin-section-settings"')
        && !str_contains($h, 'id="nav-stub"'), "$who: Usage, the trash, Agents, Most active, Settings — no placeholder");
}
$r = page($jp, '/admin');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not manage every board.'), 'Priya (a plain Member): 403 in words');
ok(page($jd, '/admin')['code'] === 403 && page($ja = sign_on(29)[0], '/admin')['code'] === 403, 'a Viewer and an external: 403');
give_role(31, 'setter', ['settings.manage']);
$h = page($jl, '/admin')['body'];
ok(str_contains($h, 'id="admin-section-settings"') && !str_contains($h, 'id="admin-section-usage"') && !str_contains($h, 'id="admin-section-trash"') && !str_contains($h, 'id="admin-section-agents"') && !str_contains($h, 'id="admin-section-active"'),
    'Lee with settings.manage alone: only the Settings section');
ok(page($jl, '/admin/settings')['code'] === 200 && page($jl, '/admin/usage')['code'] === 403 && page($jl, '/admin/agents')['code'] === 403, '…the settings page opens for him, usage and the agents do not');
give_role(30, 'purger', ['trash.purge']);
$h = page($jd, '/admin')['body'];
ok(str_contains($h, 'id="admin-section-trash"') && !str_contains($h, 'id="admin-section-usage"') && !str_contains($h, 'id="admin-section-settings"'), 'Dana with trash.purge alone: only the trash section');
$j = admin_data($jl);
ok($j['may'] === ['usage' => false, 'trash' => false, 'purge' => false, 'agents' => false, 'active' => false, 'settings' => true] && $j['usage'] === null && $j['trash'] === [] && $j['most_active'] === [], 'JSON for Lee: may says only settings; the rest empty');
set_roles(31, '{}');
set_roles(30, '{viewer}');
ok(page($jl, '/admin')['code'] === 403, 'with the role taken back the page is 403 again');

echo "3. Usage against the budget\n";
$amber = make_board($jp, 'SMOKE Usage amber');
$red = make_board($jp, 'SMOKE Usage red');
$green = make_board($jp, 'SMOKE Usage green');
$idle = make_board($jp, 'SMOKE Usage idle');
$left = leaver_board();
set_items($amber, 4100);
set_items($red, 9500);
set_items($green, 12);
set_items($idle, 30);
set_idle($idle, 200);
set_idle($left, 120);
$h = page($jm, '/admin/usage')['body'];
$bar = static function (string $h, string $id): string { return preg_match('/id="' . preg_quote($id, '/') . '" data-status="([a-z]+)"/', $h, $m) ? $m[1] : ''; };
ok($bar($h, "usage-row-$amber-bar") === 'warning' && $bar($h, "usage-row-$red-bar") === 'danger' && $bar($h, "usage-row-$green-bar") === 'success', 'a 4,100-item board is amber (warning), 9,500 red (danger), 12 green (success)');
ok($bar($h, "usage-card-$amber-bar") === 'warning' && str_contains($h, 'id="usage-card-' . $green . '"') && str_contains($h, 'id="usage-table"') && str_contains($h, 'id="usage-cards"'), 'the cards (for a phone) and the table (for a desktop) are both there');
ok(!str_contains($h, 'nav-stub') && str_contains($h, '4000 items, the hard cap 10000'), 'the page says the soft budget and the hard cap');
$j = admin_data($jm, '/admin/usage');
ok(array_keys($j) === ['filters', 'page', 'page_size', 'total', 'soft_budget', 'hard_cap', 'rows'] && $j['page_size'] === 50 && $j['soft_budget'] === 4000 && $j['hard_cap'] === 10000 && $j['rows'][0]['board_id'] === $red && $j['rows'][1]['board_id'] === $amber,
    'JSON: a whitelist; the default sort is items — the 9,500 board first, then 4,100');
$byId = array_column($j['rows'], null, 'board_id');
ok($byId[$red]['status'] === 'danger' && $byId[$amber]['status'] === 'warning' && $byId[$amber]['over_soft'] === true && $byId[$green]['over_soft'] === false && $byId[$left]['owner']['active'] === false && $byId[$amber]['owner']['display_name'] === 'SMOKE Priya',
    'each row: status, over_soft, the owner and whether they are still active');
$j = admin_data($jm, '/admin/usage?sort=idle');
ok($j['filters'] == ['sort' => 'idle'] && $j['rows'][0]['board_id'] === $idle && $j['rows'][1]['board_id'] === $left && $j['rows'][0]['idle_days'] === 200, 'sort=idle puts the board idle 200 days first, then the one idle 120');
$j = admin_data($jm, '/admin/usage?idle_days=100');
ok(array_column($j['rows'], 'board_id') === [$idle, $left] || array_diff(array_column($j['rows'], 'board_id'), [$idle, $left]) === [] && $j['total'] === 2, 'idle_days=100 lists only the boards idle that long (' . $j['total'] . ')');
$j = admin_data($jm, '/admin/usage?owner_left=1');
ok($j['total'] === 1 && $j['rows'][0]['board_id'] === $left && $j['rows'][0]['transfer_url'] === "/boards/$left/share#share-owner", 'owner_left=1 lists the board of the member who has left, with its Transfer link');
$h = page($jm, '/admin/usage?owner_left=1')['body'];
ok(str_contains($h, 'id="usage-row-' . $left . '-left"') && str_contains($h, '>left<') && str_contains($h, 'id="usage-row-' . $left . '-transfer"') && str_contains($h, 'id="usage-card-' . $left . '-transfer"') && !str_contains($h, 'id="usage-row-' . $amber . '"'),
    'the page shows the left badge and Transfer on that board only');
$tp = page($jm, "/boards/$left/share");
ok($tp['code'] === 200 && str_contains($tp['body'], 'id="share-owner-transfer"'), '…and Transfer is on that board\'s share page (a Boards admin has full)');
$r = req('POST', '/boards/transfer.php', ['jar' => $jm, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jm)], 'form' => ['board' => $left, 'new_owner' => 26]]);
$tr = q('SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $left])[0]['owner_member_id'];
ok($r['code'] === 200 && (int) $tr === 26, 'Marco transfers it to Priya (slice 1\'s action)');
ok(admin_data($jm, '/admin/usage?owner_left=1')['total'] === 0, '…and it is no longer listed');
pg("UPDATE boards SET owner_member_id = 95 WHERE id = '$left'");
$j = admin_data($jm, '/admin/usage?sort=bogus&idle_days=x&owner_left=maybe');
ok($j['filters'] == ['sort' => 'items'] && $j['total'] >= 5, 'an unknown sort, an idle_days that is not a number and a junk owner_left are ignored');
$u = admin_data($jm)['usage'];
ok(array_keys($u) === ['boards', 'items', 'over_soft', 'idle_90', 'owner_left', 'soft_budget', 'hard_cap'] && $u['over_soft'] >= 2 && $u['idle_90'] >= 2 && $u['owner_left'] === 1 && $u['items'] >= 13642, 'the admin\'s Usage summary: boards, items in all, over the soft budget, idle 90 days, owner left (' . json_encode($u) . ')');
$h = page($jm, '/admin')['body'];
ok(str_contains($h, 'href="/admin/usage?owner_left=1"') && str_contains($h, 'href="/admin/usage?sort=idle&amp;idle_days=90"'), 'each count links into usage with its filter');
as_member(26);
ok((int) one('SELECT count(*) FROM mcp_boards_usage') === 0, 'mcp_boards_usage answers nothing to a Member (the view is the wall)');
as_member(27);
ok((int) one('SELECT count(*) FROM mcp_boards_usage') >= 5, '…and every working board to a Boards admin');
$r = page($jp, '/admin/usage');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not manage every board.'), 'the usage page to a Member: 403 in words');
$r = page($jp, '/admin/usage', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 403 && (body_json($r)['error']['code'] ?? '') === 'forbidden', '…and in JSON');
// paging
pg("INSERT INTO boards (title, owner_member_id, item_count) SELECT 'SMOKE Page ' || n, 26, 1 FROM generate_series(1, 55) n");
$j = admin_data($jm, '/admin/usage');
$j2 = admin_data($jm, '/admin/usage?page=2');
ok(count($j['rows']) === 50 && $j['total'] >= 60 && count($j2['rows']) === $j['total'] - 50 && $j2['page'] === 2, 'fifty a page, the rest on page 2 (total ' . $j['total'] . ')');
pg("DELETE FROM boards WHERE title LIKE 'SMOKE Page %'");

echo "6. Most active this month\n";
$x = make_board($jp, 'SMOKE Active X');
$y = make_board($jp, 'SMOKE Active Y');
$z = make_board($jp, 'SMOKE Active Z');
$hidden = make_board($jb, 'SMOKE Active hidden');
fake_activity($x, 7, [26, 28, 27]);
fake_activity($y, 4, [26]);
fake_activity($z, 2, [26, 28], 'comment.create');
fake_activity($z, 1, [26], 'board.open');
fake_activity($hidden, 9, [28]);
fake_activity($y, 20, [26], 'item.create', 40);
fake_activity($y, 20, [26], 'board.share');
as_member(27);
$list = most_active_boards(pdo(), 30, 5);
$ids = array_column($list, 'board_id');
ok(array_slice($ids, 0, 4) === [$hidden, $x, $y, $z], 'a Boards admin: the boards in order of activity (Active hidden 9, X 7, Y 4, Z 3) — the share rows and the 40-day-old ones are not counted');
$lx = array_column($list, null, 'board_id')[$x];
ok((int) $lx['batches'] === 7 && (int) $lx['members'] === 3 && $lx['last_at'] !== null, 'a board: its batches (7), the distinct people (3), the last at');
ok(count(most_active_boards(pdo(), 30, 2)) === 2, 'the limit holds');
as_member(26);
$mine = array_column(most_active_boards(pdo(), 30, 5), 'board_id');
ok(!in_array($hidden, $mine, true) && array_slice($mine, 0, 3) === [$x, $y, $z], 'called as Priya: Bea\'s private board is absent; hers are, in order');
as_member(28);
ok(in_array($hidden, array_column(most_active_boards(pdo(), 30, 5), 'board_id'), true) && !in_array($x, array_column(most_active_boards(pdo(), 30, 5), 'board_id'), true), 'called as Bea: her own board, not Priya\'s');
as_member(27);
$h = page($jm, '/admin')['body'];
ok(str_contains($h, 'id="admin-active-' . $x . '"') && strpos($h, 'id="admin-active-' . $hidden . '"') < strpos($h, 'id="admin-active-' . $x . '"') && substr_count($h, 'id="admin-active-') <= 6, 'the admin\'s page lists them (at most five), the busiest first');
$j = admin_data($jm);
ok(array_keys($j['most_active'][0]) === ['board_id', 'title', 'batches', 'members', 'last_at', 'url'] && $j['most_active'][0]['url'] === "/boards/$hidden", 'JSON most_active: a whitelist');
$dj = admin_data($jm);
ok(array_keys($dj) === ['may', 'usage', 'trash', 'agents', 'most_active'] && $dj['agents'] === ['failed' => $dj['agents']['failed'], 'waiting' => $dj['agents']['waiting']], 'the admin (JSON): may, usage, trash, agents, most_active');
ok(str_contains(page($jm, '/admin')['body'], 'href="/admin/dispatches?status=failed"'), 'the Agents tiles link to the dispatches page');
finish();
