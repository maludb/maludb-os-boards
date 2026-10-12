<?php
/**
 * Slice 9 — search (agents-search.md "Proof" 7): a sticky's word finds its item (the link selects it), a comment's word its comment, a board's title its board; a private board's rows never reach
 * another Member; a shipped template's items and a template of the business's own never appear; kind= and board= filter; best first; the words escaped with the matches marked; the catch-up
 * re-indexes a row deleted from search_index and removes a stale one. Run through tests/phase3/slice9/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jb] = sign_on(28);
[$jl] = sign_on(31);
[$jm] = sign_on(27);
$since = last_activity_id();

echo "7. Search\n";
$a = make_board($jp, 'SMOKE Zebrafish board');
share_as(26, $a, 'member', 28, 'edit');
$s1 = item_op(['text' => 'Quokka migration plan', 'x' => 0, 'y' => 0, 'z' => 'a1']);
$s2 = item_op(['text' => 'alpaca alpaca alpaca shearing', 'x' => 300, 'y' => 0, 'z' => 'a2']);
$s3 = item_op(['text' => 'one alpaca only', 'x' => 600, 'y' => 0, 'z' => 'a3']);
$s4 = item_op(['text' => 'Narwhal & co "quoted" <script>alert(1)</script>', 'x' => 900, 'y' => 0, 'z' => 'a4']);
$fr = frame_op('Platypus frame', 0, 600, 800, 400);
ops_post($jp, $a, [$s1, $s2, $s3, $s4, $fr]);
$c = body_json(thread_on($jp, $a, 'Wombat question about the plan', $s1['item']['id']))['record_id'];
$p = make_board($jp, 'SMOKE Private pangolin');
$ps = item_op(['text' => 'Quokka secret pangolin', 'x' => 0, 'y' => 0, 'z' => 'a1']);
ops_post($jp, $p, [$ps]);
$pc = body_json(thread_on($jp, $p, 'Wombat secret', $ps['item']['id']))['record_id'];

$rows = search_rows($jp, 'q=quokka');
$kinds = array_map(static fn ($r) => $r['kind'] . ':' . $r['id'], $rows);
ok(in_array('item:' . $s1['item']['id'], $kinds, true) && in_array('item:' . $ps['item']['id'], $kinds, true) && count($rows) === 2, 'a sticky\'s word finds its item — on every board Priya can open (' . count($rows) . ' results)');
$hit = array_values(array_filter($rows, static fn ($r) => $r['id'] === $s1['item']['id']))[0];
ok($hit['url'] === '/boards/' . $a . '?item=' . $s1['item']['id'] && $hit['board_id'] === $a && $hit['board_title'] === 'SMOKE Zebrafish board' && $hit['excerpt'] === 'Quokka migration plan' && $hit['author'] === 'SMOKE Priya', 'the result: the item\'s link (the canvas flies to it), the board, the excerpt, the author');
$rows = search_rows($jb, 'q=quokka');
ok(count($rows) === 1 && $rows[0]['id'] === $s1['item']['id'], 'a private board\'s rows never reach another Member: Bea sees only the shared board\'s sticky');
ok(search_rows($jl, 'q=quokka') === [] && search_rows($jl, 'q=wombat') === [], 'and Lee, who has no board, finds nothing at all');
$rows = search_rows($jb, 'q=wombat');
ok(count($rows) === 1 && $rows[0]['kind'] === 'comment' && $rows[0]['id'] === $c && $rows[0]['url'] === '/boards/' . $a . '?comment=' . $c, 'a comment\'s word finds its comment (the link opens the thread)');
$rows = search_rows($jp, 'q=zebrafish');
ok(count($rows) === 1 && $rows[0]['kind'] === 'board' && $rows[0]['id'] === $a && $rows[0]['url'] === '/boards/' . $a && $rows[0]['title'] === 'SMOKE Zebrafish board', 'a board\'s title finds its board');
$rows = search_rows($jp, 'q=platypus');
ok(count($rows) === 1 && $rows[0]['kind'] === 'item' && $rows[0]['item_type'] === 'frame' && $rows[0]['title'] === 'Platypus frame', 'a frame is found by its title');
// filters
ok(count(search_rows($jp, 'q=plan')) === 2 && array_unique(array_column(search_rows($jp, 'q=plan&kind=item'), 'kind')) === ['item'] && count(search_rows($jp, 'q=plan&kind=comment')) === 1 && search_rows($jp, 'q=plan&kind=board') === [],
    'kind= narrows to items or comments or boards');
ok(count(search_rows($jp, 'q=quokka&board=' . $a)) === 1 && search_rows($jp, 'q=quokka&board=' . uuid4()) === [] && count(search_rows($jp, 'q=quokka&board=' . $p)) === 1, 'board= narrows to one board (and a board that is not there finds nothing)');
$bogus = search_json($jp, 'q=quokka&kind=nonsense&board=nope');
ok($bogus['code'] === 200 && count(body_json($bogus)['data']['rows']) === 2, 'an unknown kind and a board that is not a uuid are ignored');
// order
$rows = search_rows($jp, 'q=alpaca');
ok(count($rows) === 2 && $rows[0]['id'] === $s2['item']['id'] && $rows[1]['id'] === $s3['item']['id'], 'best first: three alpacas before one');
// escaping and marks
$html = page($jp, '/search?q=narwhal');
ok($html['code'] === 200 && str_contains($html['body'], '<mark>Narwhal</mark> &amp; co &quot;quoted&quot;') && !str_contains($html['body'], '<script>alert'), 'the page escapes the words (an &, quotes, a <script> typed on a sticky) and marks the match with <mark>');
$jn = search_rows($jp, 'q=narwhal');
ok(str_starts_with($jn[0]['excerpt'], 'Narwhal & co "quoted"') && !str_contains($jn[0]['excerpt'], "\x01") && !str_contains($jn[0]['excerpt'], '<mark>'), 'JSON carries the plain words, no markers');
ok(str_contains($html['body'], 'id="search-result-item-' . $s4['item']['id'] . '"') && str_contains($html['body'], 'id="search-form-field-q"') && str_contains($html['body'], 'id="search-filter-kind-comment"'), 'the ids: search-result-{kind}-{id}, search-form-field-q, the kind chips');
// short and empty
$one = search_json($jp, 'q=a');
$d = body_json($one)['data'];
ok($one['code'] === 200 && $d['rows'] === [] && $d['total'] === 0 && $d['min_length'] === 2, 'a single character searches nothing');
$pr = page($jp, '/search?q=a');
ok(str_contains($pr['body'], 'id="search-prompt"') && !str_contains($pr['body'], 'Nothing found'), 'and the page asks for two characters');
$none = page($jp, '/search?q=zzzxqj');
ok(str_contains($none['body'], 'id="search-empty"') && str_contains($none['body'], 'Nothing found') && str_contains($none['body'], 'Try fewer words.'), '"Nothing found" with the tip "Try fewer words."');
// templates are left out
$tpl = save_template($jp, $a, ['title' => 'SMOKE Quokka template', 'category' => 'workshop']);
$tid = body_json($tpl)['record_id'];
$pub = publish_template($jm, $a, ['title' => 'SMOKE Quokka business template', 'category' => 'workshop']);
ok($tpl['code'] === 200 && $pub['code'] === 200 && (int) one('SELECT count(*) FROM board_items WHERE board_id = CAST(:b AS uuid)', ['b' => $tid]) >= 4, 'a personal and a business template of the board exist, with their items');
$rows = search_rows($jp, 'q=quokka');
ok(count($rows) === 2 && !in_array($tid, array_column($rows, 'board_id'), true), 'their items and titles never appear in a search (Priya still finds exactly the two stickies)');
ok(search_rows($jp, 'q=template') === [] && search_rows($jb, 'q=business%20template') === [], 'nor does their title');
ok(search_rows($jp, 'q=went%20well') === [] && search_rows($jp, 'q=retrospective') === [], 'a shipped template\'s items and titles never appear');
// comment deleted and board deleted leave the results
post_as($jp, '/comments/delete.php', ['comment' => $c]);
ok(search_rows($jp, 'q=wombat') !== [] && count(search_rows($jp, 'q=wombat')) === 1, 'a deleted comment leaves the results (only the private board\'s remains for Priya)');
// the catch-up
$idx0 = (int) one('SELECT count(*) FROM search_index');
pg("DELETE FROM search_index WHERE entity_uuid = '" . $s1['item']['id'] . "'");
ok(count(search_rows($jp, 'q=migration')) === 0, 'a row deleted from search_index is no longer found');
$stale = uuid4();
$staleC = uuid4();
pg("INSERT INTO search_index (entity_kind, entity_uuid, board_id, title, body, tsv, occurred_at) VALUES ('item', '$stale', '$a', NULL, 'ghost word', to_tsvector('simple', 'ghost word'), now()),
    ('comment', '$staleC', '$a', NULL, 'ghost comment', to_tsvector('simple', 'ghost comment'), now())");
ok(count(search_rows($jp, 'q=ghost')) === 2, 'two stale rows (their entities are gone) are found, ghosts');
$w = worker_pass('search_catch_up');
ok($w['rc'] === 0 && $w['errors'] === [] && !isset($w['ran']['search_catch_up']['stub']) && $w['ran']['search_catch_up']['indexed'] >= 1 && $w['ran']['search_catch_up']['removed'] === 2, 'the catch-up pass: indexed ' . json_encode($w['ran']['search_catch_up'] ?? null));
ok(count(search_rows($jp, 'q=migration')) === 1 && search_rows($jp, 'q=ghost') === [], 'the missing row is back and the stale ones are gone');
ok(count(search_rows($jp, 'q=quokka')) === 2 && search_rows($jp, 'q=went') === [] && (int) one("SELECT count(*) FROM search_index s JOIN boards b ON b.id = s.board_id WHERE b.is_template AND b.template_scope = 'shipped'") === 0, 'it indexed no shipped template');
$w2 = worker_pass('search_catch_up');
ok($w2['ran']['search_catch_up'] === ['indexed' => 0, 'removed' => 0], 'a second pass changes nothing');
// the screen is logged without the words
page($jp, '/search?q=quokka%20migration');
$sv = q("SELECT after FROM activity_log WHERE action = 'screen.view' AND screen = 'search' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since]);
$sa = json_decode((string) $sv[0]['after'], true);
ok($sa['q_length'] === 16 && !str_contains((string) $sv[0]['after'], 'quokka'), 'screen.view on search carries the length of the words, never the words');
// paging
$pg = make_board($jp, 'SMOKE Paging board');
pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by)
    SELECT gen_random_uuid(), '$pg', 'sticky_note', n * 10, 0, 200, 200, 'a' || to_char(n, 'FM999'), '{}', jsonb_build_object('content', bd_rich_text('Pagingword ' || n)), 26, 26 FROM generate_series(1, 25) n");
$r1 = body_json(search_json($jp, 'q=pagingword'))['data'];
$r2 = body_json(search_json($jp, 'q=pagingword&page=2'))['data'];
ok($r1['total'] === 25 && count($r1['rows']) === 20 && count($r2['rows']) === 5 && $r1['page_size'] === 20, 'results are paged 20: 25 in all, 20 then 5');
finish();
