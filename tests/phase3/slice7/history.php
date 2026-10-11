<?php
/**
 * Slice 7 — the history page (docs/build-specs/history-versions.md "Proof" 1, 2, 4, 5): the changes of a board newest first with their words, chips and Show links, the filters, "Since your last
 * visit", paging by HTMX, an undo's "undid #n"; a named version saved by an editor and refused to a commenter; the preview as an outline for a viewer and a 404 for a stranger; the visits
 * (hidden from a commenter). Run through tests/phase3/slice7/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);   // Priya, the owner
[$jb] = sign_on(28);   // Bea, edit
[$jd] = sign_on(30);   // Dana, comment
[$jl] = sign_on(31);   // Lee, a viewer
[$ja] = sign_on(29);   // Ann, nothing

$b = make_board($jp, 'SMOKE History');
share_as(26, $b, 'member', 28, 'edit');
share_as(26, $b, 'member', 30, 'comment');
share_as(26, $b, 'member', 31, 'view');
share_as(26, $b, 'agent', 42, 'edit');

echo "1. The changes\n";
$frame = frame_op('Plan', -400, -300, 800, 600);
$s1 = item_op(['text' => 'First', 'x' => -100, 'y' => 0, 'z' => 'a1']);
$s2 = item_op(['text' => 'Second', 'x' => 100, 'y' => 0, 'z' => 'a2']);
ops_post($jp, $b, [$frame, $s1, $s2]);
ops_post($jb, $b, [['op' => 'update', 'id' => $s1['item']['id'], 'fields' => ['x' => -150]]]);
ops_post($jp, $b, [['op' => 'delete', 'ids' => [$s2['item']['id']]]]);
$s3 = item_op(['text' => 'By the agent', 'x' => 500, 'y' => 300, 'z' => 'a3']);
$agentSeq = commit_as_member($b, 42, 'agent', [$s3]);
$j = body_json(history_json($jp, $b))['data'];
$rows = $j['rows'];
ok(count($rows) === 4 && $j['total'] === 4 && $rows[0]['seq'] === $agentSeq && $rows[0]['seq'] > $rows[1]['seq'] && $rows[1]['seq'] > $rows[2]['seq'] && $rows[2]['seq'] > $rows[3]['seq'],
    'four batches, newest first: the agent\'s, Priya\'s delete, Bea\'s move, Priya\'s three creates');
ok(str_contains($rows[3]['what'], 'created 3') && str_contains($rows[3]['what'], '2 stickies') && str_contains($rows[3]['what'], '1 frame'), 'the first batch in words: "' . $rows[3]['what'] . '"');
ok($rows[2]['what'] === 'changed 1 sticky' && $rows[1]['what'] === 'deleted 1 sticky' && $rows[0]['what'] === 'created 1 sticky', 'the others: "' . $rows[2]['what'] . '", "' . $rows[1]['what'] . '", "' . $rows[0]['what'] . '"');
ok($rows[0]['who']['kind'] === 'agent' && $rows[0]['source'] === 'agent' && $rows[2]['who']['member_id'] === 28 && $rows[2]['source'] === 'web', 'who and where from: the agent\'s batch is source agent, Bea\'s is web');
ok($rows[0]['show'] === "/boards/$b?x=500&y=300&zoom=1", 'Show is at the centre of the batch\'s box: ' . $rows[0]['show']);
$h = history_html($jp, $b);
ok($h['code'] === 200 && str_contains($h['body'], 'id="history-changes"') && str_contains($h['body'], 'id="history-versions"') && str_contains($h['body'], 'id="history-visits"') && str_contains($h['body'], 'id="board-history-header"'), 'the page has its three sections and header');
ok(str_contains($h['body'], 'id="change-row-' . $agentSeq . '"') && preg_match('/id="change-row-' . $agentSeq . '".*?<span class="badge bg-soft-info text-info">agent<\/span>/s', $h['body']) === 1, 'the agent\'s row wears the agent chip');
ok(str_contains($h['body'], 'id="change-show-' . $agentSeq . '" href="/boards/' . $b . '?x=500&amp;y=300&amp;zoom=1"'), 'and its Show link flies to the batch');
ok(substr_count($h['body'], 'history-day') >= 1, 'the rows are grouped by day');
// filters
$f = body_json(history_json($jp, $b, 'member=28'))['data'];
ok($f['total'] === 1 && $f['rows'][0]['who']['member_id'] === 28, 'member=28: Bea\'s one batch');
$f = body_json(history_json($jp, $b, 'source=agent'))['data'];
ok($f['total'] === 1 && $f['rows'][0]['seq'] === $agentSeq, 'source=agent: the agent\'s batch');
$f = body_json(history_json($jp, $b, 'source=web'))['data'];
ok($f['total'] === 3, 'source=web: the three people\'s batches');
$f = body_json(history_json($jp, $b, 'source=nonsense&member=abc&since=yesterday'))['data'];
ok($f['total'] === 4, 'a source, member or date that is not one is ignored, not an error');
$f = body_json(history_json($jp, $b, 'since=' . date('Y-m-d', time() + 86400 * 2)))['data'];
ok($f['total'] === 0, 'since a date in the future: nothing');
$f = body_json(history_json($jp, $b, 'since=' . date('Y-m-d', time() - 86400)))['data'];
ok($f['total'] === 4, 'since yesterday: all four');
$fh = history_html($jp, $b, 'source=agent');
ok(str_contains($fh['body'], 'id="history-filter-source-agent"') && !str_contains($fh['body'], 'id="change-row-' . $rows[1]['seq'] . '"'), 'the filter chips are links and the page follows the filter');

echo "   since your last visit\n";
pg("UPDATE board_ops SET at = now() - interval '3 hours' WHERE board_id = '$b' AND seq <= {$rows[2]['seq']}");
pg("INSERT INTO board_recents (member_id, board_id, visited_at) VALUES (26, '$b', now() - interval '2 hours') ON CONFLICT (member_id, board_id) DO UPDATE SET visited_at = excluded.visited_at");
$h = history_html($jp, $b);
$divider = strpos($h['body'], 'id="history-last-visit"');
$p1 = strpos($h['body'], 'id="change-row-' . $rows[1]['seq'] . '"');
$p2 = strpos($h['body'], 'id="change-row-' . $rows[2]['seq'] . '"');
ok($divider !== false && $p1 !== false && $p2 !== false && $p1 < $divider && $divider < $p2, 'the divider "Since your last visit" sits between what is newer than the visit and what is older');
$h = history_html($jp, $b, 'since=' . date('Y-m-d', time() - 86400));
ok(!str_contains($h['body'], 'id="history-last-visit"'), 'with a since filter there is no divider');
$h = history_html($jb, $b);
ok(!str_contains($h['body'], 'id="history-last-visit"'), 'a person with no recorded visit sees none');
ok(body_json(history_json($jp, $b))['data']['last_visit'] !== null && body_json(history_json($jb, $b))['data']['last_visit'] === null, 'JSON names my last visit (Bea has none)');

echo "   an undo, and paging\n";
pg("INSERT INTO board_ops (board_id, member_id, source, ops, inverse, summary, undoes_seq) VALUES ('$b', 26, 'web', '[]', '[]', '{\"counts\":{\"delete\":1},\"types\":{\"sticky_note\":1},\"items\":1}', ARRAY[{$agentSeq}]::bigint[])");
$h = history_html($jp, $b);
ok(str_contains($h['body'], 'undid #' . $agentSeq), 'an undo says what it undid');
$big = make_board($jp, 'SMOKE History paging');
pg("INSERT INTO board_ops (board_id, member_id, source, ops, inverse, summary) SELECT '$big', 26, 'web', '[]', '[]', '{\"counts\":{\"update\":1},\"types\":{\"sticky_note\":1},\"items\":1,\"bbox\":[0,0,10,10]}' FROM generate_series(1, 59)");
$p1 = body_json(history_json($jp, $big))['data'];
$p2 = body_json(history_json($jp, $big, 'page=2'))['data'];
ok($p1['total'] === 59 && count($p1['rows']) === 50 && count($p2['rows']) === 9 && $p2['rows'][0]['seq'] < $p1['rows'][49]['seq'], 'paged by 50: 59 changes are 50 and 9, the second page older');
$h = history_html($jp, $big);
ok(str_contains($h['body'], 'id="history-more"') && str_contains($h['body'], 'hx-get="/boards/' . $big . '/history?part=changes&amp;page=2"'), 'the first page offers "Older changes" over HTMX');
$part = history_html($jp, $big, 'part=changes&page=2', ['HX-Request: true']);
ok($part['code'] === 200 && !str_contains($part['body'], 'board-history-header') && substr_count($part['body'], 'id="change-row-') === 9 && !str_contains($part['body'], 'id="history-more"'), 'the HTMX partial is the next 9 rows alone, with no further button');
$nojs = history_html($jp, $big, 'page=2');
ok(substr_count($nojs['body'], 'id="change-row-') === 9 && str_contains($nojs['body'], 'board-history-header'), 'without HTMX, ?page=2 is the whole page');

echo "2. A named version\n";
$since = last_activity_id();
$r = save_version($jb, $b, 'Before the workshop');
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['record_id'] === 1 && str_contains($j['did'], 'Before the workshop') && $j['location'] === "/boards/$b/history?version=1&notice=saved", 'Bea (edit) saves a named version: record_id 1, lands on its preview');
$v = q('SELECT * FROM board_versions WHERE board_id = CAST(:b AS uuid)', ['b' => $b])[0] ?? [];
ok(($v['reason'] ?? '') === 'manual' && $v['label'] === 'Before the workshop' && (int) $v['saved_by'] === 28 && (int) $v['item_count'] === 3, 'a manual version, labelled, by Bea, holding the 3 live items');
$r = save_version($jd, $b, 'Dana tries');
ok($r['code'] === 403 && str_contains(msg($r), 'edit'), 'Dana (comment) is refused in the database\'s words (403): "' . msg($r) . '"');
$r = save_version($jl, $b, 'Lee tries');
ok($r['code'] === 403, 'Lee (view): 403');
$r = save_version($ja, $b, 'Ann tries');
ok($r['code'] === 403, 'Ann, an external with no editing right: 403 (the right is asked before the board)');
$r = post_as($jb, '/boards/version-save.php', ['board' => $b, 'label' => '   ']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['label']), 'an empty name: 422 with the field');
$r = post_as($jb, '/boards/version-save.php', ['board' => $b]);
ok($r['code'] === 422 && body_json($r)['error']['fields']['label'] === 'Give this moment a name.', 'no name at all: 422 "Give this moment a name."');
$r = save_version($jb, $b, str_repeat('x', 121));
ok($r['code'] === 422 && str_contains(msg($r), '120'), 'over 120 characters: 422');
$sv = activity('version.save', $since);
ok(count($sv) === 1 && $sv[0]['board_id'] === $b && json_decode($sv[0]['after'], true) == ['version_no' => 1, 'label' => 'Before the workshop'], 'version.save is logged with the board, the number and the label');
$h = history_html($jb, $b);
ok(preg_match('/id="version-row-1".*?fw-bold[^>]*>Before the workshop/s', $h['body']) === 1, 'the version appears bold with its label');
ok(str_contains($h['body'], 'id="version-form-field-label"') && !str_contains($h['body'], 'id="version-restore-1"'), 'Bea sees the Save form but no Restore (not an owner)');
$h = history_html($jd, $b);
ok(!str_contains($h['body'], 'id="version-form-field-label"') && str_contains($h['body'], 'id="version-preview-1"'), 'Dana sees no Save form, but can preview');
$h = history_html($jp, $b);
ok(str_contains($h['body'], 'id="version-restore-1"') && str_contains($h['body'], 'hx-confirm="Restore version 1? The board as it is now is saved first."'), 'Priya sees Restore with its confirm');
$jv = body_json(history_json($jp, $b))['data']['versions'];
ok(count($jv) === 1 && $jv[0]['named'] === true && $jv[0]['reason_words'] === 'Saved' && $jv[0]['saved_by']['name'] === 'SMOKE Bea', 'JSON: the version, named, with its reason in words and who saved it');
pg("SELECT set_config('app.member_id', '28', false) \\g /dev/null\nSELECT bd_save_version('$b', 28, 'before_restore', NULL)");
pg("INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot, plain_text, item_count) SELECT board_id, 20, 0, 'interval', snapshot, '', item_count FROM board_versions WHERE version_no = 1 AND board_id = '$b'");
$words = array_column(body_json(history_json($jp, $b))['data']['versions'], 'reason_words', 'version_no');
ok(($words[2] ?? '') === 'Before a restore' && ($words[20] ?? '') === 'Autosaved', 'reasons in words: "Before a restore", "Autosaved"');

echo "4. The preview\n";
$sec = item_op(['text' => '<script>alert(1)</script> & more', 'x' => 0, 'y' => 100, 'z' => 'a5']);
ops_post($jp, $b, [$sec]);
$no = body_json(save_version($jp, $b, 'With markup'))['record_id'];
$h = history_html($jl, $b, 'version=1');
ok($h['code'] === 200 && str_contains($h['body'], 'id="history-preview"') && str_contains($h['body'], 'id="history-preview-outline"') && str_contains($h['body'], 'id="history-preview-back"'), 'a viewer (Lee) previews version 1: the preview, its outline and Back to the history');
ok(!str_contains($h['body'], 'id="history-preview-restore"'), 'a viewer has no Restore this version');
ok(preg_match('/<h5[^>]*>.*Plan.*<\/h5>/s', $h['body']) === 1 && str_contains($h['body'], 'First') && !str_contains($h['body'], 'Second'), 'the outline: the frame as a heading, the stickies as lines; version 1 held First and not the deleted Second');
$h = history_html($jp, $b, "version=$no");
ok(!str_contains($h['body'], '<script>alert(1)') && str_contains($h['body'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; more'), 'an item\'s text is escaped in the outline, never interpreted');
ok(str_contains($h['body'], 'id="history-preview-restore"') && str_contains($h['body'], 'fw-bold'), 'the owner sees Restore this version');
$pv = body_json(history_json($jp, $b, 'version=1'))['data'];
ok($pv['preview']['version']['version_no'] === 1 && str_contains($pv['preview']['outline'], '- ') , 'JSON: the preview carries the version and its outline Markdown');
ok(history_html($ja, $b, 'version=1')['code'] === 404, 'Ann (no access) previewing: 404');
ok(history_html($ja, $b)['code'] === 404, 'and the history page itself: 404');
ok(history_html($jp, $b, 'version=99')['code'] === 404 && history_html($jp, $b, 'version=abc')['code'] === 404, 'a version that does not exist, or is not a number: 404');
$since = last_activity_id();
history_html($jp, $b, 'version=1');
history_html($jp, $b);
$sv = array_values(array_filter(activity('screen.view', $since), static fn ($l) => $l['screen'] === 'board-history'));
ok(count($sv) === 2 && $sv[0]['board_id'] === $b && json_decode((string) $sv[0]['after'], true) === ['version' => 1] && ($sv[1]['after'] === null || json_decode((string) $sv[1]['after'], true) === null), 'screen.view on board-history carries the board, and after.version when previewing');

echo "5. Visits\n";
$vb = make_board($jp, 'SMOKE History visits');
share_as(26, $vb, 'member', 28, 'edit');
share_as(26, $vb, 'member', 30, 'comment');
page($jp, "/boards/$vb");
page($jp, "/boards/$vb");
page($jb, "/boards/$vb");
$vis = body_json(history_json($jp, $vb))['data']['visits'];
$byId = array_column($vis, null, 'member_id');
ok(count($vis) === 2 && $byId[26]['opens'] === 2 && $byId[28]['opens'] === 1 && $byId[28]['name'] === 'SMOKE Bea', 'the owner sees who opened the board and how often: Priya 2, Bea 1');
$h = history_html($jb, $vb);
ok(str_contains($h['body'], 'id="history-visits"') && str_contains($h['body'], 'id="visit-row-28"'), 'an editor sees the visits panel too');
$vis = body_json(history_json($jd, $vb))['data']['visits'];
$h = history_html($jd, $vb);
ok($vis === null && !str_contains($h['body'], 'history-visits'), 'a commenter sees no visits panel (JSON null, no section)');
pg("UPDATE activity_log SET occurred_at = now() - interval '31 days' WHERE board_id = '$vb' AND actor_member_id = 28 AND action = 'board.open'");
$vis = array_column(body_json(history_json($jp, $vb))['data']['visits'], null, 'member_id');
ok(!isset($vis[28]) && isset($vis[26]), 'visits are the last 30 days: Bea\'s open 31 days ago is out');
$rail = page($jl, "/boards/$b/rail?panel=history");
$newest = (int) body_json(history_json($jp, $b))['data']['rows'][0]['seq'];
ok($rail['code'] === 200 && str_contains($rail['body'], 'id="change-row-' . $newest . '"') && str_contains($rail['body'], 'href="/boards/' . $b . '/history"')
   && substr_count($rail['body'], 'id="change-row-') <= 10 && !str_contains($rail['body'], 'Built by slice'),
    "the canvas rail's History panel (a viewer): the newest changes as the page draws them, at most ten, and the way to the history page");
ok(page($ja, "/boards/$b/rail?panel=history")['code'] === 404, "the rail's History panel to a stranger: 404");
finish();
