<?php
/**
 * Slice 8 — export (docs/build-specs/templates-export.md "Proof" 5): the SVG door answers image/svg+xml with the dialect (a frame's export frame-relative), an attachment named after the board,
 * `Content-Security-Policy: sandbox`, a 404 without access and a 403 without the right; a board of 450 items is refused 422 with the sentence while its frame exports whole; an empty board
 * answers an empty <svg>; the PNG log POST answers 204; both log `board.export`. Run through tests/phase3/slice8/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);   // Priya
[$jb] = sign_on(28);   // Bea
[$jd] = sign_on(30);   // Dana, a Viewer
[$ja] = sign_on(29);   // Ann, an external Viewer
[$jl] = sign_on(31);   // Lee

echo "5. The SVG door\n";
[$b, $frame, $alpha, $beta, $gamma] = board_with_frame($jp, 'SMOKE Export — board');
share_as(26, $b, 'member', 30, 'view');
share_as(26, $b, 'member', 29, 'view');
$since = last_activity_id();
$r = export_get($jp, $b);
ok($r['code'] === 200 && str_starts_with(header_of($r, 'Content-Type'), 'image/svg+xml') && str_contains(header_of($r, 'Content-Type'), 'utf-8'), 'GET ?format=svg: 200 image/svg+xml; charset=utf-8');
ok(str_starts_with(header_of($r, 'Content-Security-Policy'), 'sandbox') && header_of($r, 'X-Content-Type-Options') === 'nosniff', '`Content-Security-Policy: sandbox`, nosniff');
ok(str_contains(header_of($r, 'Content-Disposition'), 'attachment; filename="SMOKE Export _ board.svg"') && str_contains(header_of($r, 'Content-Disposition'), "filename*=UTF-8''SMOKE%20Export%20%E2%80%94%20board.svg"),
    'an attachment named after the board: ' . header_of($r, 'Content-Disposition'));
$svg = $r['body'];
ok(str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg"') && str_contains($svg, 'data-board="' . $b . '"') && str_contains($svg, 'data-items="4"') && str_contains($svg, 'data-truncated="false"') && str_ends_with($svg, '</svg>'),
    'the dialect: <svg> with the board, 4 items, not truncated');
$x = @simplexml_load_string($svg);
ok($x !== false, 'well-formed XML');
ok(str_contains($svg, 'data-type="frame"') && str_contains($svg, 'data-title="Plan"') && substr_count($svg, 'data-type="sticky_note"') === 3 && str_contains($svg, '>Alpha<') && str_contains($svg, '>Gamma<'), 'the frame Plan with its title, three stickies with their words');
ok(!str_contains($svg, '<script') && !str_contains($svg, 'onload'), 'no script in it');
ok(preg_match('/viewBox="(-?[\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+)"/', $svg, $m) === 1 && (float) $m[1] <= -450 + 1 && (float) $m[3] >= 1700, 'the viewBox spans the board\'s extent plus 50: ' . ($m[0] ?? '?'));
$lg = activity('board.export', $since);
ok(count($lg) === 1 && $lg[0]['board_id'] === $b && json_decode($lg[0]['after'], true) == ['format' => 'svg', 'scope' => 'board', 'items' => 4, 'truncated' => false], 'board.export is logged: format, scope, items, truncated');
echo "   a frame\n";
$fr = export_get($jp, $b, "format=svg&frame=$frame");
$fx = $fr['body'];
ok($fr['code'] === 200 && str_contains($fx, 'data-frame="' . $frame . '"') && str_contains($fx, 'data-items="2"') && str_contains($fx, '>Alpha<') && str_contains($fx, '>Beta<') && !str_contains($fx, '>Gamma<'), 'a frame\'s export holds the frame\'s children only');
ok(preg_match('/viewBox="(-?[\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+)"/', $fx, $m2) === 1 && (float) $m2[1] === 0.0 && (float) $m2[2] === 0.0 && (float) $m2[3] === 800.0 && (float) $m2[4] === 600.0, 'frame-relative: viewBox 0 0 800 600 (' . ($m2[0] ?? '?') . ')');
ok(preg_match('/data-id="' . $alpha . '"[^>]* x="(-?[\d.]+)" y="(-?[\d.]+)"/', $fx, $m3) === 1 && abs((float) $m3[1] - 100) < 1, 'and Alpha sits at its place in the frame (x ' . ($m3[1] ?? '?') . ')');
ok(str_contains(header_of($fr, 'Content-Disposition'), 'filename="SMOKE Export _ board _ Plan.svg"'), 'the file is named for the board and the frame: ' . header_of($fr, 'Content-Disposition'));
$lg = activity('board.export', $since);
ok(count($lg) === 2 && json_decode($lg[1]['after'], true)['scope'] === 'frame' && json_decode($lg[1]['after'], true)['items'] === 2, 'and logged with scope frame and 2 items');
$r = export_get($jp, $b, 'format=svg&frame=' . uuid4());
ok($r['code'] === 404 && str_contains($r['body'], 'There is no such frame'), 'a frame that does not exist: 404');
$r = export_get($jp, $b, "format=svg&frame=$alpha");
ok($r['code'] === 404, 'an item that is not a frame: 404');
[$other, $oframe] = board_with_frame($jp, 'SMOKE Export — other');
$r = export_get($jp, $b, "format=svg&frame=$oframe");
ok($r['code'] === 404, 'another board\'s frame: 404');
$r = export_get($jp, $b, 'format=svg&frame=nonsense');
ok($r['code'] === 404, 'a frame id that is not one: 404');
echo "   who may\n";
ok(export_get($jd, $b)['code'] === 200 && export_get($ja, $b)['code'] === 200, 'Dana (view) and Ann (an external at view) may export what they may open');
$r = export_get($jb, $b);
ok($r['code'] === 404, 'Bea, with no access, gets 404');
$r = export_get($jb, uuid4());
ok($r['code'] === 404, 'a board that is not there: 404');
$r = export_get($jp, $b, 'format=pdf');
ok($r['code'] === 422 && str_contains($r['body'], 'SVG'), 'another format: 422 (PDF is Extended)');
$r = req('GET', "/boards/$b/export?format=svg", ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'signed out: 401');
$n = (int) one("SELECT count(*) FROM activity_log WHERE action = 'board.export'") ;
export_get($jb, $b);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'board.export'") === $n, 'a refused export is not logged');
echo "   an empty board\n";
$empty = make_board($jp, 'SMOKE Export — empty');
$r = export_get($jp, $empty);
ok($r['code'] === 200 && str_contains($r['body'], 'data-items="0"') && !str_contains($r['body'], 'data-type'), 'an empty board answers an empty <svg>');
ok(@simplexml_load_string($r['body']) !== false, '…that is well-formed');

echo "   a board past 400 items\n";
[$big, $crowd] = big_board($jp, 450, 'SMOKE Export — big');
ok(item_total($big) === 450 && (int) one('SELECT item_count FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $big]) === 450, 'the fixture board holds 450 items');
$since = last_activity_id();
$r = export_get($jp, $big);
ok($r['code'] === 422 && str_contains($r['body'], 'This board holds 450 items — export a frame, or a PNG, instead.'), 'the whole board: 422 "This board holds 450 items — export a frame, or a PNG, instead."');
ok(!str_starts_with(header_of($r, 'Content-Type'), 'image/svg+xml') && !str_contains($r['body'], '<svg'), 'no file that passes for the board comes with it');
ok(activity('board.export', $since) === [], '…and nothing is logged');
$r = export_get($jp, $big, "format=svg&frame=$crowd");
ok($r['code'] === 200 && str_contains($r['body'], 'data-items="60"') && str_contains($r['body'], 'data-truncated="false"') && substr_count($r['body'], 'data-type="sticky_note"') === 60, 'its frame exports whole: its 60 stickies');
$lg = activity('board.export', $since);
ok(count($lg) === 1 && json_decode($lg[0]['after'], true)['items'] === 60, '…logged with 60 items');
pg("DELETE FROM board_items WHERE id IN (SELECT id FROM board_items WHERE board_id = '$big' AND type = 'sticky_note' AND parent_id IS NULL LIMIT 50)");
$r = export_get($jp, $big);
ok($r['code'] === 200 && str_contains($r['body'], 'data-items="400"'), 'at exactly 400 items the whole board exports (' . substr_count($r['body'], 'data-id=') . ' elements)');

echo "   the PNG log\n";
$since = last_activity_id();
$r = export_png_log($jp, $b, ['format' => 'png', 'scope' => 'frame', 'items' => 3]);
ok($r['code'] === 204 && $r['body'] === '', 'POST format=png&scope=frame: 204, no body');
$lg = activity('board.export', $since);
ok(count($lg) === 1 && $lg[0]['board_id'] === $b && json_decode($lg[0]['after'], true) == ['format' => 'png', 'scope' => 'frame', 'items' => 3, 'truncated' => false], 'board.export is logged: png, frame, 3 items');
foreach (['view', 'selection'] as $scope) { export_png_log($jp, $b, ['format' => 'png', 'scope' => $scope]); }
ok(count(activity('board.export', $since)) === 3, 'and for the view and the selection');
$r = export_png_log($jp, $b, ['format' => 'png', 'scope' => 'everything']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['scope']), 'a scope that is not one: 422');
$r = export_png_log($jp, $b, ['format' => 'gif', 'scope' => 'view']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['format']), 'a format the browser does not make: 422');
$r = export_png_log($jb, $b);
ok($r['code'] === 404, 'no access: 404');
$r = req('POST', "/boards/$b/export", ['jar' => $jp, 'headers' => ['Accept: application/json'], 'form' => ['format' => 'png', 'scope' => 'view']]);
ok($r['code'] === 403, 'without the CSRF token: 403');
$r = export_png_log($jd, $b);
ok($r['code'] === 204, 'a person with only view may log what they exported (204)');
finish();
