<?php
/**
 * Proofs 1, 5, 6 and 7 at the doors (docs/build-specs/canvas.md "Proof"): the state for an editor, a commenter and a stranger; the catch-up
 * and resync; the page's mount and board.open once per render with my recents; the outline page and its Markdown; the rail's panels; the
 * thumbnail (an editor's PNG stored, a second within five minutes 429, the card showing it, a viewer refused, a bad one refused).
 */
require __DIR__ . '/lib.php';
echo "1. the doors\n";
[$jp, ] = sign_on(26);     // Priya, the owner
[$jb, ] = sign_on(28);     // Bea, an editor
[$jd, ] = sign_on(30);     // Dana, a Viewer (capped at comment)
[$jl, ] = sign_on(31);     // Lee, a stranger to the board
$b = make_board($jp, 'SMOKE Doors');
share_as(26, $b, 'member', 28, 'edit');
share_as(26, $b, 'member', 30, 'edit');     // the Viewer's cap makes it comment
$sticky = item_op(['text' => 'First', 'z' => 'a1']);
$r = ops_post($jp, $b, [$sticky]);
ok($r['code'] === 200 && body_json($r)['seq'] > 0, 'a batch commits and answers its seq');

$s = state_of($jb, $b);
$j = body_json($s);
ok($s['code'] === 200 && $j['board']['level'] === 'edit' && count($j['items']) === 1 && $j['items'][0]['id'] === $sticky['item']['id'] && $j['last_seq'] === body_json($r)['seq'],
    'the state for an editor: the board at edit, every live item, the last seq');
ok(isset($j['people']['26']) && $j['people']['26']['name'] !== '' && isset($j['server_time']), 'the state names the people its items and permissions name');
$s = state_of($jd, $b);
ok($s['code'] === 200 && body_json($s)['board']['level'] === 'comment', 'the state for a Viewer shared at edit: comment (the role\'s cap)');
$s = state_of($jl, $b);
ok($s['code'] === 404 && str_contains($s['body'], 'There is no such board'), 'the state for a stranger: 404 "There is no such board" — the same nothing as a missing board');
$s = req('GET', "/boards/$b/state", ['jar' => $jb, 'headers' => ['Accept: application/json', 'Accept-Encoding: gzip']]);
ok($s['code'] === 200 && preg_match('/^Content-Encoding: gzip/mi', $s['headers']) === 1 && json_decode((string) gzdecode($s['body']), true)['last_seq'] > 0, 'the state is gzip-encoded when the browser accepts it');
ok(state_of($jb, '00000000-0000-4000-8000-000000000000')['code'] === 404 && req('GET', '/boards/not-a-board/state', ['jar' => $jb])['code'] === 404, 'a board that does not exist: 404');

// the catch-up
$first = (int) body_json($r)['seq'];
$seqs = [];
foreach (['A', 'B', 'C'] as $t) { $seqs[] = (int) body_json(ops_post($jb, $b, [item_op(['text' => $t])]))['seq']; }
$o = body_json(ops_since($jd, $b, $first));
ok(array_column($o['batches'], 'seq') === $seqs && $o['last_seq'] === end($seqs) && $o['resync'] === false && $o['more'] === false,
    'ops?after= answers the batches after a seq, in seq order, with the last seq — to a commenter too');
ok($o['batches'][0]['member_id'] === 28 && $o['batches'][0]['client_id'] === 'proof-tab' && $o['batches'][0]['source'] === 'web' && isset($o['batches'][0]['summary']['bbox']),
    'a batch carries who, the tab, the source and the summary\'s box');
ok(ops_since($jl, $b, 0)['code'] === 404, 'a stranger\'s catch-up: 404');
ok(ops_since($jb, $b, -1)['code'] === 422 || ops_since($jb, $b, -1)['code'] === 200, 'a nonsense seq does not break the door');
pg("INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot) VALUES ('$b', 1, {$seqs[1]}, 'compaction', '{}')");
$o = body_json(ops_since($jb, $b, $first));
ok($o['resync'] === true && $o['batches'] === [], 'after a compaction, a seq older than it answers resync: the client reloads the state (decision 7)');
$o = body_json(ops_since($jb, $b, $seqs[1]));
ok($o['resync'] === false && array_column($o['batches'], 'seq') === [$seqs[2]], 'a seq at the compaction or after still catches up');
pg("DELETE FROM board_versions WHERE board_id = '$b'");

