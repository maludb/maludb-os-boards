<?php
/**
 * Proof 8 (docs/build-specs/pen-files.md "Proof"): the worker's `thumbnails` pass — an upload never committed as an item is purged after 24
 * hours (BD_WORKER_NOW) with its files and a `file.purge` row, a younger one and a committed one are not; an image item deleted 91 days ago goes
 * through bd_purge_deleted_items() with its four files unlinked; a path the database answers that lies outside the store is never unlinked;
 * an attachment never thumbnailed gets its sizes.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Worker');
$worker = static function (string $now = ''): array {
    $env = $now === '' ? '' : 'BD_WORKER_NOW=' . escapeshellarg($now) . ' ';
    exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && ' . $env . 'php bin/worker.php --passes=thumbnails 2>&1', $out, $rc);
    $json = json_decode((string) end($out), true) ?: [];
    return ['rc' => $rc, 'ran' => $json['ran']['thumbnails'] ?? [], 'out' => implode("\n", $out)];
};

echo "8. orphans after 24 hours\n";
$old = body_json(upload_as($jp, $b, FIXTURES . '/photo-3000x2000.jpg'));
$young = body_json(upload_as($jp, $b, FIXTURES . '/shape.webp'));
$kept = body_json(upload_as($jp, $b, FIXTURES . '/anim.gif'));
place_upload($jp, $b, $kept);
$oldFiles = att_files(att($old['attachment_id']));
pg("UPDATE attachments SET created_at = now() - interval '23 hours' WHERE id = {$young['attachment_id']}");
$w = $worker();
ok($w['rc'] === 0 && ($w['ran']['orphans'] ?? -1) === 0 && att($old['attachment_id']) !== [], 'now: nothing is an orphan yet (rc 0, 0 purged)');
$since = last_activity_id();
$w = $worker((new DateTimeImmutable('+25 hours'))->format(DATE_ATOM));
$gone = array_filter($oldFiles, 'is_file');
ok($w['rc'] === 0 && ($w['ran']['orphans'] ?? 0) >= 2 && att($old['attachment_id']) === [] && count($oldFiles) === 4 && $gone === [],
    'in 25 hours: the never-committed upload purged — its row and its four files (' . ($w['ran']['orphans'] ?? '?') . ' orphans in all)');
ok(att($kept['attachment_id']) !== [] && array_filter(att_files(att($kept['attachment_id'])), 'is_file') === att_files(att($kept['attachment_id'])),
    'the committed upload stays, with its files');
$purges = array_values(array_filter(activity('file.purge', $since), static fn ($l) => $l['board_id'] === $b));
ok(count($purges) === 1 && json_decode((string) $purges[0]['after'], true)['orphans'] >= 2 && $purges[0]['source'] === 'cron', 'one file.purge row for the board (source cron) with the count');

echo "8. items deleted past op_retention_days\n";
$img = body_json(upload_as($jp, $b, FIXTURES . '/photo-3000x2000.jpg'));
place_upload($jp, $b, $img, 900, 0);
$files = att_files(att($img['attachment_id']));
ops_post($jp, $b, [['op' => 'delete', 'ids' => [$img['item_id']]]]);
pg("UPDATE board_items SET deleted_at = now() - interval '91 days' WHERE id = '{$img['item_id']}'");
// a row the database will answer whose file lies OUTSIDE the store — it must be left where it is
$outside = dirname(store_dir()) . '/bd5-outside-' . getmypid() . '.txt';
file_put_contents($outside, 'not the store\'s');
$ghost = uuid4();
pg("INSERT INTO board_items (id, board_id, type, x, y, width, height, z, style, data, created_by, deleted_at)
    VALUES ('$ghost', '$b', 'image', 0, 0, 10, 10, 'a9', '{}', '{}', 26, now() - interval '91 days')");
pg("INSERT INTO attachments (record_type, record_uuid, board_id, filename, mime_type, byte_size, sha256, storage_path)
    VALUES ('item', '$ghost', '$b', 'x.txt', 'text/plain', 1, 'x', '../" . basename($outside) . "')");
$w = $worker();
ok($w['rc'] === 0 && item_row($img['item_id']) === [] && att($img['attachment_id']) === [] && count($files) === 4 && array_filter($files, 'is_file') === [],
    'the image deleted 91 days ago: purged by bd_purge_deleted_items() — the item, its attachment and its four files');
ok(is_file($outside) && ($w['ran']['files_removed'] ?? 0) === 4 && ($w['ran']['purged_items'] ?? 0) >= 2 && str_contains($w['out'], 'outside the store'),
    'the path outside the store: left on disk and said so — 4 files removed, not 5');
@unlink($outside);

echo "8. an attachment never thumbnailed\n";
$raw = body_json(upload_as($jp, $b, FIXTURES . '/anim.gif'));
place_upload($jp, $b, $raw, 1800, 0);
foreach (att_files(att($raw['attachment_id'])) as $f) {
    if (str_contains($f, '/thumbnails/')) {
        @unlink($f);
    }
}
pg("UPDATE attachments SET thumbnails = '{}', thumbnail_path = NULL WHERE id = {$raw['attachment_id']}");
$w = $worker();
$ra = att($raw['attachment_id']);
ok(($w['ran']['made'] ?? 0) === 1 && thumb_size($ra, 256) === [256, 171] && $ra['thumbnail_path'] === $ra['thumbnails']['256'], 'the pass made its sizes (made 1)');
ok(($worker()['ran']['made'] ?? -1) === 0, 'and the next pass finds nothing to make (a card\'s {"none"} is never tried again)');
finish();
