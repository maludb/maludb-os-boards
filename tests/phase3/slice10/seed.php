<?php
/**
 * Slice 10's browser seed (`php tests/phase3/slice10/seed.php` → JSON): Priya's (26) boards for the home (`a`..`j`, ten, three starred, a mention waiting), a team folder of Marco's, boards past the budget (4,100 and 9,500
 * items), one idle 200 days, the board of a member who has left, two trashed boards (one for the browser's Purge now, with a file in the store, `trashed_browser`; `trashed_phone`).
 * Bea (28) is given nothing: she is the first-run person. Dana (30) and Lee (31) are left alone.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);
[$jm] = sign_on(27);
// The browser's world starts clean: the PHP proofs before it have left boards, notices and shares behind (a scratch database; the shipped templates stay).
pg("DELETE FROM notifications; DELETE FROM agent_dispatches; DELETE FROM boards WHERE NOT (is_template AND template_scope = 'shipped'); DELETE FROM board_folders;");
$out = [];
foreach (range('a', 'j') as $i => $k) {
    $b = make_board($jp, 'SMOKE Home browser ' . strtoupper($k));
    pg("INSERT INTO board_recents (member_id, board_id, visited_at) VALUES (26, '$b', now() - interval '" . (30 - $i) . " minutes') ON CONFLICT (member_id, board_id) DO UPDATE SET visited_at = EXCLUDED.visited_at");
    $out['board_' . $k] = $b;
}
foreach (['a', 'd', 'g'] as $k) { act($jp, '/boards/favorite.php', ['board' => $out['board_' . $k]]); }
$fid = (int) body_json(act($jm, '/folders/save.php', ['name' => 'SMOKE Browser team', 'team' => '1']))['record_id'];
act($jm, '/folders/add.php', ['folder' => $fid, 'board' => make_board($jm, 'SMOKE Marco browser board')]);
$out['team_folder'] = $fid;
pg("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'mention', 'SMOKE browser mention', 'x')");
$amber = make_board($jp, 'SMOKE Browser amber');
$red = make_board($jp, 'SMOKE Browser red');
$idle = make_board($jp, 'SMOKE Browser idle');
set_items($amber, 4100);
set_items($red, 9500);
set_idle($idle, 200);
$out['amber'] = $amber;
$out['red'] = $red;
$out['idle'] = $idle;
$out['left'] = leaver_board();
[$t3, $files] = trashed_board($jp, 'SMOKE Trashed in the browser', 1);
[$t4] = trashed_board($jp, 'SMOKE Trashed on the phone', 1);
$out['trashed_browser'] = $t3;
$out['trashed_browser_file'] = $files[0];
$out['trashed_phone'] = $t4;
echo json_encode($out);