echo "7. the page, board.open, recents\n";
$since = last_activity_id();
$p1 = page($jb, "/boards/$b");
$p2 = page($jb, "/boards/$b?x=100&y=50&zoom=2");
$opens = activity('board.open', $since);
ok($p1['code'] === 200 && count($opens) === 2 && $opens[0]['board_id'] === $b && (int) $opens[0]['actor_member_id'] === 28, 'board.open: one row per render, with the board');
ok(json_decode((string) $opens[1]['after'], true)['view'] === ['x' => 100.0, 'y' => 50.0, 'zoom' => 2.0] || json_decode((string) $opens[1]['after'], true)['view'] == ['x' => 100, 'y' => 50, 'zoom' => 2],
    'board.open carries the view it opened at');
ok((int) one("SELECT count(*) FROM board_recents WHERE board_id = CAST(:b AS uuid) AND member_id = 28 AND visited_at > now() - interval '1 minute'", ['b' => $b]) === 1, 'my recents keep the board');
preg_match('/<div id="board-canvas"([^>]*)>/', $p1['body'], $m);
$attrs = $m[1] ?? '';
foreach (['data-board="' . $b . '"', 'data-level="edit"', 'data-locked="0"', 'data-member="28"', 'data-state-url="/boards/' . $b . '/state"', 'data-ops-url="/boards/' . $b . '/ops"',
          'data-poll-seconds="3"', 'data-soft-budget="4000"', 'data-hard-cap="10000"', 'data-batch-max="500"'] as $want) {
    if (!str_contains($attrs, $want)) { ok(false, "the mount carries $want"); }
}
$apache = getenv('BD_APP') === 'apache';
ok($apache ? !str_contains($p1['body'], 'data-test=') : str_contains($attrs, 'data-test="1"'),
    $apache ? 'under the real vhost the page carries NO test hook (only tests/dev_router.php sets it — canvas-core decision 8)' : 'under the proofs\' dev router the mount carries data-test="1"');
ok(preg_match('/data-client="[0-9a-f-]{36}"/', $attrs) === 1 && preg_match('/data-csrf="[0-9a-f]{64}"/', $attrs) === 1, 'the mount carries the canvas-core attributes: board, level, member, a fresh client id, the CSRF token, the doors, the limits');
preg_match('/data-client="([0-9a-f-]{36})"/', $p2['body'], $c2);
ok(($c2[1] ?? '') !== '' && !str_contains($p1['body'], $c2[1]), 'every render mints its own client id (one a tab)');
ok(str_contains($p2['body'], 'data-view="100 50 2"') && str_contains($p1['body'], '<noscript>') && str_contains($p1['body'], '/boards/' . $b . '/outline'),
    'the asked view rides on the mount; without JavaScript the page links the outline');
$man = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/html/assets/boards/manifest.json'), true);
ok(str_contains($p1['body'], '/assets/boards/' . $man['file']) && str_contains($p1['body'], '/assets/boards/' . $man['css']), 'the page loads the committed bundle the manifest names');
ok(str_contains($p1['body'], 'id="board-tool-sticky"') && !str_contains(page($jd, "/boards/$b")['body'], 'id="board-tool-sticky"'),
    'an editor\'s toolbar has the drawing tools; a commenter\'s has none');
ok(str_contains($p1['body'], 'class="board-canvas-page"') && str_contains($p1['body'], 'class="minimenu"'), 'the canvas page: the menu collapsed, the page sized to the window');
$h = req('GET', "/boards/$b", ['jar' => $jb, 'headers' => ['HX-Request: true']]);
ok(preg_match('/^HX-Redirect: \/boards\/' . $b . '/mi', $h['headers']) === 1, 'an HTMX navigation into a board becomes a full page load (the island needs its own document)');
ok(page($jl, "/boards/$b")['code'] === 404, 'a stranger opening the board: 404');
$jv = body_json(page($jb, "/boards/$b", ['headers' => ['Accept: application/json']]));
ok(($jv['data']['board']['my_level'] ?? '') === 'edit' && ($jv['data']['board']['state_url'] ?? '') === "/boards/$b/state", 'board-view answers JSON: the board, my level, where its state is');

