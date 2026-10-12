<?php
/**
 * Proof — the records tools, each against the seeded world (docs/boards-mcp-tool-surface.md): the facts of a board, a board read search → region (the overview, a search, a region and a frame as SVG), an item and what it is connected to,
 * files, changes, versions, comment threads, the outline. THE SVG DIALECT's rules, parsed as XML: frames first, a frame a group whose children are frame-relative, data-cx/data-cy the ABSOLUTE centre, a connector a line between centres, text at most
 * 500 characters, no deleted item, no drawing points, truncation at the limit, 64 kB -> too_large; the outline's 256 kB -> too_large. Every figure is checked against the database as the same member (sql_as). Servers: records :9104.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$A = $w['roadmap'];
/** The SVG as XPath: ns svg. */
function svg_x(string $svg): ?DOMXPath
{
    $d = new DOMDocument();
    if (!@$d->loadXML($svg)) { return null; }
    $x = new DOMXPath($d);
    $x->registerNamespace('s', 'http://www.w3.org/2000/svg');
    return $x;
}
function svg_el(DOMXPath $x, string $id): ?DOMElement { $n = $x->query("//*[@data-id='$id']"); return $n->length ? $n->item(0) : null; }

echo "1. find_boards\n";
$r = tool(PRIYA, 'find_boards', ['limit' => 100]);
$rows = $r['data']['rows'];
ok(array_keys($rows[0]) === ['board_id', 'title', 'owner_name', 'my_level', 'item_count', 'open_threads', 'last_activity_at', 'is_favorite', 'is_template'], 'a row: board_id, title, owner_name, my_level, item_count, open_threads, last_activity_at, is_favorite, is_template');
$times = array_column($rows, 'last_activity_at');
$sorted = $times; rsort($sorted);
ok($times === $sorted && $r['data']['truncated'] === false, 'newest activity first; not truncated');
$ids = array_column($rows, 'board_id');
$visible = array_column(sql_as(PRIYA, 'SELECT board_id FROM mcp_boards WHERE NOT is_template'), 'board_id');
sort($ids); sort($visible);
ok($ids === $visible, 'exactly the boards the view shows Priya (' . count($ids) . '), no template');
$rm = find_row($rows, 'board_id', $A);
ok($rm['item_count'] === 18 && $rm['open_threads'] === 4 && $rm['is_favorite'] === true && $rm['my_level'] === 'full' && $rm['owner_name'] === 'SMOKE Priya', 'the Roadmap: 18 items, 4 open threads, her favourite, full');
ok(ids(trows(PRIYA, 'find_boards', ['favorites' => true]), 'board_id') === [$A], 'favorites');
ok(ids(trows(PRIYA, 'find_boards', ['folder' => 'SMOKE p4 Planning']), 'board_id') === [$A] && ids(trows(PRIYA, 'find_boards', ['folder' => $w['folder']]), 'board_id') === [$A], 'folder, by name and by id');
ok(mcp_tool(REC, ptoken(PRIYA), 'find_boards', ['folder' => 'No such folder'])['code'] === 'not_found', 'a folder that is nowhere: not_found');
ok(array_column(trows(PRIYA, 'find_boards', ['owner' => 'SMOKE Marco', 'limit' => 100]), 'title') === ['SMOKE p4 Everyone', 'SMOKE p4 Sales board'] || count(trows(PRIYA, 'find_boards', ['owner' => 27, 'limit' => 100])) === 2, 'owner: Marco\'s two (by name and by id)');
ok(count(trows(PRIYA, 'find_boards', ['owner' => 'me', 'limit' => 100])) === 7, 'owner me');
$sw = array_column(trows(BEA, 'find_boards', ['shared_with_me' => true, 'limit' => 100]), 'title');
sort($sw);
ok($sw === ['SMOKE p4 Everyone', 'SMOKE p4 Roadmap'], 'shared with Bea: the Roadmap (a person) and the everyone board — not her own');
$sw = array_column(trows(PRIYA, 'find_boards', ['shared_with_me' => true, 'limit' => 100]), 'title');
sort($sw);
ok($sw === ['SMOKE p4 Everyone', 'SMOKE p4 Sales board'], 'shared with Priya: the Sales board (her department) and the everyone board');
ok(trows(PRIYA, 'find_boards', ['updated_since' => '2099-01-01']) === [] && count(trows(PRIYA, 'find_boards', ['updated_since' => gmdate('Y-m-d', time() - 86400), 'limit' => 100])) === 9, 'updated_since: a day in the future finds none, yesterday finds them');
$tp = trows(PRIYA, 'find_boards', ['templates' => true, 'limit' => 100]);
ok(count($tp) === 8 && count(array_filter($tp, fn ($x) => $x['is_template'])) === 8, 'templates=true: the six shipped, the business one and her own: ' . count($tp));
$p1 = trows(PRIYA, 'find_boards', ['limit' => 3]);
$p2 = trows(PRIYA, 'find_boards', ['limit' => 3, 'offset' => 3]);
ok(count($p1) === 3 && count($p2) === 3 && !array_intersect(ids($p1, 'board_id'), ids($p2, 'board_id')), 'paged with limit and offset: no overlap');
ok(tdata(PRIYA, 'find_boards', ['limit' => 3])['truncated'] === true, '...and truncated says there is more');

