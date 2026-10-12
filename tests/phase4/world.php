<?php
/**
 * The Phase 4 world, made once per run through the application's own handlers and the schema's own functions (so the walls are the real ones), and written as JSON to $BD_DEV_STATE/world.json. Everything is named "SMOKE p4 …".
 *   roadmap  Priya's "SMOKE p4 Roadmap": shared with Bea (edit), Dana (view), the Facilitator (edit, an agent), the Expert (view, an agent) and Ann (an external Viewer: comment); a frame "Plan" holding Alpha, Beta and "Pricing idea",
 *            a frame "Later" holding "Hiring plan", a free Gamma, "Pricing tiers", a shape "Retro actions", a text "A heading", a connector Alpha -> Beta ("depends on"), a pen drawing, a 700-character sticky, a locked sticky, a rotated
 *            sticky, an uploaded image, one deleted sticky ("Gone"); a version "Baseline"; changes by the web, the command bar and an agent; four comment threads (one mentioning Bea and answered by her, one resolved, one unanswered,
 *            one mentioning the Facilitator = a pending dispatch); Priya's favourite, in her folder "SMOKE p4 Planning"; a personal template from it
 *   secret   Priya's alone: "SMOKE p4 Secret" (a sticky "Secret plan") — Bea, Lee, the agents and Ann are strangers to it
 *   sales    Marco's "SMOKE p4 Sales board", shared with the Sales department (edit); everyone  Marco's, everyone in the business at view (an external excepted)
 *   big      "SMOKE p4 Big": 450 stickies (a region of them is more than 400 items; a default read is more than 64 kB)
 *   huge     "SMOKE p4 Huge outline": 3,200 stickies of long text (its outline is more than 256 kB)
 *   trashed  Priya's deleted board; left: "SMOKE Left behind" (its owner has left); the business template Marco published; a team folder of Marco's
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/phase3/slice4/lib.php';
require_once dirname(__DIR__) . '/phase3/slice5/lib.php';
require_once dirname(__DIR__) . '/phase3/slice6/lib.php';
require_once dirname(__DIR__) . '/phase3/slice7/lib.php';
require_once dirname(__DIR__) . '/phase3/slice8/lib.php';

[$jp] = sign_on(26); [$jm] = sign_on(27); [$jb] = sign_on(28);
$w = [];
function must(array $r, string $what): array { if ($r['code'] !== 200) { fwrite(STDERR, "world: $what failed: {$r['code']} {$r['body']}\n"); exit(1); } return body_json($r); }

echo "1. the roadmap\n";
[$A, $plan, $alpha, $beta, $gamma] = board_with_frame($jp, 'SMOKE p4 Roadmap');
$w += ['roadmap' => $A, 'plan' => $plan, 'alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma];
pg("UPDATE boards SET description = 'The roadmap for the year.' WHERE id = '$A'");
foreach ([[28, 'edit'], [30, 'view'], [42, 'edit'], [40, 'view'], [29, 'comment']] as [$m, $lv]) { must(act($jp, '/boards/share-set.php', ['board' => $A, 'member' => $m, 'level' => $lv]), "share $m"); }       // through the handler: each share is a row of the trail
$pricing = child_op($plan, 'Pricing idea', 350, 450, 'a4');
$later = frame_op('Later', 2000, 0, 800, 600);
$hiring = child_op($later['item']['id'], 'Hiring plan', 200, 200, 'a5');
$tiers = item_op(['text' => 'Pricing tiers', 'x' => 0, 'y' => 1000, 'z' => 'a6']);
$retro = item_op(['type' => 'shape', 'text' => 'Retro actions', 'x' => -1200, 'y' => 0, 'width' => 200, 'height' => 120, 'z' => 'a7', 'style' => ['fillColor' => '#ffffff'], 'data' => ['shape' => 'ellipse']]);
$head = item_op(['type' => 'text', 'text' => 'A heading', 'x' => 0, 'y' => -700, 'width' => 400, 'height' => 80, 'z' => 'a8', 'style' => []]);
$long = item_op(['text' => str_repeat('Long words go on and on. ', 30), 'x' => 0, 'y' => 1600, 'z' => 'a9']);
$locked = item_op(['text' => 'Locked note', 'x' => -600, 'y' => 1000, 'z' => 'b1', 'locked' => true]);
$tilt = item_op(['text' => 'Tilted note', 'x' => 600, 'y' => 1000, 'z' => 'b2', 'rotation' => 15]);
$conn = item_op(['type' => 'connector', 'start_item_id' => $alpha, 'end_item_id' => $beta, 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'z' => 'b3', 'style' => [], 'data' => ['shape' => 'straight', 'captions' => [['content' => 'depends on']]]]);
$draw = item_op(['type' => 'drawing', 'x' => -1200, 'y' => 700, 'width' => 120, 'height' => 60, 'z' => 'b4', 'style' => ['color' => '#222222'], 'data' => ['tool' => 'pen', 'points' => [0, 0, 20, 10, 40, 30, 60, 20, 80, 50, 120, 60]]]);
$gone = item_op(['text' => 'Gone for good', 'x' => 900, 'y' => 1700, 'z' => 'b5']);
$r = ops_post($jp, $A, [$pricing, $later, $hiring, $tiers, $retro, $head, $long, $locked, $tilt, $conn, $draw, $gone]);
if ($r['code'] !== 200) { fwrite(STDERR, "world: ops failed {$r['code']} {$r['body']}\n"); exit(1); }
ops_post($jp, $A, [['op' => 'delete', 'ids' => [$gone['item']['id']]]]);
$w += ['pricing' => $pricing['item']['id'], 'later' => $later['item']['id'], 'hiring' => $hiring['item']['id'], 'tiers' => $tiers['item']['id'], 'retro' => $retro['item']['id'], 'heading' => $head['item']['id'],
       'long' => $long['item']['id'], 'locked' => $locked['item']['id'], 'tilt' => $tilt['item']['id'], 'connector' => $conn['item']['id'], 'drawing' => $draw['item']['id'], 'gone' => $gone['item']['id']];
$u = body_json(upload_as($jp, $A, alpha_png()));
$w['image'] = place_upload($jp, $A, $u, 1200, 700)['code'] === 200 ? $u['item_id'] : null;
$w['attachment'] = (int) ($u['attachment_id'] ?? 0);
$w['baseline'] = (int) (body_json(save_version($jp, $A, 'Baseline'))['record_id'] ?? 0);

echo "2. changes by the web, the command bar and an agent\n";
ops_post($jp, $A, [['op' => 'update', 'id' => $gamma, 'fields' => ['x' => 1300]]]);
commit_as_member($A, 26, 'command_bar', [item_op(['text' => 'From the command bar', 'x' => -300, 'y' => 1300, 'z' => 'b6'])]);
$w['agent_item'] = (function () use ($A) { $op = item_op(['text' => 'Facilitator idea', 'x' => 300, 'y' => 1300, 'z' => 'b7']); commit_as_member($A, 42, 'agent', [$op]); return $op['item']['id']; })();
$w['versions'] = (int) one("SELECT count(*) FROM board_versions WHERE board_id = CAST(:b AS uuid)", ['b' => $A]);

echo "3. comments, a dispatch, the favourite, a folder, a template\n";
$t1 = must(thread_on($jp, $A, 'Is the pricing idea final? @SMOKE Bea', $w['pricing']), 'thread 1');
$w['thread_bea'] = $t1['record_id'];
must(comment_as($jb, 'reply.php', ['comment' => $w['thread_bea'], 'text' => 'Yes, it is final.']), 'reply');
$t2 = must(thread_on($jp, $A, 'A remark that is settled', $alpha), 'thread 2');
$w['thread_resolved'] = $t2['record_id'];
must(comment_as($jp, 'resolve.php', ['comment' => $w['thread_resolved']]), 'resolve');
$t3 = must(thread_on($jp, $A, 'An unanswered question about Gamma', $gamma), 'thread 3');
$w['thread_open'] = $t3['record_id'];
$t4 = must(thread_on($jp, $A, '@SMOKE Facilitator cluster these stickies please', $tiers['item']['id']), 'thread 4');
$w['thread_agent'] = $t4['record_id'];
run_facts(7101, 42);
$ar = agent_act(42, 7101, '/comments/reply.php', ['comment' => $w['thread_agent'], 'text' => 'On it: clustering now.']);       // an agent acting through a handler: a row in the trail with its run
if ($ar['code'] !== 200) { fwrite(STDERR, "world: the agent's reply failed {$ar['code']} {$ar['body']}\n"); exit(1); }
$t5 = must(comment_as($jb, 'create.php', ['board' => $A, 'text' => 'A point on the canvas', 'x' => 400, 'y' => -300]), 'thread 5');
$w['thread_point'] = $t5['record_id'];
$w['dispatch'] = (int) one("SELECT id FROM agent_dispatches WHERE record_uuid = CAST(:c AS uuid)", ['c' => $w['thread_agent']]);
must(act($jp, '/boards/favorite.php', ['board' => $A]), 'favorite');
$fid = (int) must(act($jp, '/folders/save.php', ['name' => 'SMOKE p4 Planning']), 'folder')['record_id'];
must(act($jp, '/folders/add.php', ['folder' => $fid, 'board' => $A]), 'folder add');
$w['folder'] = $fid;
$w['team_folder'] = (int) must(act($jm, '/folders/save.php', ['name' => 'SMOKE p4 Team folder', 'team' => 'yes']), 'team folder')['record_id'];
$w['template'] = must(save_template($jp, $A, ['title' => 'SMOKE p4 Roadmap template', 'category' => 'planning']), 'template')['record_id'];

echo "4. the other boards\n";
$secret = make_board($jp, 'SMOKE p4 Secret');
ops_post($jp, $secret, [item_op(['text' => 'Secret plan', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
thread_on($jp, $secret, 'A secret remark', null);
$w['secret'] = $secret;
$sales = make_board($jm, 'SMOKE p4 Sales board');
ops_post($jm, $sales, [item_op(['text' => 'Sales target', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
must(act($jm, '/boards/share-set.php', ['board' => $sales, 'department' => SALES, 'level' => 'edit']), 'sales share');
$w['sales'] = $sales;
$every = make_board($jm, 'SMOKE p4 Everyone');
ops_post($jm, $every, [item_op(['text' => 'For everyone', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
must(act($jm, '/boards/access-set.php', ['board' => $every, 'level' => 'view']), 'everyone');
$w['everyone'] = $every;
$w['business_template'] = must(publish_template($jm, $every, ['title' => 'SMOKE p4 Business template', 'category' => 'strategy']), 'business template')['record_id'];
$trashed = make_board($jp, 'SMOKE p4 Trashed');
ops_post($jp, $trashed, [item_op(['text' => 'Trashed sticky', 'x' => 0, 'y' => 0, 'z' => 'a1'])]);
must(act($jp, '/boards/delete.php', ['board' => $trashed]), 'trash');
$w['trashed'] = $trashed;
$w['left'] = leaver_board();
$w['twin_a'] = make_board($jp, 'SMOKE p4 Twin'); $w['twin_b'] = make_board($jp, 'SMOKE p4 Twin');         // two boards of one title: a name that is ambiguous

$replay = make_board($jp, 'SMOKE p4 Replay', ['description' => 'Keep this description']);
share_as(26, $replay, 'agent', 42, 'edit');
$rs = [];
foreach ([1, 2, 3, 4] as $n) { $op = item_op(['text' => "Replay $n", 'x' => ($n - 1) * 300, 'y' => 0, 'z' => 'a' . $n]); $rs[] = $op; }
ops_post($jp, $replay, $rs);
$w['replay'] = $replay; $w['replay_items'] = array_map(fn ($o) => $o['item']['id'], $rs);       // the actions the registry proof replays against the real handlers, as the Facilitator

echo "5. the big boards\n";
$big = make_board($jp, 'SMOKE p4 Big');
pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by)
    SELECT gen_random_uuid(), '$big', 'sticky_note', (n % 30) * 220, (n / 30) * 220, 200, 200, 'a' || to_char(n, 'FM9999'), '{\"fillColor\": \"light_blue\"}',
           jsonb_build_object('content', bd_rich_text('Big ' || n)), 26, 26 FROM generate_series(1, 450) n");
$w['big'] = $big;
$huge = make_board($jp, 'SMOKE p4 Huge outline');
pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, updated_by)
    SELECT gen_random_uuid(), '$huge', 'sticky_note', (n % 60) * 220, (n / 60) * 220, 200, 200, 'a' || to_char(n, 'FM9999'), '{\"fillColor\": \"light_green\"}',
           jsonb_build_object('content', bd_rich_text('Huge ' || n || ' ' || repeat('words that fill an outline line ', 4))), 26, 26 FROM generate_series(1, 3200) n");
$w['huge'] = $huge;

// the agents' run facts are set per proof; the people's directory is the fixture's
file_put_contents(need('BD_DEV_STATE') . '/world.json', json_encode($w));
echo "  ok   the world: " . count($w) . " ids; boards " . one("SELECT count(*) FROM boards WHERE title LIKE 'SMOKE p4%'") . ", items " . one('SELECT count(*) FROM board_items') . ", comments " . one('SELECT count(*) FROM comments') . "\n";