echo "5. the outline\n";
$since = last_activity_id();
$ol = page($jd, "/boards/$b/outline");
ok($ol['code'] === 200 && substr_count($ol['body'], 'class="outline-item"') === 4 && str_contains($ol['body'], '?item=' . $sticky['item']['id']),
    'the outline page lists every item, each opening the canvas at it (a commenter reads it)');
$md = body_json(page($jd, "/boards/$b/outline", ['headers' => ['Accept: application/json']]))['data']['markdown'] ?? '';
ok(str_starts_with($md, '# SMOKE Doors') && str_contains($md, '## On the canvas') && str_contains($md, '- [sticky, light_yellow] First') && str_contains($ol['body'], e_('- [sticky, light_yellow] First')),
    'its Markdown is bd_board_outline() verbatim — the page carries it for "Copy as Markdown"');
ok(count(array_filter(activity('screen.view', $since), static fn ($a) => $a['screen'] === 'board-outline' && $a['board_id'] === $b)) >= 1, 'the outline logs screen.view with the board');
ok(page($jl, "/boards/$b/outline")['code'] === 404, 'a stranger\'s outline: 404');

echo "   the rail\n";
$shape = item_op(['type' => 'shape', 'x' => 400, 'style' => ['fillColor' => '#ffffff'], 'data' => ['shape' => 'ellipse'], 'z' => 'a2']);
ops_post($jp, $b, [$shape]);
$rp = page($jb, "/boards/$b/rail?panel=item&ids=" . $sticky['item']['id']);
ok($rp['code'] === 200 && str_contains($rp['body'], 'id="rail-sticky-colors"') && str_contains($rp['body'], 'data-board-command="style"') && str_contains($rp['body'], 'id="rail-start-view"'),
    'Properties for a sticky: Miro\'s palette, each a command for the island (not a form), the start view');
$rp = page($jb, "/boards/$b/rail?panel=item&ids=" . $shape['item']['id'] . ',' . $sticky['item']['id']);
ok(str_contains($rp['body'], 'id="rail-fill-colors"') && str_contains($rp['body'], 'id="rail-sticky-colors"') && str_contains($rp['body'], '2 items selected'), 'Properties for a mixed selection: both palettes');
$rp = page($jd, "/boards/$b/rail?panel=item&ids=" . $sticky['item']['id']);
ok($rp['code'] === 200 && !str_contains($rp['body'], 'data-board-command') && str_contains($rp['body'], 'Sticky note'), 'a commenter sees the item but no control');
ok(str_contains(page($jb, "/boards/$b/rail?panel=outline")['body'], 'id="rail-outline"') && str_contains(page($jb, "/boards/$b/rail?panel=comments")['body'], 'id="comment-rail"')
   && str_contains(page($jb, "/boards/$b/rail?panel=history")['body'], 'Built by slice 7') && str_contains(page($jb, "/boards/$b/rail?panel=badge")['body'], 'Can edit'),
    'the rail\'s other panels: the outline, Comments (slice 6\'s) and History (slice 7\'s placeholder), my badge');
ok(page($jl, "/boards/$b/rail?panel=item")['code'] === 404 && page($jb, "/boards/$b/rail?panel=nope")['code'] === 404, 'the rail is behind the wall; an unknown panel is 404');

echo "6. the thumbnail\n";
$png = static function (int $w, int $h): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 255, 249, 177)); ob_start(); imagepng($im); return (string) ob_get_clean(); };
$post = static fn (string $jar, string $board, string $body, string $type = 'image/png') => req('POST', "/boards/$board/thumbnail",
    ['jar' => $jar, 'raw' => $body, 'headers' => ['Content-Type: ' . $type, 'Accept: application/json', 'X-CSRF-Token: ' . csrf_for($jar)]]);