echo "2. board_get, board_access\n";
$g = tdata(PRIYA, 'board_get', ['board' => $A]);
ok(array_keys($g) === ['ok', 'board_id', 'title', 'description', 'owner', 'my_level', 'is_locked', 'is_template', 'template_scope', 'template_category', 'is_favorite', 'item_count', 'soft_budget', 'hard_cap', 'counts_by_type', 'open_threads', 'folders', 'start_view', 'last_change', 'last_activity_at', 'created_at', 'url'],
    'board_get: the facts in a fixed shape');
ok($g['description'] === 'The roadmap for the year.' && $g['owner'] === ['member_id' => 26, 'name' => 'SMOKE Priya'] && $g['item_count'] === 18 && $g['soft_budget'] === 4000 && $g['hard_cap'] === 10000, 'title, description, owner, item_count against the budget');
$cbt = $g['counts_by_type']; ksort($cbt);
ok($cbt === ['connector' => 1, 'drawing' => 1, 'frame' => 2, 'image' => 1, 'shape' => 1, 'sticky_note' => 11, 'text' => 1] && array_sum($cbt) === 18, 'counts by type: 11 stickies, 2 frames, a connector, a drawing, an image, a shape, a text — the 18, and no deleted one');
ok($g['folders'] === [['folder_id' => $w['folder'], 'name' => 'SMOKE p4 Planning', 'is_team' => false]] && $g['open_threads'] === 4, 'the folders it is kept in; four open threads');
ok($g['last_change']['who'] === 'SMOKE Facilitator' && $g['last_change']['kind'] === 'agent' && $g['last_change']['source'] === 'agent' && str_ends_with($g['url'], '/boards/' . $A), 'the last change (who, when) and the URL');
ok(tdata(PRIYA, 'board_get', ['board' => 'SMOKE p4 Roadmap'])['board_id'] === $A && tdata(PRIYA, 'board_get', ['board' => 'smoke P4 roadmap'])['board_id'] === $A, 'named by its title (case does not matter)');
ok(tdata(PRIYA, 'board_get', ['board' => 'p4 Roadm'])['board_id'] === $A, '...or by a fragment of it, when only one matches');
$amb = tool(PRIYA, 'board_get', ['board' => 'SMOKE p4 Twin']);
ok($amb['code'] === 'ambiguous' && count($amb['data']['error']['candidates']) === 2 && in_array($w['twin_a'], array_column($amb['data']['error']['candidates'], 'id'), true) && $amb['data']['error']['retry'] === false, 'two boards of one title: ambiguous, with both candidates (id + label)');
$amb = tool(PRIYA, 'board_get', ['board' => 'SMOKE p4']);
ok($amb['code'] === 'ambiguous' && count($amb['data']['error']['candidates']) === 6, 'a fragment that matches many: ambiguous, six candidates at most');
ok(tool(PRIYA, 'board_get', ['board' => 'zzzz nothing'])['code'] === 'not_found', 'a title that is nowhere: not_found');
$tg = tdata(BEA, 'board_get', ['board' => '7e000000-0000-4000-8000-000000000001']);
ok($tg['is_template'] === true && $tg['template_scope'] === 'shipped' && $tg['my_level'] === 'view', 'a shipped template reads at view for everyone in the business');
$ac = tdata(DANA, 'board_access', ['board' => $A]);
ok($ac['owner']['name'] === 'SMOKE Priya' && $ac['everyone_level'] === 'none' && count($ac['rows']) === 5 && find_row($ac['rows'], 'principal_id', 28)['level'] === 'edit' && find_row($ac['rows'], 'principal_id', 42)['principal_kind'] === 'agent', 'board_access: the owner, everyone none, five rows (Bea edit, the agents) — Dana reads it at view');
ok(tdata(MARCO, 'board_access', ['board' => $w['everyone']])['everyone_level'] === 'view', 'everyone_level view on the everyone board');

