<?php
/**
 * Proofs 3, 4 and 6 at the door (docs/build-specs/pen-files.md "Proof"): POST /files/upload.php — the 3000 × 2000 JPEG's three thumbnails
 * and its place in the store, the GIF's first frame, the WebP's and a PNG's alpha, the PDF's first page at three sizes, the DOCX a card, the
 * SVG's size from its viewBox; finfo, never the name (an executable called .png refused); the size limits (26 MB the setting's sentence, 33 MB
 * 413); a viewer, an agent and a request without its token refused; one `file.upload` row each, never a path.
 */
require __DIR__ . '/lib.php';
[$jp, ] = sign_on(26);
$b = make_board($jp, 'SMOKE Uploads');
share_as(26, $b, 'member', 30, 'view');

echo "3. images: three sizes, never upscaled, alpha kept\n";
$since = last_activity_id();
$r = upload_as($jp, $b, FIXTURES . '/photo-3000x2000.jpg');
$u = body_json($r);
$a = att((int) ($u['attachment_id'] ?? 0));
ok($r['code'] === 200 && $u['kind'] === 'image' && $u['mime_type'] === 'image/jpeg' && $u['width'] === 3000 && $u['height'] === 2000 && $u['sizes'] === [256, 1024, 2048]
   && $u['record_id'] === $u['attachment_id'] && preg_match('/^[0-9a-f-]{36}$/', (string) $u['item_id']) === 1,
    'the 3000 × 2000 JPEG: 200 — an image, its size, three thumbnail sizes, the attachment as record_id and a fresh item id');
ok(thumb_size($a, 2048) === [2048, 1365] && thumb_size($a, 1024) === [1024, 683] && thumb_size($a, 256) === [256, 171] && str_ends_with((string) $a['thumbnails']['256'], '.jpg')
   && $a['thumbnail_path'] === $a['thumbnails']['256'], 'its thumbnails 2048 × 1365, 1024 × 683, 256 × 171 — JPEG, the 256 one the thumbnail_path');
$sha = hash_file('sha256', FIXTURES . '/photo-3000x2000.jpg');
ok(preg_match('#^attachments/\d{4}/\d{2}/' . $a['id'] . '-' . substr($sha, 0, 12) . '\.jpg$#', (string) $a['storage_path']) === 1 && $a['sha256'] === $sha
   && (int) $a['byte_size'] === filesize(FIXTURES . '/photo-3000x2000.jpg') && hash_file('sha256', store_dir() . '/' . $a['storage_path']) === $sha
   && $a['record_type'] === 'item' && $a['record_uuid'] === $u['item_id'] && $a['board_id'] === $b && (int) $a['uploaded_by'] === 26,
    'the original kept byte for byte at attachments/{yyyy}/{mm}/{id}-{sha12}.jpg, against the minted item id and the board');
$log = activity('file.upload', $since);
$after = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && $log[0]['board_id'] === $b && $after['attachment_id'] === $a['id'] && !str_contains((string) $log[0]['after'], 'attachments/') && !str_contains((string) $log[0]['after'], store_dir()),
    'one file.upload row with the board and the ids — never a path');

$g = body_json(upload_as($jp, $b, FIXTURES . '/anim.gif'));
$ga = att((int) ($g['attachment_id'] ?? 0));
ok(($g['kind'] ?? '') === 'image' && $g['width'] === 480 && thumb_size($ga, 256) === [256, 171] && thumb_size($ga, 1024) === [480, 320]
   && $ga['thumbnails']['1024'] === $ga['thumbnails']['2048'] && str_ends_with((string) $ga['thumbnails']['256'], '.png'),
    'the GIF: its first frame, PNG out; 1024 and 2048 share one copy at its own 480 × 320 (never upscaled)');
$w = body_json(upload_as($jp, $b, FIXTURES . '/shape.webp'));
$wa = att((int) ($w['attachment_id'] ?? 0));
$wi = @imagecreatefromwebp(store_dir() . '/' . ($wa['thumbnails']['256'] ?? 'x'));
ok(($w['kind'] ?? '') === 'image' && str_ends_with((string) $wa['thumbnails']['256'], '.webp') && $wi instanceof GdImage && ((imagecolorat($wi, 1, 1) >> 24) & 0x7f) > 100,
    'the WebP: WebP thumbnails, its transparent corner still transparent');
$p = body_json(upload_as($jp, $b, alpha_png()));
$pa = att((int) ($p['attachment_id'] ?? 0));
$pi = @imagecreatefrompng(store_dir() . '/' . ($pa['thumbnails']['256'] ?? 'x'));
ok(($p['kind'] ?? '') === 'image' && $pi instanceof GdImage && ((imagecolorat($pi, 5, 5) >> 24) & 0x7f) === 127 && ((imagecolorat($pi, 250, 100) >> 24) & 0x7f) === 0,
    'a PNG keeps its alpha: the transparent half transparent, the opaque half opaque');

