<?php
/**
 * Slice 7 — the worker's two passes (docs/build-specs/history-versions.md "Proof" 6): a changed board gets one interval version and a second pass in the same hour none; an aged one gets
 * another; thinning keeps one a day past interval_version_days and every named version; at 03:xx (the business's zone) compaction removes ops older than op_retention_days after a compaction
 * version, once a night, and not at 04:xx. A shipped template gets no interval version. (The purge of deleted items is slice 5's thumbnails pass.) Run through tests/phase3/slice7/run.sh.
 */
require __DIR__ . '/lib.php';
[$jp] = sign_on(26);

echo "6. Interval versions\n";
[$b, $frame, $alpha] = board_with_three($jp, 'SMOKE Worker versions');
$w = worker_pass('versions');
$iv = array_values(array_filter(versions_of($b), static fn ($v) => $v['reason'] === 'interval'));
ok($w['rc'] === 0 && ($w['ran']['versions']['saved'] ?? 0) >= 1 && count($iv) === 1 && !isset($w['ran']['versions']['stub']), 'a changed board gets one interval version (saved ' . ($w['ran']['versions']['saved'] ?? '?') . ' in all)');
$w = worker_pass('versions');
ok(count(versions_of($b)) === 1 && ($w['ran']['versions']['saved'] ?? -1) === 0, 'a second pass finds nothing to save');
ops_post($jp, $b, [['op' => 'update', 'id' => $alpha, 'fields' => ['x' => 77]]]);
$w = worker_pass('versions');
ok(count(versions_of($b)) === 1, 'the board changed, but within the hour: still one version (at most hourly)');
pg("UPDATE board_versions SET created_at = now() - interval '2 hours' WHERE board_id = '$b'");
$w = worker_pass('versions');
ok(count(versions_of($b)) === 2 && ($w['ran']['versions']['saved'] ?? 0) >= 1, 'two hours on, the change has its own interval version');
$w = worker_pass('versions');
ok(count(versions_of($b)) === 2, 'and a quiet board gets no more');
$tpl = make_board($jp, 'SMOKE Shipped template');
ops_post($jp, $tpl, [item_op(['text' => 'In a template', 'z' => 'a1'])]);
pg("UPDATE boards SET is_template = true, template_scope = 'shipped', template_category = 'other' WHERE id = '$tpl'");
$w = worker_pass('versions');
ok(versions_of($tpl) === [], 'a shipped template never gets an interval version');
$w = worker_pass('versions');
ok($w['rc'] === 0 && array_filter($w['errors']) === [], 'the pass reports no error');