echo "3. board_overview\n";
$o = tdata(PRIYA, 'board_overview', ['board' => $A]);
ok($o['item_count'] === 18 && $o['my_level'] === 'full' && count($o['frames']) === 2 && $o['frames'][0]['title'] === 'Plan' && $o['frames'][1]['title'] === 'Later', 'the overview: two frames, top to bottom then left to right');
ok($o['frames'][0]['counts_by_type'] === ['sticky_note' => 3] && $o['frames'][0]['sample'] === ['Alpha', 'Beta', 'Pricing idea'] && $o['frames'][0]['box'] === [-400, -300, 400, 300], 'the Plan frame: counts by type, up to three sample texts, its box');
ok($o['extent'][0] <= -1260 && $o['extent'][2] >= 2400 && count($o['extent']) === 4, 'the extent of the whole canvas: ' . json_encode($o['extent']));
ok(array_sum(array_column($o['clusters'], 'count')) === 18 - 2 - 4 - 1 - 1 || array_sum(array_column($o['clusters'], 'count')) > 5, 'the unframed items fall into clusters (cells of 1,000 units): ' . count($o['clusters']));
ok($o['totals_by_type']['sticky_note'] === 11 && $o['last_change']['seq'] === 7 && in_array('SMOKE Facilitator', $o['active_last_hour'], true) && $o['soft_budget'] === 4000, 'totals, the last change, who worked in the last hour, the budget');
$big = tdata(PRIYA, 'board_overview', ['board' => $w['big']]);
ok($big['frames'] === [] && array_sum(array_column($big['clusters'], 'count')) === 450 && count($big['clusters']) > 1, 'a 450-item board with no frame: clusters hold all 450');
ok(tdata(PRIYA, 'board_overview', ['board' => $w['twin_a']])['extent'] === null && tdata(PRIYA, 'board_overview', ['board' => $w['twin_a']])['frames'] === [], 'an empty board: no extent, no frames');

echo "4. board_search\n";
$s = tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing']);
$ph = find_row($s['rows'], 'item_id', $w['pricing']);
ok($s['count'] === 2 && $ph['frame_id'] === $w['plan'] && $ph['frame_title'] === 'Plan' && $ph['cx'] == -50 && $ph['cy'] == 150 && $ph['x1'] == -150 && $ph['y2'] == 250 && $ph['excerpt'] === 'Pricing idea', 'a search for "pricing": two hits, each with its frame, centre, box and excerpt');
ok(ids(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing', 'frame' => $w['plan']])['rows'], 'item_id') === [$w['pricing']], 'narrowed to a frame');
$f = tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'plan'])['rows'];
ok($f[0]['type'] === 'frame' && $f[0]['item_id'] === $w['plan'] && $f[0]['excerpt'] === 'Plan' && ids($f, 'item_id') === [$w['plan'], $w['hiring']], 'a frame\'s title is searched, and a frame comes first ("plan" also finds "Hiring plan")');
ok(count(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing idea'])['rows']) === 1 && count(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'idea pricing'])['rows']) === 1 && tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing zzz'])['rows'] === [], 'every word must appear, in any order');
ok(count(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'PRICING', 'type' => 'sticky_note'])['rows']) === 2 && tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing', 'type' => 'shape'])['rows'] === [], 'case-insensitive; narrowed by type');
ok(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => '%'])['rows'] === [] && tdata(PRIYA, 'board_search', ['board' => $A, 'text' => '_ricing'])['rows'] === [], 'a % or _ in the words is a character, not a wildcard');
ok(count(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'pricing', 'limit' => 1])['rows']) === 1 && tool(PRIYA, 'board_search', ['board' => $A, 'text' => 'x', 'limit' => 51])['code'] === 'invalid_argument', 'limit 1–50');
$lg = tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'Long words'])['rows'][0];
ok(mb_strlen($lg['excerpt']) <= 160 && str_ends_with($lg['excerpt'], '…'), 'an excerpt is at most 160 characters, with an ellipsis');
ok(tdata(PRIYA, 'board_search', ['board' => $A, 'text' => 'Gone for good'])['rows'] === [], 'a deleted item is never found');

