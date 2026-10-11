<?php
declare(strict_types=1);

/**
 * Thumbnails (slice 5; docs/build-specs/pen-files.md "Thumbnails"): a raster image → 256, 1024 and 2048 on the longest side with GD (never
 * upscaled — a size the original does not reach shares one copy at the original's size; a GIF's first frame; PNG out for PNG and GIF, JPEG
 * q 82, WebP q 80; a JPEG turned upright by its EXIF orientation); a PDF → its first page by `pdftoppm` (a 20-second limit), then the same
 * three sizes as PNG; an SVG → no raster, its size from the root's viewBox (or width/height, else 800 × 600); anything else → none.
 * Thumbnails are written beside the original under `thumbnails/` (store.php file_thumb_path()). An image past THUMB_MAX_PIXELS is never
 * decoded (the vhost's 256 MB would not hold it): it has no preview and becomes a file card (the build's decision 2).
 */

require_once __DIR__ . '/store.php';

const THUMB_MAX_PIXELS = 25_000_000;            // 25 MP decoded = 100 MB of GD's truecolor, inside the vhost's memory_limit 256M
const PDF_RENDER_SECONDS = 20;

/**
 * Make the thumbnails of the original at $path (absolute, under `{store}/attachments/`): ['width', 'height', 'thumbnails' => [size => absolute
 * path], 'none' => why there is no preview, or null]. width/height are the original's (a PDF's: its first page as rendered; an SVG's: its
 * viewBox). Throws nothing for a bad file: a file that will not decode answers none.
 */
function make_thumbnails(string $path, string $mime): array
{
    if ($mime === 'image/svg+xml') {
        [$w, $h] = svg_size($path);
        return ['width' => $w, 'height' => $h, 'thumbnails' => [], 'none' => null];
    }
    if ($mime === 'application/pdf') {
        return pdf_thumbnails($path);
    }
    if (!in_array($mime, RASTER_TYPES, true)) {
        return ['width' => null, 'height' => null, 'thumbnails' => [], 'none' => 'not an image'];
    }
    $info = @getimagesize($path);
    if ($info === false || $info[0] < 1 || $info[1] < 1) {
        return ['width' => null, 'height' => null, 'thumbnails' => [], 'none' => 'the image does not decode'];
    }
    if ($info[0] * $info[1] > THUMB_MAX_PIXELS) {
        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'thumbnails' => [], 'none' => 'too large to preview'];
    }
    $src = match ($mime) {
        'image/png' => @imagecreatefrompng($path),
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/gif' => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
    };
    if (!$src instanceof GdImage) {
        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'thumbnails' => [], 'none' => 'the image does not decode'];
    }
    if (!imageistruecolor($src)) {
        imagepalettetotruecolor($src);                       // a GIF's (or a palette PNG's) transparent index becomes alpha
    }
    if ($mime === 'image/jpeg') {
        $src = jpeg_upright($src, $path);
    }
    [$out, $ext] = match ($mime) {
        'image/jpeg' => ['jpeg', 'jpg'],
        'image/webp' => ['webp', 'webp'],
        default => ['png', 'png'],
    };
    $thumbs = raster_sizes($src, $path, $out, $ext);
    $w = imagesx($src);
    $h = imagesy($src);
    imagedestroy($src);
    return ['width' => $w, 'height' => $h, 'thumbnails' => $thumbs, 'none' => $thumbs === [] ? 'the thumbnails could not be written' : null];
}

/** The three sizes of a decoded image, each from the next larger (a size the image does not reach shares one copy at its own size). */
function raster_sizes(GdImage $src, string $origAbs, string $format, string $ext): array
{
    $w = imagesx($src);
    $h = imagesy($src);
    $long = max($w, $h);
    $thumbs = [];
    $prev = $src;
    $full = null;                                           // the one copy at the original's size, shared by the sizes it does not reach
    foreach (array_reverse(THUMB_SIZES) as $size) {         // 2048, 1024, 256: each resampled from the last
        $dest = thumb_abs($origAbs, $size, $ext);
        if ($long <= $size) {
            if ($full === null) {
                $full = thumb_abs($origAbs, $size, $ext);
                if (!gd_write(gd_copy_scaled($src, $w, $h), $full, $format)) {
                    return [];
                }
            }
            $thumbs[$size] = $full;
            continue;
        }
        $k = $size / $long;
        $tw = max(1, (int) round($w * $k));
        $th = max(1, (int) round($h * $k));
        $img = gd_copy_scaled($prev, $tw, $th);
        if (!gd_write($img, $dest, $format)) {
            return [];
        }
        if ($prev !== $src) {
            imagedestroy($prev);
        }
        $prev = $img;
        $thumbs[$size] = $dest;
    }
    if ($prev !== $src) {
        imagedestroy($prev);
    }
    ksort($thumbs);
    return $thumbs;
}

