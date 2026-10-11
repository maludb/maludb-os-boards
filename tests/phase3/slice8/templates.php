<?php
/**
 * Slice 8 — the shipped templates and the gallery (docs/build-specs/templates-export.md "Proof" 1, 2, 4): the seed (six boards, owner NULL, the item counts, every child inside its frame, the flowchart's
 * connectors attached and captioned, re-applying changes nothing, a shipped template never changes); the gallery as a Member, by category and by whose, an external seeing none, a Viewer seeing them
 * with no Use; a template's page with its outline; Delete for the maker of a personal template only. Run through tests/phase3/slice8/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);   // Priya, a Member
[$jm] = sign_on(27);   // Marco, a Boards admin
[$jb] = sign_on(28);   // Bea, a Member by capability
[$jd] = sign_on(30);   // Dana, a Viewer
[$ja] = sign_on(29);   // Ann, an external Viewer

echo "1. The seed\n";
$ids = array_values(SHIPPED);
$rows = q("SELECT id::text, title, owner_member_id, created_by, is_template, template_scope, template_category, item_count, description FROM boards WHERE id::text LIKE '7e000000-%' ORDER BY id");
ok(count($rows) === 6 && array_unique(array_column($rows, 'template_scope')) === ['shipped'] && array_unique(array_column($rows, 'owner_member_id')) === [null] && array_unique(array_column($rows, 'created_by')) === [null]
    && array_unique(array_column($rows, 'is_template')) === [true], 'six shipped templates, no owner and no creator');
ok(array_column($rows, 'title') === ['Retrospective', 'Brainstorm', 'SWOT analysis', 'User story map', 'Flowchart', 'Weekly planning'], 'in the spec\'s order: ' . implode(', ', array_column($rows, 'title')));
ok(array_map('intval', array_column($rows, 'item_count')) === [7, 7, 9, 12, 12, 11], 'the item counts: 7, 7, 9, 12, 12, 11');
ok(array_column($rows, 'template_category') === ['retrospective', 'brainstorm', 'strategy', 'planning', 'diagram', 'planning'], 'the categories: ' . implode(', ', array_column($rows, 'template_category')));
ok(count(array_filter($rows, static fn ($r) => strlen((string) $r['description']) > 20)) === 6 && str_starts_with($rows[0]['description'], 'Look back on a piece of work'), 'each has its sentence of description');
$live = (int) one("SELECT count(*) FROM board_items WHERE board_id::text LIKE '7e000000-%' AND deleted_at IS NULL");
ok($live === 58 && (int) one("SELECT count(*) FROM board_items WHERE id::text NOT LIKE '7e00000_-0000-4000-8000-0000000000__' AND board_id::text LIKE '7e000000-%'") === 0, "58 items, every id in the 7e00000N-… shape ($live)");
$out = (int) one("SELECT count(*) FROM board_items c JOIN board_items f ON f.id = c.parent_id WHERE c.board_id::text LIKE '7e000000-%' AND NOT (f.bbox @> c.bbox)");
$kids = (int) one("SELECT count(*) FROM board_items c WHERE c.board_id::text LIKE '7e000000-%' AND c.parent_id IS NOT NULL");
ok($out === 0 && $kids === 23, "every child ($kids of them) lies inside its frame's box");
$cn = q("SELECT i.id::text, i.data->'captions'->0->>'content' AS cap, i.start_item_id IS NOT NULL AND i.end_item_id IS NOT NULL AS joined, i.style->>'endStrokeCap' AS cap_end, i.data->>'shape' AS shape
         FROM board_items i WHERE i.board_id = CAST(:b AS uuid) AND i.type = 'connector' ORDER BY i.id", ['b' => SHIPPED['flow']]);
ok(count($cn) === 6 && array_unique(array_column($cn, 'joined')) === [true] && array_unique(array_column($cn, 'cap_end')) === ['arrow'] && array_unique(array_column($cn, 'shape')) === ['elbowed']
    && array_values(array_filter(array_column($cn, 'cap'))) === ['yes', 'no'], 'the flowchart\'s six connectors are attached, elbowed, with arrowheads and the captions "yes" and "no"');
ok((float) one("SELECT width FROM board_items WHERE id = '7e000001-0000-4000-8000-000000000005'") === 200.0 && one("SELECT style->>'fillColor' FROM board_items WHERE id = '7e000001-0000-4000-8000-000000000005'") === 'light_green'
    && plain('7e000001-0000-4000-8000-000000000005') === 'What went well?', 'a sticky is 200 × 200, its colour and its words as the table says');
ok(one("SELECT data->>'shape' FROM board_items WHERE id = '7e000005-0000-4000-8000-000000000003'") === 'diamond' && one("SELECT data->>'title' FROM board_items WHERE id = '7e000003-0000-4000-8000-000000000002'") === 'Strengths',
    'a shape\'s kind and a frame\'s title are where the island reads them (data.shape, data.title)');
$sum = static fn (): string => (string) one("SELECT md5(string_agg(b.id::text || b.title || b.item_count, ',' ORDER BY b.id)) || md5(COALESCE((SELECT string_agg(i.id::text || i.z || i.data::text || i.style::text, ',' ORDER BY i.id) FROM board_items i WHERE i.board_id::text LIKE '7e000000-%'), ''))
                                              FROM boards b WHERE b.id::text LIKE '7e000000-%'");
$before = $sum();
pg(file_get_contents(dirname(__DIR__, 3) . '/db/016_seed_templates.sql'));
ok($sum() === $before && (int) one("SELECT count(*) FROM boards WHERE id::text LIKE '7e000000-%'") === 6, 're-applying db/016 changes nothing');
ok((int) one("SELECT count(*) FROM search_index WHERE board_id::text LIKE '7e000000-%'") === 0, 'a shipped template is not in the search index (db/010)');
echo "   and they never change\n";
$ver = (int) one("SELECT count(*) FROM board_versions WHERE board_id::text LIKE '7e000000-%'");
ok($ver === 0, 'no version of a shipped template exists (db/015 keeps the worker off them)');
$r = save_version($jp, SHIPPED['retro'], 'Priya tries');
ok($r['code'] === 403, 'a Member (level view) cannot save a version of one: ' . msg($r));
$r = ops_post($jp, SHIPPED['retro'], [['op' => 'update', 'id' => '7e000001-0000-4000-8000-000000000005', 'fields' => ['x' => 999]]]);
ok($r['code'] === 403, 'nor change an item of one through the canvas (' . $r['code'] . ')');
ok((float) item_row('7e000001-0000-4000-8000-000000000005')['x'] === 130.0, '…the sticky is where the table put it');

echo "2. The gallery\n";
$g = gallery($jp);
$d = body_json($g)['data'] ?? [];
ok($g['code'] === 200 && ($d['total'] ?? 0) === 6 && count($d['rows']) === 6, 'a Member sees the six shipped templates');
ok(array_column($d['rows'], 'title') === ['Brainstorm', 'Flowchart', 'Retrospective', 'SWOT analysis', 'User story map', 'Weekly planning'], 'shipped first, then by title: ' . implode(', ', array_column($d['rows'], 'title')));
$r0 = $d['rows'][2];
ok(array_keys($r0) === ['board_id', 'title', 'description', 'scope', 'scope_words', 'category', 'category_words', 'item_count', 'by', 'thumbnail_url', 'url'] && $r0['by'] === 'Shipped with Boards' && $r0['scope_words'] === 'Shipped'
    && $r0['url'] === '/templates/' . SHIPPED['retro'], 'a row is a whitelist: ' . implode(', ', array_keys($r0)));
ok(array_column(gallery_rows($jp, 'category=planning'), 'title') === ['User story map', 'Weekly planning'] && count(gallery_rows($jp, 'category=diagram')) === 1, 'category=planning: the story map and the weekly plan; diagram: one');
ok(count(gallery_rows($jp, 'scope=shipped')) === 6 && gallery_rows($jp, 'scope=business') === [] && gallery_rows($jp, 'scope=personal') === [] && gallery_rows($jp, 'scope=mine') === [], 'scope=shipped six; the business\'s and mine none yet');
ok(count(gallery_rows($jp, 'category=nonsense&scope=nonsense')) === 6, 'a category or scope that is not one is ignored, not an error');
$h = gallery($jp, '', []);
ok($h['code'] === 200 && str_contains($h['body'], 'id="templates-grid"') && str_contains($h['body'], 'id="template-card-' . SHIPPED['retro'] . '"') && str_contains($h['body'], 'id="template-filter-category-planning"')
    && str_contains($h['body'], 'id="template-filter-scope-shipped"') && str_contains($h['body'], 'id="template-use-form-' . SHIPPED['retro'] . '"') && str_contains($h['body'], 'id="template-card-' . SHIPPED['retro'] . '-tile"'), 'the page: chips, the grid, a card with its tile and its inline Use form');
ok(substr_count($h['body'], 'class="badge text-bg-primary" id="template-card-') === 6 && str_contains($h['body'], '>Shipped</span>'), 'each card wears the scope chip "Shipped"');
$e = gallery($ja);
$ed = body_json($e)['data'] ?? [];
ok($e['code'] === 200 && ($ed['total'] ?? -1) === 0 && ($ed['rows'] ?? 1) === [], 'an external sees none');
$e = gallery($ja, '', []);
ok(str_contains($e['body'], 'id="templates-empty"'), '…and the page says there are none');
$v = gallery($jd);
$vd = body_json($v)['data'] ?? [];
ok($v['code'] === 200 && $vd['total'] === 6 && $vd['may']['use'] === false, 'a Viewer sees the six and may not use them');
$vh = gallery($jd, '', []);
ok(!str_contains($vh['body'], 'template-use-form-') && !str_contains($vh['body'], 'template-use-open-') && str_contains($vh['body'], 'id="template-card-' . SHIPPED['retro'] . '-preview"'), '…no Use on their cards, a Preview');
$vp = template_page($jd, SHIPPED['retro'], []);
ok($vp['code'] === 200 && !str_contains($vp['body'], 'id="template-use-card"') && str_contains($vp['body'], 'id="template-no-use"'), 'a Viewer\'s template page has no Use card');
$r = use_template($jd, SHIPPED['retro']);
ok($r['code'] === 403, 'and a forged Use is refused (' . $r['code'] . ': ' . msg($r) . ')');
ok(gallery($jm, 'page=2')['code'] === 200 && body_json(gallery($jm, 'page=2'))['data']['rows'] === [], 'page 2 is empty, not an error');
$none = req('GET', '/templates', ['headers' => ['Accept: application/json']]);
ok($none['code'] === 401, 'the gallery wants a sign-in');

echo "4. A template's page\n";
$t = template_page($jp, SHIPPED['swot']);
$td = body_json($t)['data'] ?? [];
ok($t['code'] === 200 && $td['title'] === 'SWOT analysis' && $td['scope'] === 'shipped' && $td['by'] === 'Shipped with Boards' && $td['item_count'] === 9 && $td['open_url'] === '/boards/' . SHIPPED['swot'], 'JSON: title, scope, by whom, items, the canvas URL');
ok(str_contains($td['outline'], '## Strengths') && str_contains($td['outline'], '## Opportunities') && str_contains($td['outline'], '[sticky, light_green] What do we do well?'), 'the outline is the database\'s: headings per frame, a sticky\'s colour and words');
ok($td['may']['use'] === true && $td['may']['delete'] === false, 'a shipped template cannot be deleted, by anyone');
$tm = body_json(template_page($jm, SHIPPED['swot']))['data'];
ok($tm['my_level'] === 'view' && $tm['may']['delete'] === false, 'nor by the Boards admin: a shipped template is view for everyone, an admin included (db/017)');
$r = ops_post($jm, SHIPPED['swot'], [['op' => 'update', 'id' => '7e000001-0000-4000-8000-000000000005', 'fields' => ['x' => 999]]]);
ok($r['code'] >= 400 && $r['code'] < 500 && (int) one("SELECT count(*) FROM board_ops WHERE board_id = CAST('" . SHIPPED['swot'] . "' AS uuid)") === 0, "the Boards admin's edit of a shipped template: refused ({$r['code']}), no op written");
ok(one("SELECT bd_board_level(CAST('" . SHIPPED['swot'] . "' AS uuid), 27)") === 'view' && one("SELECT level FROM bd_member_board_levels(27) WHERE board_id = CAST('" . SHIPPED['swot'] . "' AS uuid)") === 'view',
    'both level functions agree: view (db/017)');
$th = template_page($jp, SHIPPED['swot'], []);
ok($th['code'] === 200 && str_contains($th['body'], 'id="template-outline"') && str_contains($th['body'], '<h4 class="fs-15 fw-semibold mt-3 mb-1">SWOT analysis</h4>') && str_contains($th['body'], 'Strengths')
    && str_contains($th['body'], 'id="template-open"') && str_contains($th['body'], 'href="/boards/' . SHIPPED['swot'] . '"') && str_contains($th['body'], 'id="template-use-form-' . SHIPPED['swot'] . '"')
    && str_contains($th['body'], 'id="template-scope"') && !str_contains($th['body'], 'id="template-delete"') && str_contains($th['body'], 'data-screen="template-view"'), 'the page: the outline, Open on the canvas, the Use form, the scope chip, no Delete');
$ownBoard = make_board($jp, 'SMOKE not a template');
$nt = template_page($jp, $ownBoard);
ok($nt['code'] === 404 && body_json($nt)['error']['message'] === 'There is no such template.', 'a board that is not a template is not found at /templates/{board}: ' . body_json($nt)['error']['message']);
ok(template_page($jp, 'not-a-uuid')['code'] === 404, 'nor is a thing that is not a uuid');
ok(template_page($jb, $ownBoard)['code'] === 404, 'nor a stranger\'s board');
$unlog = last_activity_id();
template_page($jp, SHIPPED['flow'], []);
gallery($jp, '', []);
$sv = q("SELECT screen, board_id::text FROM activity_log WHERE id > :s AND action = 'screen.view' ORDER BY id", ['s' => $unlog]);
ok($sv === [['screen' => 'template-view', 'board_id' => SHIPPED['flow']], ['screen' => 'templates', 'board_id' => null]], 'screen.view is logged with the board on template-view (the gallery without one)');
// the gallery as a personal template maker, Delete for the maker only
$pt = body_json(save_template($jp, $ownBoard, ['title' => 'SMOKE Priya template', 'category' => 'workshop']));
$tid = (string) ($pt['record_id'] ?? '');
$pm = body_json(template_page($jp, $tid))['data'];
ok($pm['scope'] === 'personal' && $pm['may']['delete'] === true && $pm['by'] === 'SMOKE Priya', 'the maker of a personal template may delete it');
$ph = template_page($jp, $tid, []);
ok(str_contains($ph['body'], 'id="template-delete"') && str_contains($ph['body'], 'hx-confirm="Delete SMOKE Priya template?'), 'the page shows Delete with its confirm');
ok(template_page($jb, $tid)['code'] === 404 && template_page($jm, $tid)['code'] === 200, 'another Member cannot open it; the Boards admin can (full on every board)');
ok(array_column(gallery_rows($jm, 'scope=personal'), 'title') === [] && count(gallery_rows($jm)) === 6, 'but it is not in the Boards admin\'s gallery: the gallery is for choosing what to use');
ok(array_column(gallery_rows($jp, 'scope=mine'), 'title') === ['SMOKE Priya template'], 'it is in its maker\'s, under Mine');
$del = act($jp, '/boards/delete.php', ['board' => $tid]);
ok($del['code'] === 200 && one('SELECT deleted_at IS NOT NULL FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $tid]) === true, 'the maker deletes it (slice 1\'s board_delete: to the trash)');
ok(gallery_rows($jp, 'scope=mine') === [] && template_page($jp, $tid)['code'] === 404, '…and it is gone from the gallery and the page');
$r = act($jb, '/boards/delete.php', ['board' => SHIPPED['flow']]);
ok($r['code'] === 403 || $r['code'] === 404, 'a Member deleting a shipped template: ' . $r['code']);
finish();