echo "5. board_read_region — THE SVG DIALECT\n";
$r = tool(PRIYA, 'board_read_region', ['board' => $A, 'x1' => -500, 'y1' => -400, 'x2' => 500, 'y2' => 400]);
$d = $r['data']; $x = svg_x($d['svg']);
ok($x !== null && $d['ok'] === true && $d['items'] === 5 && $d['truncated'] === false && $d['total'] === 5, 'the Plan area: valid XML, 5 items (the frame, its three stickies, the connector), not truncated');
$root = $x->query('/s:svg')->item(0);
ok($root->getAttribute('data-board') === $A && $root->getAttribute('data-box') === '-500 -400 500 400' && $root->getAttribute('data-items') === '5' && $root->getAttribute('data-truncated') === 'false' && $root->getAttribute('viewBox') === '-500 -400 1000 800', 'the root: data-board, data-box, data-items, data-truncated and a viewBox of the box');
$first = $root->firstChild;
ok($first->nodeName === 'g' && $first->getAttribute('data-type') === 'frame' && $first->getAttribute('data-title') === 'Plan' && $first->getAttribute('transform') === 'translate(-400 -300)', 'FRAMES FIRST: the frame is a group translated to its top-left');
$alpha = svg_el($x, $w['alpha']);
ok($alpha->parentNode === $first && $alpha->getAttribute('x') === '100' && $alpha->getAttribute('y') === '100' && $alpha->getAttribute('width') === '200', 'a child of a frame is FRAME-RELATIVE (Alpha at 100, 100 inside the group)');
ok($alpha->getAttribute('data-cx') === '-200' && $alpha->getAttribute('data-cy') === '-100' && $alpha->textContent === 'Alpha' && $alpha->getAttribute('data-color') === 'light_yellow' && $alpha->getAttribute('data-type') === 'sticky_note', 'while data-cx / data-cy are the ABSOLUTE centre (-200, -100) — what an action takes — with the text and the colour');
$cn = svg_el($x, $w['connector']);
ok($cn->nodeName === 'path' && $cn->getAttribute('data-start') === $w['alpha'] && $cn->getAttribute('data-end') === $w['beta'] && $cn->getAttribute('d') === 'M -200 -100 L 100 -100' && $cn->getAttribute('data-caption') === 'depends on' && $cn->getAttribute('data-shape') === 'straight', 'a connector is a line between the centres of its ends, with its caption');
$c1 = tool(PRIYA, 'board_read_region', ['board' => $A, 'around' => $w['alpha'], 'pad' => 50])['data'];
$c2 = tool(PRIYA, 'board_read_region', ['board' => $A, 'around' => $w['alpha']])['data'];
$x1 = svg_x($c1['svg']); $x2 = svg_x($c2['svg']);
ok(svg_el($x1, $w['alpha']) !== null && svg_el($x1, $w['beta']) === null && svg_el($x2, $w['beta']) !== null && svg_x($c1['svg'])->query('/s:svg')->item(0)->getAttribute('data-box') === '-350 -250 -50 50', 'around an item: its box padded (50: Alpha alone; the default 200 reaches Beta)');
$rev = tool(PRIYA, 'board_read_region', ['board' => $A, 'x1' => 500, 'y1' => 400, 'x2' => -500, 'y2' => -400])['data'];
ok($rev['svg'] === $d['svg'], 'a box given the wrong way round is the same box');
ok(tool(PRIYA, 'board_read_region', ['board' => $A, 'x1' => 0, 'y1' => 0])['code'] === 'invalid_argument' && tool(PRIYA, 'board_read_region', ['board' => $A])['code'] === 'invalid_argument', 'a partial box, or none: invalid_argument');
$full = tool(PRIYA, 'board_read_region', ['board' => $A, 'x1' => -5000, 'y1' => -5000, 'x2' => 5000, 'y2' => 5000]);
$fx = svg_x($full['data']['svg']);
ok($full['data']['items'] === 18 && $full['data']['truncated'] === false, 'the whole Roadmap in one read: 18 items (its cap is 400)');
ok(svg_el($fx, $w['gone']) === null && strpos($full['data']['svg'], 'Gone for good') === false, 'a deleted item is NEVER in it');
$dr = svg_el($fx, $w['drawing']);
ok($dr->nodeName === 'rect' && $dr->getAttribute('data-type') === 'drawing' && $dr->getAttribute('data-points') === '6' && $dr->getAttribute('data-tool') === 'pen' && !preg_match('/(?<!data-)points=/', $full['data']['svg']) && !str_contains($full['data']['svg'], '"points"'), 'a drawing is a box with a count of points and its tool — the points themselves never');
ok(svg_el($fx, $w['locked'])->getAttribute('data-locked') === 'true' && svg_el($fx, $w['tilt'])->getAttribute('data-rotation') === '15' && svg_el($fx, $w['retro'])->getAttribute('data-shape') === 'ellipse' && svg_el($fx, $w['image'])->nodeName === 'image' && svg_el($fx, $w['image'])->getAttribute('data-type') === 'image', 'locked, rotated, a shape\'s form, an image as an <image>');
$longEl = svg_el($fx, $w['long']);
ok(mb_strlen($longEl->textContent) <= 500 && str_ends_with($longEl->textContent, '…'), 'text is at most 500 characters, longer ends in an ellipsis (' . mb_strlen($longEl->textContent) . ')');
$order = [];
foreach ($fx->query('/s:svg/*') as $n) { $order[] = $n->nodeName === 'g' ? 'frame' : 'other'; }
ok(array_search('other', $order, true) > array_search('frame', array_reverse($order, true), true) || $order[0] === 'frame', 'frames come before the rest, at the top level');
ok(!str_contains($full['data']['svg'], 'storage') && !str_contains($full['data']['svg'], need('BD_STORAGE_DIR')), 'no path of the store in an image\'s element (its title is the file\'s name)');
$lim = tool(PRIYA, 'board_read_region', ['board' => $w['big'], 'x1' => -100, 'y1' => -100, 'x2' => 9000, 'y2' => 9000, 'limit' => 50])['data'];
ok($lim['items'] === 50 && $lim['total'] === 450 && $lim['truncated'] === true && str_contains($lim['note'], 'narrow') && svg_x($lim['svg'])->query('/s:svg')->item(0)->getAttribute('data-truncated') === 'true', 'past the limit: 50 of 450, truncated, data-truncated="true" and the envelope says to narrow the box');
$tl = tool(PRIYA, 'board_read_region', ['board' => $w['big'], 'x1' => -100, 'y1' => -100, 'x2' => 9000, 'y2' => 9000]);
ok($tl['code'] === 'too_large' && str_contains($tl['data']['error']['message'], 'narrow'), '400 of the 450 are more than 64 kB of SVG: too_large, in words that say to narrow the box');
$part = tool(PRIYA, 'board_read_region', ['board' => $w['big'], 'x1' => -100, 'y1' => -100, 'x2' => 1500, 'y2' => 1000]);
ok(!$part['error'] && $part['data']['items'] > 20 && $part['data']['items'] < 100 && strlen($part['data']['svg']) < 65536, 'a part of it answers (' . ($part['data']['items'] ?? 0) . ' items, ' . strlen($part['data']['svg'] ?? '') . ' bytes)');
ok(tool(PRIYA, 'board_read_region', ['board' => $A, 'limit' => 401, 'x1' => 0, 'y1' => 0, 'x2' => 1, 'y2' => 1])['code'] === 'invalid_argument', 'limit past 400: invalid_argument');
$sq = tdata(PRIYA, 'board_read_region', ['board' => $A, 'x1' => 3000, 'y1' => 3000, 'x2' => 3100, 'y2' => 3100]);
ok($sq['items'] === 0 && svg_x($sq['svg']) !== null, 'an empty area is an empty, valid SVG');
$vs = val_as(PRIYA, "SELECT bd_region_svg(CAST('$A' AS uuid), -500, -400, 500, 400, 400, NULL)->>'svg'");
ok($vs === $d['svg'], 'the answer is bd_region_svg() byte for byte — the tool renders nothing');

