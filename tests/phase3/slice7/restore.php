<?php
/**
 * Slice 7 — restore (docs/build-specs/history-versions.md "Proof" 3): an owner restores a version — a moved sticky back, an added one gone, a deleted one back — in ONE board_ops row with source
 * restore, with a before_restore and a restore version; an editor's restore is refused; restoring the current state answers "Nothing to restore"; a retry of one request restores once; the
 * batch reaches an open canvas through the catch-up like any other; the approval for an agent. Run through tests/phase3/slice7/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);   // Priya, the owner
[$jb] = sign_on(28);   // Bea, edit
[$jd] = sign_on(30);   // Dana, comment
[$jl] = sign_on(31);   // Lee, a viewer
[$ja] = sign_on(29);   // Ann, nothing
[$jm] = sign_on(27);   // Marco, a Boards admin

[$b, $frame, $alpha, $beta] = board_with_three($jp, 'SMOKE Restore');
share_as(26, $b, 'member', 28, 'edit');
share_as(26, $b, 'member', 30, 'comment');
share_as(26, $b, 'member', 31, 'view');
$v = body_json(save_version($jp, $b, 'Baseline'));
$base = (int) $v['record_id'];
$titleBefore = (string) one('SELECT title FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $b]);
// what happens after the baseline: Alpha moves, Beta is deleted, Gamma is added, the frame is renamed
$gamma = item_op(['text' => 'Gamma', 'x' => -300, 'y' => 200, 'z' => 'a3']);
$after = ops_post($jp, $b, [['op' => 'update', 'id' => $alpha, 'fields' => ['x' => 900]], ['op' => 'delete', 'ids' => [$beta]], $gamma, ['op' => 'update', 'id' => $frame, 'fields' => ['data' => ['title' => 'Renamed']]]]);
$g = $gamma['item']['id'];
$seqAfter = (int) (body_json($after)['seq'] ?? 0);
ok($after['code'] === 200 && item_state($alpha) === [900.0, false] && item_state($beta)[1] === true && item_state($g) === [-300.0, false], 'the board has moved on: Alpha at 900, Beta deleted, Gamma added');
$opsBefore = count(ops_of($b));

echo "3. An owner restores\n";
$since = last_activity_id();
$r = restore_version($jb, $b, $base);
ok($r['code'] === 403 && str_contains(msg($r), 'Only'), 'Bea (edit) restoring is refused in the database\'s words (403): "' . msg($r) . '"');
ok(restore_version($jd, $b, $base)['code'] === 403 && restore_version($jl, $b, $base)['code'] === 403, 'Dana and Lee: 403');
ok(restore_version($ja, $b, $base)['code'] === 403, 'Ann, an external with no editing right: 403');
ok(count(ops_of($b)) === $opsBefore && item_state($alpha)[0] === 900.0, 'none of those changed the board');
$r = restore_version($jp, $b, 99);
ok($r['code'] === 422 && str_contains(msg($r), 'not here'), 'a version this board does not have: 422 "' . msg($r) . '"');
$r = restore_version($jp, $b, '');
ok($r['code'] === 422 && body_json($r)['error']['fields']['version'] === 'Say which version to restore.', 'no version: 422 in words');
$r = restore_version($jp, $b, $base, ['Accept: application/json', 'X-Request-Id: proof-restore-1']);
$j = body_json($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['noop'] === false && $j['version'] === $base && $j['record_id'] === $j['seq'] && $j['changes'] >= 4 && $j['refresh'] === 'versionChanged', 'Priya restores: ok, record_id the batch\'s seq, the number of changes');
ok($j['did'] === "Restored version $base — {$j['changes']} changes in one batch; the board as it was is version {$j['saved_as']}", 'the reply: "' . $j['did'] . '"');
ok(item_state($alpha) === [0.0, false] && item_state($beta) === [300.0, false] && item_state($g)[1] === true, 'the board matches the version: Alpha back at 0, Beta back, Gamma gone');
ok(str_contains(json_encode(item_row($frame)['data']), 'Plan') && !str_contains((string) item_row($frame)['data'], 'Renamed'), 'the frame\'s title is as it was');
$restoreOps = ops_of($b, 'restore');
ok(count($restoreOps) === 1 && (int) $restoreOps[0]['member_id'] === 26 && $restoreOps[0]['seq'] === $j['seq'] && count(ops_of($b)) === $opsBefore + 1, 'ONE board_ops row with source restore, by Priya — no second write path');
$vs = versions_of($b);
$reasons = array_column($vs, 'reason', 'version_no');
ok($reasons[$base] === 'manual' && $reasons[$j['saved_as']] === 'before_restore' && in_array('restore', $reasons, true), 'versions: the baseline, a before_restore (the board as it was) and a restore: ' . implode(', ', $reasons));
$restoreVersion = array_search('restore', $reasons, true);
ok(q('SELECT label FROM board_versions WHERE board_id = CAST(:b AS uuid) AND version_no = :n', ['b' => $b, 'n' => $restoreVersion])[0]['label'] === "Restored version $base", 'the restore version is labelled "Restored version N"');
$before = q('SELECT jsonb_array_length(snapshot->\'items\') AS n, snapshot::text AS s FROM board_versions WHERE board_id = CAST(:b AS uuid) AND version_no = :n', ['b' => $b, 'n' => $j['saved_as']])[0];
ok((int) $before['n'] === 3 && str_contains($before['s'], 'Gamma') && str_contains($before['s'], 'Renamed') && !str_contains($before['s'], 'Beta'), 'the before_restore version holds the board as it was: nothing is lost');
$rl = activity('version.restore', $since);
ok(count($rl) === 1 && $rl[0]['board_id'] === $b && json_decode($rl[0]['after'], true) == ['version_no' => $base, 'seq' => $j['seq'], 'noop' => false], 'version.restore is logged with the board, the version, the seq');
$h = history_html($jp, $b);
ok(preg_match('/id="change-row-' . $j['seq'] . '".*?bg-soft-warning text-warning">restore</s', $h['body']) === 1, 'the history shows the restore row with its chip');
ok(body_json(history_json($jp, $b))['data']['rows'][0]['source'] === 'restore', 'JSON: the newest change is source restore');

echo "   again, and a retry\n";
$vCount = count($vs);
$r = restore_version($jp, $b, $base, ['Accept: application/json', 'X-Request-Id: proof-restore-1']);
$j2 = body_json($r);
ok($r['code'] === 200 && $j2['noop'] === true && $j2['record_id'] === null && $j2['did'] === "Nothing to restore — the board already matches version $base", 'the same request again, or the current state: "' . $j2['did'] . '"');
ok(count(ops_of($b, 'restore')) === 1, 'still one restore batch (the retry restored nothing twice)');
ok(count(versions_of($b)) === $vCount + 1, 'the no-op still saved a before_restore version of its own');
$rl = activity('version.restore', $since);
ok(count($rl) === 2 && json_decode($rl[1]['after'], true)['noop'] === true, 'and is logged with noop true');

echo "   the canvases receive it\n";
$catch = body_json(ops_since($jb, $b, $seqAfter));
$batches = $catch['batches'] ?? [];
ok(count($batches) === 1 && $batches[0]['source'] === 'restore' && $batches[0]['seq'] === (int) $restoreOps[0]['seq'] && count($batches[0]['ops']) >= 4, 'Bea\'s catch-up after the batch she last saw returns the restore as one batch (the island applies it like any other)');
$j3 = body_json(state_of($jb, $b));
$items = array_column($j3['items'] ?? $j3['data']['items'] ?? [], null, 'id');
ok(isset($items[$alpha]) && (float) $items[$alpha]['x'] === 0.0 && !isset($items[$g]) && isset($items[$beta]), 'and the board\'s state reads as the version');

echo "   a Boards admin; the approval\n";
$r = restore_version($jm, $b, $base);
ok($r['code'] === 200 && body_json($r)['noop'] === true, 'Marco, a Boards admin (full everywhere), restores too (here: nothing to do)');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['version_restore']['approval'] ?? null) === 'other' && ($reg['actions']['version_save']['approval'] ?? null) === null, 'the kernel pauses an agent\'s version_restore (approval other), not version_save');
share_as(26, $b, 'agent', 42, 'edit');
$a = agent_act(42, 8100, '/boards/version-restore.php', ['board' => $b, 'version' => $base]);
ok($a['code'] === 403, 'an agent at edit level is refused by the database too, in its words (' . $a['code'] . ')');
$a = agent_act(42, 8101, '/boards/version-save.php', ['board' => $b, 'label' => 'By the agent']);
ok($a['code'] === 200 && body_json($a)['ok'] === true, 'an agent at edit saves a version (no pause)');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'version.save' AND source = 'agent' AND agent_run_id = 8101 AND board_id = CAST(:b AS uuid)", ['b' => $b]) === 1, 'logged with source agent and its run');

echo "   locked items\n";
pg("UPDATE board_items SET locked = true WHERE id = '$alpha'");
$base2 = (int) body_json(save_version($jp, $b, 'Alpha locked'))['record_id'];
ops_post($jp, $b, [['op' => 'update', 'id' => $alpha, 'fields' => ['locked' => false]], ['op' => 'update', 'id' => $alpha, 'fields' => ['x' => 50]]]);
$r = restore_version($jp, $b, $base2);
ok($r['code'] === 200 && item_state($alpha) === [0.0, false] && (bool) item_row($alpha)['locked'] === true, 'a locked item is unlocked for the change and locked again as the snapshot had it');
finish();