/** A resampled truecolor copy with its alpha kept. */
function gd_copy_scaled(GdImage $src, int $w, int $h): GdImage
{
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    return $dst;
}

function gd_write(GdImage $img, string $abs, string $format): bool
{
    files_mkdir(dirname($abs));
    $ok = match ($format) {
        'jpeg' => imagejpeg($img, $abs, 82),
        'webp' => imagewebp($img, $abs, 80),
        default => imagepng($img, $abs, 6),
    };
    return $ok && is_file($abs);
}

/** A thumbnail's absolute path beside the original: `{store}/attachments/…/x.jpg` → `{store}/thumbnails/…/x-{size}.{ext}`. */
function thumb_abs(string $origAbs, int $size, string $ext): string
{
    $at = strrpos($origAbs, '/attachments/');
    if ($at === false) {
        throw new RuntimeException('An original lives under attachments/.');
    }
    return substr($origAbs, 0, $at) . '/' . file_thumb_path(substr($origAbs, $at + 1), $size, $ext);
}

/** A JPEG turned the way its EXIF orientation says (what the browser shows), mirrored variants too. */
function jpeg_upright(GdImage $img, string $path): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $o = (int) ((@exif_read_data($path, 'IFD0') ?: [])['Orientation'] ?? 1);
    $turn = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;   // imagerotate turns counter-clockwise
    if (in_array($o, [2, 4, 5, 7], true)) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    if ($turn !== 0) {
        $r = imagerotate($img, $turn, 0);
        if ($r instanceof GdImage) {
            imagedestroy($img);
            $img = $r;
        }
    }
    return $img;
}

/** An SVG's size from its root: the viewBox's, else width/height in user units, else 800 × 600 (clamped to 1–20000). */
function svg_size(string $path): array
{
    $head = (string) file_get_contents($path, false, null, 0, 65536);
    if (preg_match('/<svg\b[^>]*>/is', $head, $m)) {
        $tag = $m[0];
        if (preg_match('/\bviewBox\s*=\s*["\']\s*[-0-9.e]+[\s,]+[-0-9.e]+[\s,]+([0-9.e]+)[\s,]+([0-9.e]+)\s*["\']/i', $tag, $v) && (float) $v[1] > 0 && (float) $v[2] > 0) {
            return [svg_clamp((float) $v[1]), svg_clamp((float) $v[2])];
        }
        if (preg_match('/\bwidth\s*=\s*["\']\s*([0-9.]+)(px)?\s*["\']/i', $tag, $a) && preg_match('/\bheight\s*=\s*["\']\s*([0-9.]+)(px)?\s*["\']/i', $tag, $b)
            && (float) $a[1] > 0 && (float) $b[1] > 0) {
            return [svg_clamp((float) $a[1]), svg_clamp((float) $b[1])];
        }
    }
    return [800, 600];
}

function svg_clamp(float $v): int
{
    return max(1, min(20000, (int) round($v)));
}

/** A PDF's first page → the 2048 PNG by pdftoppm (20 s, no shell), then 1024 and 256 from it. A PDF that will not render: none (a card). */
function pdf_thumbnails(string $path): array
{
    $none = static fn (string $why): array => ['width' => null, 'height' => null, 'thumbnails' => [], 'none' => $why];
    $bin = is_executable('/usr/bin/pdftoppm') ? '/usr/bin/pdftoppm' : null;
    if ($bin === null) {
        return $none('pdftoppm is not installed');
    }
    $big = thumb_abs($path, 2048, 'png');
    files_mkdir(dirname($big));
    $prefix = substr($big, 0, -4);                          // pdftoppm -singlefile writes {prefix}.png
    $cmd = ['/usr/bin/timeout', '--kill-after=2', (string) PDF_RENDER_SECONDS, $bin, '-png', '-f', '1', '-l', '1', '-scale-to', '2048', '-singlefile', $path, $prefix];
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return $none('the page could not be rendered');
    }
    $rc = proc_close($proc);
    if ($rc !== 0 || !is_file($big)) {
        @unlink($big);
        return $none($rc === 124 ? 'the page took too long to render' : 'the page could not be rendered');
    }
    $src = @imagecreatefrompng($big);
    if (!$src instanceof GdImage) {
        @unlink($big);
        return $none('the page could not be rendered');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $thumbs = [2048 => $big];
    $prev = $src;
    foreach ([1024, 256] as $size) {
        $k = $size / max($w, $h);
        $img = gd_copy_scaled($prev, max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        $dest = thumb_abs($path, $size, 'png');
        if (!gd_write($img, $dest, 'png')) {
            return $none('the thumbnails could not be written');
        }
        if ($prev !== $src) {
            imagedestroy($prev);
        }
        $prev = $img;
        $thumbs[$size] = $dest;
    }
    imagedestroy($prev);
    if ($prev !== $src) {
        imagedestroy($src);
    }
    ksort($thumbs);
    return ['width' => $w, 'height' => $h, 'thumbnails' => $thumbs, 'none' => null];
}