echo "6. frame_read\n";
$fr = tool(PRIYA, 'frame_read', ['board' => $A, 'frame' => $w['plan']])['data'];
$fx = svg_x($fr['svg']);
$root = $fx->query('/s:svg')->item(0);
ok($fr['items'] === 4 && $root->getAttribute('data-frame') === $w['plan'] && $root->getAttribute('viewBox') === '0 0 800 600' && $fx->query('//s:g')->length === 0, 'a frame alone: its children and the connector between them, no group — the frame\'s top-left is 0, 0');
$a = svg_el($fx, $w['alpha']);
ok($a->getAttribute('x') === '100' && $a->getAttribute('data-cx') === '-200' && svg_el($fx, $w['connector'])->getAttribute('d') === 'M 200 200 L 500 200', 'children at their frame-relative places; the connector in the same coordinates; data-cx still the absolute centre');
ok(tool(PRIYA, 'frame_read', ['board' => $A, 'frame' => $w['alpha']])['code'] === 'not_found' && tool(PRIYA, 'frame_read', ['board' => $A, 'frame' => $missing = '66666666-6666-4666-8666-666666666666'])['code'] === 'not_found' && tool(PRIYA, 'frame_read', ['board' => $A, 'frame' => 'not-an-id'])['code'] === 'invalid_argument', 'an item that is not a frame, an id that is nowhere: not_found; not an id: invalid_argument');
ok(tdata(PRIYA, 'frame_read', ['board' => $A, 'frame' => $w['later']])['items'] === 1, 'the Later frame holds Hiring plan');

