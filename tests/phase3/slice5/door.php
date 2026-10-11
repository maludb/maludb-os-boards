<?php
/**
 * Proofs 5 and 7 at the door (docs/build-specs/pen-files.md "The file door", "Proof"): GET /files/{id}[?size=][&download=1] — the headers
 * (the stored type, nosniff, private an hour, an ETag and 304), sizes and their 404, documents as attachments with their name, an SVG always
 * sandboxed and an attachment when opened as a page, `file.open` only for an original opened or downloaded; the wall — a stranger, a viewer,
 * a trashed board, a path outside the store; find_attachments.
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/files/queries.php';
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Door');
share_as(26, $b, 'member', 30, 'view');
$photo = body_json(upload_as($jp, $b, FIXTURES . '/photo-3000x2000.jpg'));
$pdf = body_json(upload_as($jp, $b, FIXTURES . '/two-pages.pdf'));
$docx = body_json(upload_as($jp, $b, FIXTURES . '/notes.docx'));
$svg = body_json(upload_as($jp, $b, FIXTURES . '/evil.svg'));
$orphan = body_json(upload_as($jp, $b, FIXTURES . '/shape.webp'));
foreach ([$photo, $pdf, $docx, $svg] as $k => $u) {
    place_upload($jp, $b, $u, $k * 900.0, 0);
}
$hdr = static fn (array $r, string $name): string => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $r['headers'], $m) ? trim($m[1]) : '';
$get = static fn (string $jar, string $path, array $h = []): array => req('GET', $path, ['jar' => $jar, 'headers' => $h]);

echo "the door's answers\n";
$since = last_activity_id();
$r = $get($jp, '/files/' . $photo['attachment_id'], ['Sec-Fetch-Dest: image']);
ok($r['code'] === 200 && $hdr($r, 'Content-Type') === 'image/jpeg' && $hdr($r, 'X-Content-Type-Options') === 'nosniff' && $hdr($r, 'Cache-Control') === 'private, max-age=3600'
   && $hdr($r, 'Content-Disposition') === 'inline' && strlen($r['body']) === $photo['byte_size'] && hash('sha256', $r['body']) === att($photo['attachment_id'])['sha256'],
    'the original image: 200, image/jpeg inline, nosniff, private for an hour — its bytes');
$etag = $hdr($r, 'ETag');
ok($etag !== '' && $get($jp, '/files/' . $photo['attachment_id'], ['If-None-Match: ' . $etag])['code'] === 304, 'its ETag: 304 when it has not changed');
$t = $get($jp, '/files/' . $photo['attachment_id'] . '?size=256', ['Sec-Fetch-Dest: image']);
$ti = @getimagesizefromstring($t['body']);
ok($t['code'] === 200 && $hdr($t, 'Content-Type') === 'image/jpeg' && $ti !== false && $ti[0] === 256 && $hdr($t, 'ETag') !== $etag, '?size=256: the 256 thumbnail (its own ETag)');
ok($get($jp, '/files/' . $photo['attachment_id'] . '?size=512')['code'] === 404 && $get($jp, '/files/' . $docx['attachment_id'] . '?size=256')['code'] === 404,
    'a size it has not (512; a card\'s 256): 404');
$pp = $get($jp, '/files/' . $pdf['attachment_id'] . '?size=1024', ['Sec-Fetch-Dest: image']);
ok($pp['code'] === 200 && $hdr($pp, 'Content-Type') === 'image/png' && $hdr($pp, 'Content-Disposition') === 'inline', "a PDF's first page at 1024: a PNG, inline");
$pd = $get($jp, '/files/' . $pdf['attachment_id'], ['Sec-Fetch-Dest: document']);
$dd = $get($jp, '/files/' . $docx['attachment_id'], ['Sec-Fetch-Dest: document']);
ok($pd['code'] === 200 && $hdr($pd, 'Content-Type') === 'application/pdf' && str_starts_with($hdr($pd, 'Content-Disposition'), 'attachment; filename="two-pages.pdf"')
   && str_starts_with($hdr($dd, 'Content-Disposition'), 'attachment; filename="notes.docx"; filename*=UTF-8\'\'notes.docx'), 'documents: always attachments, with their names');
$dl = $get($jp, '/files/' . $photo['attachment_id'] . '?download=1', ['Sec-Fetch-Dest: document']);
ok(str_starts_with($hdr($dl, 'Content-Disposition'), 'attachment; filename="photo-3000x2000.jpg"'), '?download=1: an image as an attachment');

echo "5. the SVG\n";
$sv = $get($jp, '/files/' . $svg['attachment_id'], ['Sec-Fetch-Dest: document']);
$sa = $get($jp, '/files/' . $svg['attachment_id'], ['Sec-Fetch-Dest: image']);
$csp = "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:";
ok($sv['code'] === 200 && $hdr($sv, 'Content-Type') === 'image/svg+xml' && $hdr($sv, 'Content-Security-Policy') === $csp && str_starts_with($hdr($sv, 'Content-Disposition'), 'attachment')
   && str_starts_with($hdr($sa, 'Content-Disposition'), 'attachment'), '/files/{svg}: always an attachment under the sandbox CSP (opened as a page, or fetched any other way)');
$si = $get($jp, '/files/' . $svg['attachment_id'] . '?inline=1', ['Sec-Fetch-Dest: image']);
$sf = $get($jp, '/files/' . $svg['attachment_id'] . '?inline=1', ['Sec-Fetch-Dest: iframe']);
$sd = $get($jp, '/files/' . $svg['attachment_id'] . '?inline=1', ['Sec-Fetch-Dest: document']);
ok($hdr($si, 'Content-Security-Policy') === $csp && $hdr($si, 'Content-Disposition') === 'inline' && $hdr($sf, 'Content-Security-Policy') === $csp
   && str_starts_with($hdr($sd, 'Content-Disposition'), 'attachment'), '?inline=1 (the canvas\'s <img>, a frame): inline under the sandbox CSP — opened as a page, still an attachment');
$opens = activity('file.open', $since);
$ids = array_map(static fn ($l) => (int) json_decode((string) $l['after'], true)['attachment_id'], $opens);
sort($ids);
$want = [$photo['attachment_id'], $pdf['attachment_id'], $docx['attachment_id'], $svg['attachment_id']];
sort($want);
ok(count($opens) === 5 && array_values(array_unique($ids)) === $want && count(array_filter($opens, static fn ($l) => $l['board_id'] === $b)) === 5,
    'file.open: the five originals opened as pages or downloaded — never a thumbnail, an <img> or ?inline=1 (with the board)');

echo "7. the wall\n";
[$jb, ] = sign_on(28);
[$jd, ] = sign_on(30);
ok($get($jb, '/files/' . $photo['attachment_id'])['code'] === 404 && $get($jb, '/files/' . $photo['attachment_id'] . '?size=256')['code'] === 404
   && $get($jb, '/files/' . $photo['attachment_id'])['body'] === $get($jb, '/files/99999999')['body'], 'Bea (no access): 404 for the file and its thumbnail — the same answer as no file');
ok($get($jd, '/files/' . $photo['attachment_id'] . '?size=1024')['code'] === 200 && $get($jd, '/files/' . $docx['attachment_id'])['code'] === 200, 'Dana (view): 200');
$x = q("INSERT INTO attachments (record_type, record_uuid, board_id, filename, mime_type, byte_size, sha256, storage_path)
        VALUES ('item', CAST(:u AS uuid), CAST(:b AS uuid), 'passwd', 'text/plain', 1, 'x', '../../../../etc/passwd') RETURNING id", ['u' => uuid4(), 'b' => $b])[0]['id'];
ok($get($jp, '/files/' . $x)['code'] === 404, 'a row whose path climbs out of the store: 404 (the door resolves only inside it)');
as_member(26);
$rows = find_attachments(pdo(), $b, null, 50);
$imgs = find_attachments(pdo(), $b, 'image', 50);
ok(count($rows) === 4 && !in_array($orphan['item_id'], array_column($rows, 'item_id'), true) && !array_key_exists('storage_path', $rows[0])
   && array_column($imgs, 'item_id') === [$svg['item_id'], $photo['item_id']], 'find_attachments: the four on items (the orphan upload not), images alone by kind — never a path');
as_member(28);
ok(find_attachments(pdo(), $b, null, 50) === [], 'find_attachments as Bea: nothing (the view\'s wall)');
pg("SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_delete('$b', 26) \\g /dev/null");
ok($get($jp, '/files/' . $photo['attachment_id'])['code'] === 404 && $get($jd, '/files/' . $photo['attachment_id'] . '?size=256')['code'] === 404,
    'the board in the trash: 404 for its owner and its viewer');
finish();