echo "4. files: a PDF's first page, a card, finfo decides\n";
$f = body_json(upload_as($jp, $b, FIXTURES . '/two-pages.pdf'));
$fa = att((int) ($f['attachment_id'] ?? 0));
$big = thumb_size($fa, 2048);
ok(($f['kind'] ?? '') === 'document' && $f['preview'] === true && $big !== null && max($big) === 2048 && max((array) thumb_size($fa, 1024)) === 1024 && max((array) thumb_size($fa, 256)) === 256
   && str_ends_with((string) $fa['thumbnails']['256'], '.png') && $f['width'] === $big[0], "the two-page PDF: a document with its first page at 2048, 1024 and 256 (PNG)");
$d = body_json(upload_as($jp, $b, FIXTURES . '/notes.docx'));
$da = att((int) ($d['attachment_id'] ?? 0));
ok(($d['kind'] ?? '') === 'document' && $d['preview'] === false && $d['sizes'] === [] && $da['thumbnails'] === ['none' => 'not an image']
   && $d['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'the DOCX: a document without a preview (a card)');
$s = body_json(upload_as($jp, $b, FIXTURES . '/evil.svg'));
ok(($s['kind'] ?? '') === 'image' && $s['mime_type'] === 'image/svg+xml' && $s['width'] === 400 && $s['height'] === 300 && $s['sizes'] === [] && $s['preview'] === true,
    "the SVG: an image sized by its viewBox (400 × 300), no raster thumbnail — the browser draws it");
$before = (int) one('SELECT count(*) FROM attachments');
$r = upload_as($jp, $b, fake_png());
ok($r['code'] === 422 && str_contains($r['body'], 'Boards keeps images and documents, not application/x-dosexec') && (int) one('SELECT count(*) FROM attachments') === $before,
    'an executable named setup.png: refused by finfo "Boards keeps images and documents, not application/x-dosexec" — no row');
$r = upload_as($jp, $b, FIXTURES . '/photo-3000x2000.jpg', 'holiday.png');
ok($r['code'] === 200 && body_json($r)['filename'] === 'holiday.jpg' && body_json($r)['mime_type'] === 'image/jpeg', 'a JPEG named .png: kept as a JPEG, its name following its bytes (holiday.jpg)');
$csv = scratch_file('numbers.csv');
file_put_contents($csv, "a,b\n1,2\n");
ok(body_json(upload_as($jp, $b, $csv))['mime_type'] === 'text/csv', 'a CSV (text to finfo): text/csv by its name, among text types only');
$empty = scratch_file('empty.txt');
file_put_contents($empty, '');
ok(upload_as($jp, $b, $empty)['code'] === 422, 'an empty file: refused');

echo "6. sizes and who may upload\n";
$r = upload_as($jp, $b, sized_file(26, 'big.zip'));
ok($r['code'] === 422 && str_contains($r['body'], 'Boards keeps files up to 25 MB.'), '26 MB: refused with the setting\'s sentence "Boards keeps files up to 25 MB."');
$r = upload_as($jp, $b, sized_file(33, 'huge.zip'));
ok($r['code'] === 413, '33 MB: 413 — past the body limit (' . (getenv('BD_APP') === 'apache' ? "Apache's LimitRequestBody" : "PHP's post_max_size, the door's 413") . ')');
[$jd, ] = sign_on(30);
ok(upload_as($jd, $b, FIXTURES . '/shape.webp')['code'] === 403, 'Dana (view): 403 — uploading needs edit');
[$jb, ] = sign_on(28);
ok(upload_as($jb, $b, FIXTURES . '/shape.webp')['code'] === 404, "Bea (no access): 404 — the same nothing as no board");
$RUN = 8501;
kernel_state(function ($s) use ($RUN) { $s['facts'][(string) $RUN] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => $RUN, 'request_id' => 'r-' . $RUN,
    'endpoints' => [['id' => 1, 'application' => APP]]]; return $s; });
share_as(26, $b, 'agent', 40, 'edit');
$r = agent_act(40, $RUN, '/files/upload.php', ['board' => $b]);
ok($r['code'] === 403 && str_contains($r['body'], 'This is for people'), "an agent's file_upload: 403 \"This is for people\" (an agent never uploads)");
$r = req('POST', '/files/upload.php', ['jar' => $jp, 'headers' => ['Accept: application/json'], 'raw' => ['board' => $b, 'file' => new CURLFile(FIXTURES . '/shape.webp')]]);
ok(in_array($r['code'], [403, 419], true), 'without the CSRF token: refused (' . $r['code'] . ')');
ok(count(array_filter(glob(store_dir() . '/attachments/*/*/*') ?: [], static fn ($f) => str_contains($f, 'setup'))) === 0, 'nothing of a refused upload is left in the store');
finish();