echo "7. item_get, item_connections\n";
$i = tdata(PRIYA, 'item_get', ['item' => $w['alpha']]);
ok($i['type'] === 'sticky_note' && $i['frame_id'] === $w['plan'] && $i['frame_title'] === 'Plan' && $i['cx'] == -200 && $i['cy'] == -100 && $i['width'] == 200 && $i['text'] === 'Alpha' && $i['runs'][0]['plain_text'] === 'Alpha' && $i['style']['fillColor'] === 'light_yellow' && $i['locked'] === false && $i['version'] >= 1, 'a sticky: type, frame, centre, size, its text plain and as runs, style, lock, version');
ok($i['created_by'] === 'SMOKE Priya' && $i['connectors'] === [['connector_id' => $w['connector'], 'from_id' => $w['alpha'], 'to_id' => $w['beta'], 'shape' => 'straight', 'caption' => 'depends on']] && str_contains($i['url'], '?item=' . $w['alpha']), 'who made it, its connectors, a link that opens the canvas at it');
$l = tdata(PRIYA, 'item_get', ['item' => $w['long']]);
ok(mb_strlen($l['text']) >= 700 && !str_contains($l['text'], '…'), 'item_get gives a long text whole (the dialect cuts at 500): ' . mb_strlen($l['text']) . ' characters');
$dd = tdata(PRIYA, 'item_get', ['item' => $w['drawing']]);
ok($dd['data'] === ['tool' => 'pen', 'point_count' => 6] && !has_key($dd, 'points'), 'a drawing\'s points are counted, not listed');
ok(tdata(PRIYA, 'item_get', ['item' => $w['locked']])['locked'] === true && tdata(PRIYA, 'item_get', ['item' => $w['tilt']])['rotation'] == 15, 'locked and rotated');
$c = tdata(PRIYA, 'item_get', ['item' => $w['connector']]);
ok($c['type'] === 'connector' && $c['from_id'] === $w['alpha'] && $c['to_id'] === $w['beta'] && $c['data']['captions'][0]['content'] === 'depends on', 'a connector: its ends and its caption');
ok(tool(PRIYA, 'item_get', ['item' => $w['gone']])['code'] === 'not_found' && tool(PRIYA, 'item_get', ['item' => 'xyz'])['code'] === 'invalid_argument', 'a deleted item is not_found; not an id is invalid_argument');
ok(tdata(PRIYA, 'item_get', ['item' => $w['plan']])['data']['title'] === 'Plan' && tdata(PRIYA, 'item_get', ['item' => $w['image']])['type'] === 'image', 'a frame (its title) and an image');
$cn = tdata(PRIYA, 'item_connections', ['item' => $w['alpha']]);
ok($cn['incoming'] === [] && count($cn['outgoing']) === 1 && $cn['outgoing'][0] === ['connector_id' => $w['connector'], 'to_id' => $w['beta'], 'to_type' => 'sticky_note', 'to_excerpt' => 'Beta', 'caption' => 'depends on'], 'Alpha has one outgoing connector, to Beta');
$cn = tdata(PRIYA, 'item_connections', ['item' => $w['beta']]);
ok(count($cn['incoming']) === 1 && $cn['incoming'][0]['from_id'] === $w['alpha'] && $cn['incoming'][0]['from_excerpt'] === 'Alpha' && $cn['outgoing'] === [], 'Beta has one incoming');
$cn = tdata(PRIYA, 'item_connections', ['item' => $w['gamma']]);
ok($cn['incoming'] === [] && $cn['outgoing'] === [], 'Gamma has none');

