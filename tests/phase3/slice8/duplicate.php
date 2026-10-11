<?php
/**
 * Slice 8 — use, duplicate, save as template, publish (docs/build-specs/templates-export.md "Proof" 3): the retrospective used into a folder (a private board of the Member's with 7 items and the frames' children
 * mapped), a board duplicated ("(copy)", connectors' ends mapped), saved as a personal template another Member cannot see, published by an admin for every insider and refused to a Member in the
 * database's words, an agent's publish a paused action; each logged with the board and its source. Run through tests/phase3/slice8/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);   // Priya, a Member
[$jm] = sign_on(27);   // Marco, a Boards admin
[$jb] = sign_on(28);   // Bea, a Member by capability
[$jd] = sign_on(30);   // Dana, a Viewer
[$jl] = sign_on(31);   // Lee, a Viewer by capability
[$ja] = sign_on(29);   // Ann, an external Viewer

echo "3. Use a template\n";
$since = last_activity_id();
$fid = (int) body_json(act($jp, '/folders/save.php', ['name' => 'SMOKE Slice 8 folder']))['record_id'];
$beaFolder = (int) body_json(act($jb, '/folders/save.php', ['name' => 'SMOKE Bea folder']))['record_id'];
$r = use_template($jp, SHIPPED['retro'], ['folder' => $fid]);
$j = body_json($r);
$new = (string) ($j['record_id'] ?? '');
ok($r['code'] === 200 && $j['ok'] === true && preg_match('/^[0-9a-f-]{36}$/', $new) === 1 && $j['location'] === "/boards/$new" && $j['did'] === 'Made a board from Retrospective' && $j['refresh'] === 'boardChanged'
    && $j['source_board'] === SHIPPED['retro'] && $j['board_id'] === $new, 'template_use: ok, did, record_id the new board, location /boards/{new}, refresh boardChanged');
$b = q('SELECT title, owner_member_id, is_template, template_scope, duplicated_from::text AS src, item_count, description, created_by FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new])[0];
ok($b['title'] === 'Retrospective' && (int) $b['owner_member_id'] === 26 && $b['is_template'] === false && $b['template_scope'] === null && $b['src'] === SHIPPED['retro'] && (int) $b['item_count'] === 7
    && str_starts_with((string) $b['description'], 'Look back') && (int) $b['created_by'] === 26, 'a board of Priya\'s, not a template, remembering its source, with the template\'s title and description and its 7 items');
ok(level_of($new, 26) === 'full' && level_of($new, 28) === 'none' && level_of($new, 30) === 'none' && (int) one('SELECT count(*) FROM board_permissions WHERE board_id = CAST(:b AS uuid)', ['b' => $new]) === 0, 'private: Priya has full, nobody else any level');
ok((int) one('SELECT count(*) FROM board_folder_entries WHERE folder_id = :f AND board_id = CAST(:b AS uuid)', ['f' => $fid, 'b' => $new]) === 1, 'filed in the folder she chose');
$kids = q("SELECT c.id::text, c.plain_text, f.id::text AS fid, f.data->>'title' AS ftitle, f.board_id::text AS fboard FROM board_items c JOIN board_items f ON f.id = c.parent_id WHERE c.board_id = CAST(:b AS uuid) ORDER BY c.plain_text", ['b' => $new]);
ok(count($kids) === 3 && array_column($kids, 'ftitle') === ['To improve', 'Went well', 'Actions'] && array_unique(array_column($kids, 'fboard')) === [$new]
    && count(array_filter(array_column($kids, 'id'), static fn ($i) => str_starts_with($i, '7e'))) === 0, 'the three stickies sit in the new frames (To improve, Went well, Actions), on the new board, with new ids');
ok(counts_of($new) === ['frame' => 3, 'sticky_note' => 3, 'text' => 1], 'three frames, three stickies, the title text: ' . json_encode(counts_of($new)));
ok(counts_of(SHIPPED['retro']) === ['frame' => 3, 'sticky_note' => 3, 'text' => 1] && item_total(SHIPPED['retro']) === 7 && (int) one("SELECT count(*) FROM board_versions WHERE board_id = CAST(:b AS uuid)", ['b' => SHIPPED['retro']]) === 0, 'the template itself is as it was');
$fr = q("SELECT x, y, width, height FROM board_items WHERE board_id = CAST(:b AS uuid) AND data->>'title' = 'Went well'", ['b' => $new])[0];
ok((float) $fr['x'] === -650.0 && (float) $fr['width'] === 600.0, 'a copied frame keeps its place and size');
$st = body_json(state_of($jp, $new));
ok(count($st['items'] ?? $st['data']['items'] ?? []) === 7, 'and the canvas reads the 7 items of the copy');
$ul = activity('template.use', $since);
ok(count($ul) === 1 && $ul[0]['board_id'] === $new && json_decode($ul[0]['after'], true) == ['source_board' => SHIPPED['retro'], 'title' => 'Retrospective', 'scope' => null, 'category' => null, 'folder_id' => $fid], 'template.use is logged with the new board and the source');
// a title, no folder; the form; refusals
$r = use_template($jp, SHIPPED['swot'], ['title' => 'SMOKE Our SWOT']);
$sw = (string) body_json($r)['record_id'];
ok($r['code'] === 200 && one('SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $sw]) === 'SMOKE Our SWOT' && (int) one('SELECT count(*) FROM board_folder_entries WHERE board_id = CAST(:b AS uuid)', ['b' => $sw]) === 0 && item_total($sw) === 9, 'a title of her own, no folder: 9 items');
$r = use_template($jp, SHIPPED['swot'], ['title' => str_repeat('x', 201)]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['title']), 'a title over 200: 422 on the field');
$r = use_template($jp, SHIPPED['swot'], ['folder' => 999999]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['folder']), 'a folder that is not there: 422 on the field');
$r = use_template($jp, SHIPPED['swot'], ['folder' => $beaFolder]);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['folder']), 'a folder that is Bea\'s: 422 (it is not Priya\'s to see)');
$r = use_template($jp, $new);
ok($r['code'] === 404 && msg($r) === 'There is no such template.', 'a board that is not a template: 404 "There is no such template."');
$r = use_template($jp, uuid4());
ok($r['code'] === 404, 'a template that does not exist: 404');
$r = use_template($jp, '');
ok($r['code'] === 404, 'no template at all: 404');
$r = use_template($jl, SHIPPED['swot']);
ok($r['code'] === 403 && msg($r) === 'You may not create a board.', 'a Viewer by capability: 403 "' . msg($r) . '"');
$r = use_template($ja, SHIPPED['swot']);
ok($r['code'] === 403 || $r['code'] === 404, 'an external: refused (' . $r['code'] . ')');
$h = use_template($jp, SHIPPED['brainstorm'], [], ['Accept: text/html']);
ok($h['code'] === 302 && preg_match('#^/boards/[0-9a-f-]{36}$#', $h['location']) === 1, 'the form without JavaScript: a redirect to the new board (' . $h['location'] . ')');
$hx = use_template($jp, SHIPPED['brainstorm'], [], ['HX-Request: true', 'Accept: text/html']);
ok(preg_match('/^HX-Location: (.+)$/mi', $hx['headers'], $hm) === 1 && preg_match('#^/boards/[0-9a-f-]{36}$#', json_decode(trim($hm[1]), true)['path'] ?? '') === 1, 'over HTMX: HX-Location to it');

echo "   Duplicate a board\n";
[$b1, $frame, $alpha, $beta, $gamma] = board_with_frame($jp, 'SMOKE Source');
$con = ['op' => 'create', 'item' => ['id' => uuid4(), 'type' => 'connector', 'start_item_id' => $alpha, 'end_item_id' => $gamma, 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'z' => 'a4',
        'style' => ['endStrokeCap' => 'arrow'], 'data' => ['shape' => 'elbowed', 'captions' => [['content' => 'then']]]]];
ops_post($jp, $b1, [$con]);
share_as(26, $b1, 'member', 28, 'view');
$since = last_activity_id();
$r = duplicate_board($jp, $b1);
$j = body_json($r);
$dup = (string) ($j['record_id'] ?? '');
ok($r['code'] === 200 && $j['did'] === 'Made a copy of SMOKE Source' && $j['location'] === "/boards/$dup" && $j['source_board'] === $b1, 'board_duplicate: ok, did, record_id the copy, location');
$dd = q('SELECT title, owner_member_id, duplicated_from::text AS src, is_template FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $dup])[0];
ok($dd['title'] === 'SMOKE Source (copy)' && (int) $dd['owner_member_id'] === 26 && $dd['src'] === $b1 && $dd['is_template'] === false, 'titled "… (copy)", Priya\'s, remembering its source');
ok(counts_of($dup) === counts_of($b1) && item_total($dup) === 5, 'the same items by type: ' . json_encode(counts_of($dup)));
$cc = q("SELECT c.start_item_id::text AS s, c.end_item_id::text AS e, c.data->'captions'->0->>'content' AS cap, a.plain_text AS sw, g.plain_text AS ew, a.board_id::text AS sb FROM board_items c JOIN board_items a ON a.id = c.start_item_id JOIN board_items g ON g.id = c.end_item_id
         WHERE c.board_id = CAST(:b AS uuid) AND c.type = 'connector'", ['b' => $dup])[0];
ok($cc['sw'] === 'Alpha' && $cc['ew'] === 'Gamma' && $cc['cap'] === 'then' && $cc['sb'] === $dup && $cc['s'] !== $alpha && $cc['e'] !== $gamma, 'the connector joins the copies of Alpha and Gamma (not the originals), caption kept');
$ch = q("SELECT c.plain_text, f.data->>'title' AS ft FROM board_items c JOIN board_items f ON f.id = c.parent_id WHERE c.board_id = CAST(:b AS uuid) ORDER BY 1", ['b' => $dup]);
ok($ch === [['plain_text' => 'Alpha', 'ft' => 'Plan'], ['plain_text' => 'Beta', 'ft' => 'Plan']], 'Alpha and Beta are in the copy of the frame Plan');
ok((int) one("SELECT count(*) FROM board_items WHERE id IN (SELECT id FROM board_items WHERE board_id = CAST(:a AS uuid)) AND board_id = CAST(:b AS uuid)", ['a' => $b1, 'b' => $dup]) === 0, 'no item id is shared with the source');
ok((float) item_row($alpha)['x'] === 200.0 && item_total($b1) === 5, 'the source is as it was');
ok((int) one('SELECT count(*) FROM board_permissions WHERE board_id = CAST(:b AS uuid)', ['b' => $dup]) === 0, 'the copy is private: the source\'s shares do not follow');
// the same by a person who may only view the source
$r = duplicate_board($jb, $b1, ['title' => 'SMOKE Bea copy', 'folder' => $beaFolder]);
$bd = (string) (body_json($r)['record_id'] ?? '');
ok($r['code'] === 200 && (int) one('SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $bd]) === 28 && item_total($bd) === 5, 'Bea (view on the source) copies it: hers, 5 items');
ok(level_of($bd, 26) === 'none', 'and Priya has no access to Bea\'s copy');
$r = duplicate_board($jl, $b1);
ok($r['code'] === 403, 'a Viewer may not copy (403: ' . msg($r) . ')');
$r = duplicate_board($jb, $b1 . '0');
ok($r['code'] === 404, 'a board that is not a uuid: 404');
$private = make_board($jp, 'SMOKE Priya private');
$r = duplicate_board($jb, $private);
ok($r['code'] === 404 && msg($r) === 'There is no such board.', 'a board Bea cannot open does not exist to her: 404 "' . msg($r) . '"');
$dl = activity('board.duplicate', $since);
ok(count($dl) === 2 && $dl[0]['board_id'] === $dup && json_decode($dl[0]['after'], true)['source_board'] === $b1 && json_decode($dl[0]['after'], true)['title'] === 'SMOKE Source (copy)', 'board.duplicate is logged with the new board and the source');

echo "   Save as a template (personal)\n";
$since = last_activity_id();
$r = save_template($jp, $b1, ['title' => 'SMOKE Priya plan', 'category' => 'planning']);
$j = body_json($r);
$pt = (string) ($j['record_id'] ?? '');
ok($r['code'] === 200 && $j['did'] === 'Saved SMOKE Priya plan as a template of yours' && $j['location'] === "/templates/$pt?notice=saved", 'template_save: ok, did, record_id the template, location /templates/{id}');
$tt = q('SELECT is_template, template_scope, template_category, owner_member_id, duplicated_from::text AS src FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $pt])[0];
ok($tt['is_template'] === true && $tt['template_scope'] === 'personal' && $tt['template_category'] === 'planning' && (int) $tt['owner_member_id'] === 26 && $tt['src'] === $b1 && item_total($pt) === 5, 'a personal template of planning, Priya\'s, 5 items');
ok(array_column(gallery_rows($jp, 'scope=personal'), 'board_id') === [$pt] && in_array('SMOKE Priya plan', array_column(gallery_rows($jp, 'category=planning'), 'title'), true), 'Priya sees it under Mine and under planning');
ok(array_column(gallery_rows($jb), 'title') === ['Brainstorm', 'Flowchart', 'Retrospective', 'SWOT analysis', 'User story map', 'Weekly planning'] && template_page($jb, $pt)['code'] === 404, 'another Member does not see it, nor its page');
ok(use_template($jb, $pt)['code'] === 404, '…nor use it (404)');
ok((int) one("SELECT count(*) FROM board_permissions WHERE board_id = CAST(:b AS uuid)", ['b' => $pt]) === 0, 'it carries no share');
$r = use_template($jp, $pt, ['title' => 'SMOKE From my template']);
ok($r['code'] === 200 && item_total((string) body_json($r)['record_id']) === 5, 'and Priya makes a board from it');
$r = save_template($jp, $b1, ['title' => 'SMOKE No category']);
ok($r['code'] === 200 && one('SELECT template_category FROM boards WHERE id = CAST(:b AS uuid)', ['b' => body_json($r)['record_id']]) === 'other', 'with no category: other');
$r = save_template($jp, $b1, []);
ok($r['code'] === 422 && body_json($r)['error']['fields']['title'] === 'Give the template a title.', 'no title: 422 "Give the template a title."');
$r = save_template($jp, $b1, ['title' => 'x', 'category' => 'cooking']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['category']), 'a category that is not one: 422 on the field');
$r = save_template($jp, $b1, ['title' => '']);
ok($r['code'] === 422 && isset(body_json($r)['error']['fields']['title']), 'an empty title: 422');
$r = save_template($jl, $b1, ['title' => 'x']);
ok($r['code'] === 403, 'a Viewer may not save a template (403)');
$r = save_template($jb, $private, ['title' => 'x']);
ok($r['code'] === 404, 'nor from a board she cannot open (404)');
$sl = activity('template.save', $since);
ok(count($sl) === 2 && $sl[0]['board_id'] === $pt && json_decode($sl[0]['after'], true) == ['source_board' => $b1, 'title' => 'SMOKE Priya plan', 'scope' => 'personal', 'category' => 'planning', 'folder_id' => null], 'template.save is logged with the new template and the source (two made, the refused ones are not logged)');

echo "   Publish for the business\n";
$since = last_activity_id();
$r = publish_template($jp, $b1, ['title' => 'SMOKE Member publishes', 'category' => 'planning']);
ok($r['code'] === 403 && msg($r) === 'You may not publish a business template.', 'a Member\'s publish is refused (403): "' . msg($r) . '"');
try {
    as_member(26);
    pdo()->query("SELECT bd_board_duplicate(CAST('$b1' AS uuid), 26, 'x', 'business', NULL)");
    $dbSays = '(no refusal)';
} catch (PDOException $e) {
    $dbSays = (string) preg_replace('/^.*?ERROR:\s*(.*?)(\n.*)?$/s', '$1', $e->getMessage());
}
ok(str_contains($dbSays, 'Only a Boards admin publishes a template for the business.'), 'and the database refuses it too, in its own words: "' . $dbSays . '"');
ok((int) one("SELECT count(*) FROM boards WHERE template_scope = 'business'") === 0 && activity('template.publish', $since) === [], 'nothing was made, nothing logged');
$r = publish_template($jm, $b1, ['title' => 'SMOKE Business plan', 'category' => 'planning']);
$j = body_json($r);
$bt = (string) ($j['record_id'] ?? '');
ok($r['code'] === 200 && $j['did'] === 'Published SMOKE Business plan for the business' && $j['location'] === "/templates/$bt?notice=published", 'template_publish by Marco (a Boards admin): ok, record_id the template');
$bb = q('SELECT template_scope, template_category, owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $bt])[0];
ok($bb['template_scope'] === 'business' && $bb['template_category'] === 'planning' && (int) $bb['owner_member_id'] === 27, 'a business template of planning, Marco\'s');
ok(array_column(gallery_rows($jp, 'scope=business'), 'title') === ['SMOKE Business plan'] && array_column(gallery_rows($jb, 'scope=business'), 'title') === ['SMOKE Business plan'], 'every Member sees it under The business\'s');
$vd = body_json(gallery($jd))['data'];
ok($vd['total'] === 7 && in_array($bt, array_column($vd['rows'], 'board_id'), true), 'a Viewer sees it too (7 templates)');
ok(gallery_rows($ja) === [] && template_page($ja, $bt)['code'] === 404, 'an external does not (no template, no page)');
$bp = body_json(template_page($jp, $bt))['data'];
ok($bp['scope'] === 'business' && $bp['by'] === 'SMOKE Marco' && $bp['my_level'] === 'view' && $bp['may']['delete'] === false, 'its page: by SMOKE Marco, Priya at view, no Delete for her');
$r = use_template($jp, $bt, ['title' => 'SMOKE From the business']);
ok($r['code'] === 200 && item_total((string) body_json($r)['record_id']) === 5 && (int) one('SELECT owner_member_id FROM boards WHERE id = CAST(:b AS uuid)', ['b' => body_json($r)['record_id']]) === 26, 'Priya uses it: her board, 5 items');
$r = act($jp, '/boards/delete.php', ['board' => $bt]);
ok($r['code'] === 403, 'but she cannot delete it (403)');
$r = act($jm, '/boards/delete.php', ['board' => $bt]);
ok($r['code'] === 200, 'its maker can');
$pl = activity('template.publish', $since);
ok(count($pl) === 1 && $pl[0]['board_id'] === $bt && json_decode($pl[0]['after'], true) == ['source_board' => $b1, 'title' => 'SMOKE Business plan', 'scope' => 'business', 'category' => 'planning', 'folder_id' => null], 'template.publish is logged with the new template and the source');
echo "   the approval, and an agent\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['template_publish']['approval'] ?? null) === 'other' && ($reg['actions']['template_save']['approval'] ?? null) === null && ($reg['actions']['template_use']['approval'] ?? null) === null
    && ($reg['actions']['board_duplicate']['approval'] ?? null) === null && isset($reg['actions']['template_save'], $reg['actions']['template_use'], $reg['actions']['board_duplicate']), 'the kernel pauses an agent\'s template_publish (approval other); the other three do not pause');
share_as(26, $b1, 'agent', 42, 'view');
$a = agent_act(42, 8300, '/templates/save.php', ['board' => $b1, 'title' => 'SMOKE Agent template', 'category' => 'diagram']);
ok($a['code'] === 200 && body_json($a)['ok'] === true && (int) one("SELECT count(*) FROM activity_log WHERE action = 'template.save' AND source = 'agent' AND agent_run_id = 8300") === 1, 'an agent with view saves a personal template (logged as source agent with its run)');
$a = agent_act(42, 8301, '/templates/publish.php', ['board' => $b1, 'title' => 'SMOKE Agent publish', 'category' => 'diagram']);
ok($a['code'] === 403, 'an agent holds no publish right: the database says no even when the kernel would approve (' . $a['code'] . ')');
$a = agent_act(42, 8302, '/boards/duplicate.php', ['board' => $b1]);
ok($a['code'] === 200 && body_json($a)['ok'] === true, 'an agent duplicates a board it can view');
finish();