$since = last_activity_id();
$t = $post($jb, $b, $png(480, 300));
$aid = (int) (body_json($t)['record_id'] ?? 0);
$row = q("SELECT a.*, b.thumbnail_attachment_id FROM attachments a JOIN boards b ON b.id = a.record_uuid WHERE a.id = :a", ['a' => $aid])[0] ?? [];
ok($t['code'] === 200 && ($row['record_type'] ?? '') === 'board_thumbnail' && (int) $row['thumbnail_attachment_id'] === $aid && str_starts_with((string) $row['storage_path'], 'thumbnails/' . $b . '/'),
    'an editor\'s PNG is stored as the board\'s thumbnail attachment, under the store, by a relative path');
ok(is_file(getenv('BD_STORAGE_DIR') . '/' . $row['storage_path']) && (int) $row['width'] === 480 && (int) $row['height'] === 300, 'its file is written, its size recorded');
$t2 = $post($jb, $b, $png(480, 300));
ok($t2['code'] === 429 && preg_match('/^Retry-After: \d+/mi', $t2['headers']) === 1 && str_contains($t2['body'], 'Not yet'), 'a second within five minutes: 429 "not yet", with Retry-After');
ok(activity('board.thumbnail', $since) === [] && count(array_filter(q('SELECT action FROM activity_log WHERE id > :s', ['s' => $since]), static fn ($a) => str_contains($a['action'], 'thumbnail'))) === 0,
    'the thumbnail is not logged: it is a cache (canvas-core decision 10)');
pg("UPDATE attachments SET created_at = now() - interval '6 minutes' WHERE id = $aid");
$old = getenv('BD_STORAGE_DIR') . '/' . $row['storage_path'];
$t3 = $post($jb, $b, $png(320, 200));
$aid3 = (int) (body_json($t3)['record_id'] ?? 0);
clearstatcache();
ok($t3['code'] === 200 && $aid3 !== $aid && !is_file($old) && (int) one('SELECT count(*) FROM attachments WHERE id = :a', ['a' => $aid]) === 0,
    'five minutes on, a new one replaces it: the old row deleted, its file unlinked');
$b2 = make_board($jp, 'SMOKE Thumb rules');
share_as(26, $b2, 'member', 28, 'edit');
share_as(26, $b2, 'member', 30, 'view');
ok($post($jb, $b2, $png(500, 300))['code'] === 422 && $post($jb, $b2, 'not a png')['code'] === 422 && $post($jb, $b2, str_repeat('x', 600 * 1024))['code'] === 422,
    'too large, not a PNG, or over 512 kB: 422');
ok($post($jd, $b2, $png(100, 60))['code'] === 403 && $post($jl, $b2, $png(100, 60))['code'] === 404, 'a viewer may not post one (403); a stranger finds no board (404)');
$g = req('GET', "/boards/$b/thumbnail", ['jar' => $jd]);
ok($g['code'] === 200 && preg_match('/^Content-Type: image\/png/mi', $g['headers']) === 1 && str_starts_with($g['body'], "\x89PNG"), 'the current one is served behind the wall (a commenter reads it)');
ok(req('GET', "/boards/$b/thumbnail", ['jar' => $jl])['code'] === 404 && req('GET', "/boards/$b2/thumbnail", ['jar' => $jb])['code'] === 404, 'a stranger: 404; a board with none: 404');
$list = page($jb, '/boards');
ok(str_contains($list['body'], 'id="board-card-' . $b . '-thumb"') && str_contains($list['body'], "/boards/$b/thumbnail?v=$aid3"), 'the card in /boards shows it');
$lj = body_json(page($jb, '/boards', ['headers' => ['Accept: application/json']]));
$card = array_values(array_filter($lj['data']['rows'] ?? [], static fn ($x) => $x['board_id'] === $b))[0] ?? [];
ok(($card['thumbnail_url'] ?? '') === "/boards/$b/thumbnail?v=$aid3", 'the list\'s JSON names it too');
finish();
