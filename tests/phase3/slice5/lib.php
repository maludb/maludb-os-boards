<?php
/**
 * Slice 5's helpers (docs/build-specs/pen-files.md "Proof"), on slice 4's (and through it 2's, 1's and Phase 2's): an upload posted as the
 * island posts it (multipart, the CSRF header), the scratch store, generated files (a PNG with alpha, a renamed executable, a file of N MB),
 * an attachment's row. `php tests/phase3/slice5/lib.php seed` makes the browser's boards as JSON: Priya's (26) `draw`, `files`, `phone`, and
 * `drop`, each shared with Bea (28) at edit.
 */
require dirname(__DIR__) . '/slice4/lib.php';

const FIXTURES = __DIR__ . '/../../fixtures';

function store_dir(): string { return rtrim(need('BD_STORAGE_DIR'), '/'); }
function scratch_file(string $name): string { return sys_get_temp_dir() . '/bd5-' . getmypid() . '-' . $name; }

/** POST a file to the upload door as $jar does from the canvas; $as names the file the client sends (its name, not its type). */
function upload_as(string $jar, string $board, string $path, ?string $as = null, array $extraHeaders = []): array
{
    $csrf = csrf_for($jar);
    return req('POST', '/files/upload.php', ['jar' => $jar, 'headers' => array_merge(['Accept: application/json', 'X-CSRF-Token: ' . $csrf], $extraHeaders),
        'raw' => ['board' => $board, 'csrf_token' => $csrf, 'file' => new CURLFile($path, 'application/octet-stream', $as ?? basename($path))]]);
}

/** The attachment's row, as postgres (the store's paths are relative). */
function att(int $id): array
{
    $r = q('SELECT * FROM attachments WHERE id = :i', ['i' => $id])[0] ?? [];
    if ($r !== []) {
        $r['thumbnails'] = json_decode((string) $r['thumbnails'], true);
    }
    return $r;
}

/** A thumbnail's pixel size on disk: [w, h] or null. */
function thumb_size(array $a, int $size): ?array
{
    $rel = $a['thumbnails'][(string) $size] ?? null;
    $info = $rel === null ? false : @getimagesize(store_dir() . '/' . $rel);
    return $info === false ? null : [(int) $info[0], (int) $info[1]];
}

/** Every file an attachment owns in the store (the original and its distinct thumbnails), absolute. */
function att_files(array $a): array
{
    $f = [store_dir() . '/' . $a['storage_path']];
    foreach ((array) ($a['thumbnails'] ?? []) as $k => $p) {
        if (ctype_digit((string) $k)) {
            $f[] = store_dir() . '/' . $p;
        }
    }
    return array_values(array_unique($f));
}

/** A PNG with a transparent half (to prove the alpha survives the thumbnails). */
function alpha_png(): string
{
    $p = scratch_file('alpha.png');
    $im = imagecreatetruecolor(1600, 900);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 800, 0, 1599, 899, imagecolorallocatealpha($im, 240, 70, 40, 0));
    imagepng($im, $p);
    return $p;
}

/** An executable with a picture's name (finfo reads MZ … PE). */
function fake_png(): string
{
    $p = scratch_file('setup.png');
    file_put_contents($p, 'MZ' . str_repeat("\x90\x00", 29) . pack('V', 64) . "\0\0" . "PE\0\0" . str_repeat("\0", 400));
    return $p;
}

/** A file of exactly $mb MiB (zeros), named as given. */
function sized_file(int $mb, string $name): string
{
    $p = scratch_file($name);
    $h = fopen($p, 'wb');
    $chunk = str_repeat("\0", 1024 * 1024);
    for ($i = 0; $i < $mb; $i++) {
        fwrite($h, $chunk);
    }
    fclose($h);
    return $p;
}

/** Commit the item an upload's answer names, as the island does (an image or a document at x, y). */
function place_upload(string $jar, string $board, array $u, float $x = 0, float $y = 0): array
{
    $w = $u['kind'] === 'image' || $u['preview'] ? min(800, (float) ($u['width'] ?? 320)) : 320;
    $h = $u['kind'] === 'image' || $u['preview'] ? ($u['width'] ? $w * $u['height'] / $u['width'] : 120) : 120;
    return ops_post($jar, $board, [['op' => 'create', 'item' => ['id' => $u['item_id'], 'type' => $u['kind'], 'parent_id' => null, 'x' => $x, 'y' => $y,
        'width' => round($w, 2), 'height' => round($h, 2), 'rotation' => 0, 'z' => 'a5', 'style' => (object) [],
        'data' => ['attachment_id' => $u['attachment_id'], 'title' => $u['filename'], 'mime_type' => $u['mime_type'], 'byte_size' => $u['byte_size'], 'preview' => $u['preview']],
        'locked' => false]]]);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__) && ($_SERVER['argv'][1] ?? '') === 'seed') {
    [$jp, ] = sign_on(26);
    $out = [];
    foreach (['draw' => 'SMOKE Pen', 'files' => 'SMOKE Files', 'drop' => 'SMOKE Drop', 'phone' => 'SMOKE Pen phone', 'wall' => 'SMOKE Wall'] as $k => $title) {
        $out[$k] = make_board($jp, $title);
        if ($k !== 'wall') {
            share_as(26, $out[$k], 'member', 28, 'edit');
        }
    }
    // the pen board: a sticky to highlight over
    $s = item_op(['text' => 'Highlight me', 'x' => 0, 'y' => 0, 'z' => 'a1']);
    ops_post($jp, $out['draw'], [$s]);
    $out['sticky'] = $s['item']['id'];
    echo json_encode($out);
}