echo "8. find_attachments\n";
$fa = tdata(PRIYA, 'find_attachments', ['board' => $A]);
ok(count($fa['rows']) === 1 && $fa['rows'][0]['item_id'] === $w['image'] && $fa['rows'][0]['mime_type'] === 'image/png' && $fa['rows'][0]['width'] === 1600 && $fa['rows'][0]['height'] === 900 && $fa['rows'][0]['uploaded_by'] === 'SMOKE Priya' && !has_key($fa, 'storage_path'), 'the image: its item, type, size, uploader — never a path');
ok(count(trows(PRIYA, 'find_attachments', ['board' => $A, 'kind' => 'image'])) === 1 && trows(PRIYA, 'find_attachments', ['board' => $A, 'kind' => 'document']) === [], 'kind image / document');

echo "9. board_changes, versions\n";
$ops = (int) one("SELECT count(*) FROM board_ops WHERE board_id = CAST(:b AS uuid)", ['b' => $A]);
$bc = tdata(PRIYA, 'board_changes', ['board' => $A, 'limit' => 100]);
ok(count($bc['rows']) === $ops && $bc['rows'][0]['seq'] > $bc['rows'][1]['seq'] && array_keys($bc['rows'][0]) === ['seq', 'at', 'member_name', 'member_kind', 'source', 'counts', 'types', 'box', 'undoes', 'agent_run_id'], "one row per committed batch ($ops), newest first");
$ag = trows(PRIYA, 'board_changes', ['board' => $A, 'source' => 'agent']);
ok(count($ag) === 1 && $ag[0]['member_name'] === 'SMOKE Facilitator' && $ag[0]['member_kind'] === 'agent' && $ag[0]['counts'] === ['create' => 1] && $ag[0]['types'] === ['sticky_note' => 1], 'source=agent: the Facilitator\'s one batch, its counts and types');
ok(count(trows(PRIYA, 'board_changes', ['board' => $A, 'source' => 'command_bar'])) === 1 && count(trows(PRIYA, 'board_changes', ['board' => $A, 'member' => 'SMOKE Facilitator'])) === 1 && count(trows(PRIYA, 'board_changes', ['board' => $A, 'member' => 'me', 'limit' => 100])) === $ops - 1, 'by source and by person (me = Priya: all but the agent\'s)');
ok(trows(PRIYA, 'board_changes', ['board' => $A, 'since' => '2099-01-01']) === [] && count(trows(PRIYA, 'board_changes', ['board' => $A, 'until' => '2099-01-01', 'limit' => 100])) === $ops, 'since / until');
ok(!array_key_exists('since_my_last_visit', tdata(PRIYA, 'board_changes', ['board' => $A, 'since' => '2000-01-01'])), 'with a since: no "since my last visit"');
q("DELETE FROM board_recents WHERE member_id = 30 AND board_id = CAST(:b AS uuid)", ['b' => $A]);
$sv = tdata(DANA, 'board_changes', ['board' => $A])['since_my_last_visit'];
ok($sv['last_visit'] === null && $sv['changes_by_others'] === null, 'since my last visit: Dana has not opened the board before — and is told so');
q("INSERT INTO board_recents (board_id, member_id, visited_at) VALUES (CAST(:b AS uuid), 30, now() - interval '1 hour') ON CONFLICT (board_id, member_id) DO UPDATE SET visited_at = excluded.visited_at", ['b' => $A]);
$sv = tdata(DANA, 'board_changes', ['board' => $A])['since_my_last_visit'];
ok($sv['changes_by_others'] === $ops && in_array('SMOKE Priya', $sv['by'], true) && in_array('SMOKE Facilitator', $sv['by'], true), "since Dana's last visit an hour ago: all $ops changes were others', by Priya and the Facilitator");
q("UPDATE board_recents SET visited_at = now() + interval '1 hour' WHERE member_id = 30 AND board_id = CAST(:b AS uuid)", ['b' => $A]);
ok(tdata(DANA, 'board_changes', ['board' => $A])['since_my_last_visit']['changes_by_others'] === 0, '...and none since a visit in the future');
$vr = tdata(PRIYA, 'board_versions', ['board' => $A]);
ok(count($vr['rows']) === 1 && $vr['rows'][0]['version_no'] === 1 && $vr['rows'][0]['label'] === 'Baseline' && $vr['rows'][0]['reason'] === 'manual' && $vr['rows'][0]['saved_by'] === 'SMOKE Priya' && $vr['rows'][0]['item_count'] === 16, 'board_versions: the named version, who saved it and the items it held (16)');
$vo = tdata(PRIYA, 'version_outline', ['board' => $A, 'version' => 1]);
ok(str_contains($vo['outline'], '## Plan') && str_contains($vo['outline'], 'Alpha') && !str_contains($vo['outline'], 'Facilitator idea') && !str_contains($vo['outline'], 'From the command bar') && $vo['version'] === 1, 'version_outline: the board as the Baseline had it — without what came after');
$sqlv = val_as(PRIYA, "SELECT bd_version_outline(CAST('$A' AS uuid), 1)");
ok($vo['outline'] === $sqlv, '...exactly bd_version_outline()');
ok(tool(PRIYA, 'version_outline', ['board' => $A, 'version' => 99])['code'] === 'not_found' && tool(PRIYA, 'version_outline', ['board' => $A, 'version' => 0])['code'] === 'invalid_argument', 'a version that is not there: not_found');