echo "   thinning\n";
[$tb] = board_with_three($jp, 'SMOKE Worker thinning');
$named = (int) body_json(save_version($jp, $tb, 'Named long ago'))['record_id'];
pg("INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot, plain_text, item_count, created_at)
    SELECT board_id, 100 + g, 0, 'interval', snapshot, '', item_count, date_trunc('day', now() - interval '40 days') + g * interval '2 hours'
      FROM board_versions, generate_series(1, 3) g WHERE board_id = '$tb' AND version_no = $named");
pg("INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot, plain_text, item_count, created_at)
    SELECT board_id, 200, 0, 'interval', snapshot, '', item_count, date_trunc('day', now() - interval '41 days') + interval '5 hours' FROM board_versions WHERE board_id = '$tb' AND version_no = $named");
pg("INSERT INTO board_versions (board_id, version_no, content_rev, reason, snapshot, plain_text, item_count, created_at)
    SELECT board_id, 300, 0, 'interval', snapshot, '', item_count, now() - interval '2 days' FROM board_versions WHERE board_id = '$tb' AND version_no = $named");
pg("UPDATE board_versions SET created_at = now() - interval '40 days' WHERE board_id = '$tb' AND version_no = $named");
$w = worker_pass('versions');
$left = array_column(versions_of($tb), 'reason', 'version_no');
ok(($w['ran']['versions']['thinned'] ?? 0) === 2 && array_keys($left) === [$named, 103, 200, 300], 'past interval_version_days: of three the same day one stays (the last), the next day\'s lone one stays, the recent one stays, the named one stays (kept ' . implode(',', array_keys($left)) . ')');
pg("UPDATE bd_settings SET daily_version_days = 35 WHERE id = 1");
$w = worker_pass('versions');
$left = array_column(versions_of($tb), 'reason', 'version_no');
pg("UPDATE bd_settings SET daily_version_days = 365 WHERE id = 1");
ok(array_keys($left) === [$named, 300], 'past daily_version_days the daily ones go too — never a named version or a recent one (kept ' . implode(',', array_keys($left)) . ')');

echo "   compaction\n";
$tz = (string) one('SELECT timezone FROM bd_settings WHERE id = 1');
[$cb, , $ca] = board_with_three($jp, 'SMOKE Worker compaction');
pg("UPDATE board_ops SET at = now() - interval '100 days' WHERE board_id = '$cb'");
$oldOps = count(ops_of($cb));
$recent = ops_post($jp, $cb, [['op' => 'update', 'id' => $ca, 'fields' => ['x' => 5]]]);
$day = '2031-03-14';
$w = worker_pass('compaction', "{$day}T04:30:00+00:00");
ok($tz === 'UTC' && $w['rc'] === 0 && ($w['ran']['compaction']['compacted'] ?? -1) === 0 && count(ops_of($cb)) === $oldOps + 1, 'at 04:xx compaction does nothing (' . json_encode($w['ran']['compaction'] ?? null) . ')');
$w = worker_pass('compaction', "{$day}T03:30:00+00:00");
$ranOps = ops_of($cb);
$cv = array_values(array_filter(versions_of($cb), static fn ($v) => $v['reason'] === 'compaction'));
ok(($w['ran']['compaction']['compacted'] ?? 0) >= $oldOps && count($ranOps) === 1 && count($cv) === 1, 'at 03:xx the ops older than op_retention_days go after a compaction version (' . json_encode($w['ran']['compaction'] ?? null) . ')');
ok(!isset($w['ran']['compaction']['stub']), 'and the pass is no stub');
$snap = (string) one("SELECT snapshot::text FROM board_versions WHERE board_id = CAST(:b AS uuid) AND reason = 'compaction'", ['b' => $cb]);
ok(str_contains($snap, 'Alpha') && str_contains($snap, 'Beta'), 'the compaction version holds the board as it stood at the cut, so a restore still works');
pg("UPDATE board_ops SET at = now() - interval '100 days' WHERE board_id = '$cb'");
$w = worker_pass('compaction', "{$day}T03:50:00+00:00");
ok(($w['ran']['compaction']['compacted'] ?? -1) === 0 && ($w['ran']['compaction']['skipped'] ?? '') === 'already done today' && count(ops_of($cb)) === 1, 'a second pass the same night does nothing');
$w = worker_pass('compaction', '2031-03-15T03:05:00+00:00');
ok(($w['ran']['compaction']['compacted'] ?? 0) >= 1 && count(ops_of($cb)) === 0, 'the next night it runs again');
pg("UPDATE bd_settings SET timezone = 'America/Chicago' WHERE id = 1");
pg("UPDATE board_ops SET at = now() - interval '100 days' WHERE board_id = '$cb'");
$none = ops_post($jp, $cb, [['op' => 'update', 'id' => $ca, 'fields' => ['x' => 6]]]);
pg("UPDATE board_ops SET at = now() - interval '100 days' WHERE board_id = '$cb'");
$w = worker_pass('compaction', '2031-03-16T03:30:00+00:00');   // 03:30 UTC = 22:30 the night before in Chicago: not the hour
ok(($w['ran']['compaction']['skipped'] ?? '') === 'not the hour', 'the hour is the business\'s own: 03:30 UTC is not 03:xx in Chicago');
$w = worker_pass('compaction', '2031-03-16T08:30:00+00:00');   // 03:30 CDT
ok(($w['ran']['compaction']['compacted'] ?? 0) >= 1, '08:30 UTC is 03:30 in Chicago: it runs');
pg("UPDATE bd_settings SET timezone = 'UTC' WHERE id = 1");

echo "   the whole pass\n";
$w = worker_pass('versions,compaction');
ok($w['rc'] === 0 && isset($w['ran']['versions']['saved'], $w['ran']['compaction']['compacted']), 'both passes together report saved, thinned and compacted');
$wp = (string) one("SELECT steps::text FROM worker_passes ORDER BY id DESC LIMIT 1");
ok(str_contains($wp, 'compacted') && !str_contains($wp, 'Alpha') && !str_contains($wp, 'Beta'), 'the worker_passes row carries counts, never an item\'s text');
finish();