echo "10. comment_thread\n";
$th = tdata(PRIYA, 'comment_thread', ['comment' => $w['thread_bea']]);
ok($th['root']['comment_id'] === $w['thread_bea'] && $th['root']['author_name'] === 'SMOKE Priya' && $th['root']['anchor']['item_id'] === $w['pricing'] && str_contains($th['root']['text'], 'pricing idea final') && count($th['replies']) === 1 && $th['replies'][0]['author_name'] === 'SMOKE Bea' && $th['resolved_at'] === null, 'the root (author, text, the item it is pinned to), the reply, not resolved');
$rid = $th['replies'][0]['comment_id'];
ok(tdata(PRIYA, 'comment_thread', ['comment' => $rid])['root']['comment_id'] === $w['thread_bea'], 'any comment of the thread opens the whole thread (the reply\'s id gives the root)');
$pt = tdata(PRIYA, 'comment_thread', ['comment' => $w['thread_point']]);
ok($pt['root']['anchor'] == ['item_id' => null, 'x' => 400, 'y' => -300] && $pt['replies'] === [], 'a point on the canvas: its x, y');
ok(tdata(PRIYA, 'comment_thread', ['comment' => $w['thread_resolved']])['resolved_at'] !== null, 'a resolved thread says when');
ok(tdata(PRIYA, 'comment_thread', ['comment' => $w['thread_agent']])['replies'][0]['author_kind'] === 'agent', 'an agent\'s reply is marked as an agent\'s');
$fc = tdata(PRIYA, 'find_comments', ['board' => $A, 'limit' => 100])['rows'];
ok(count($fc) === 5 && array_keys($fc[0]) === ['comment_id', 'board_id', 'board_title', 'item_id', 'author_name', 'author_kind', 'replies', 'resolved_at', 'created_at', 'excerpt'], 'find_comments: one row per thread (5), newest first');

echo "11. board_outline (as a person)\n";
$ol = tdata(PRIYA, 'board_outline', ['board' => $A]);
$sqlo = val_as(PRIYA, "SELECT bd_board_outline(CAST('$A' AS uuid))");
ok($ol['outline'] === $sqlo && $ol['title'] === 'SMOKE p4 Roadmap' && $ol['board_id'] === $A && !isset($ol['schema']), 'the outline is bd_board_outline() verbatim: a person\'s answer has no share envelope');
$t = $ol['outline'];
ok(str_starts_with($t, '# SMOKE p4 Roadmap') && str_contains($t, "## Plan\n- [sticky, light_yellow] Alpha") && str_contains($t, '## On the canvas') && str_contains($t, '- Alpha → Beta (depends on)') && str_contains($t, '## Open comments') && !str_contains($t, 'Gone for good') && !str_contains($t, 'A remark that is settled'),
    'frames as headings with their items as lines, the rest under "On the canvas", connectors as A → B (caption), open comments only, nothing deleted');
ok(str_contains($t, 'A point on the canvas') && !str_contains($t, 'A point on the canvas — on ""'), 'a comment on a point of the canvas names no item (the empty "on" is gone, db/019)');
$huge = tool(PRIYA, 'board_outline', ['board' => $w['huge']]);
ok($huge['code'] === 'too_large' && str_contains($huge['data']['error']['message'], '256 kB') && mb_strlen(val_as(PRIYA, "SELECT bd_board_outline(CAST('{$w['huge']}' AS uuid))")) > 262144, 'a board whose outline is more than 256 kB: too_large (the database\'s own outline is ' . round(strlen((string) val_as(PRIYA, "SELECT bd_board_outline(CAST('{$w['huge']}' AS uuid))")) / 1024) . ' kB)');
$bo = tdata(PRIYA, 'board_outline', ['board' => $w['big']]);
ok(strlen($bo['outline']) > 4000 && strlen($bo['outline']) < 262144, 'the 450-item board\'s outline answers');
finish();
