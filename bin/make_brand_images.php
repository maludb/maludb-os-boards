<?php
declare(strict_types=1);
/** Draws Boards' brand images with GD (the logo, the abbreviated mark, the favicon, the PWA icons): php bin/make_brand_images.php. Rerun after changing the mark. */
$dir = dirname(__DIR__) . '/html/assets/images';
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$indigo = [55, 88, 230];
function mark(int $size, bool $rounded = true): GdImage
{
    global $indigo;
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, ...$indigo);
    $r = $rounded ? (int) round($size * 0.22) : 0;
    imagefilledrectangle($im, $r, 0, $size - 1 - $r, $size - 1, $bg);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - 1 - $r, $bg);
    foreach ([[$r, $r], [$size - 1 - $r, $r], [$r, $size - 1 - $r], [$size - 1 - $r, $size - 1 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
    }
    // a board: two sticky notes joined by a connector — the canvas, the items, the link between them
    $yellow = imagecolorallocate($im, 255, 214, 64);
    $white = imagecolorallocate($im, 255, 255, 255);
    $s = (int) round($size * 0.30);                                  // a sticky's side
    $ax = (int) round($size * 0.17); $ay = (int) round($size * 0.20);   // the first sticky, top left
    $bx = (int) round($size * 0.53); $by = (int) round($size * 0.50);   // the second, bottom right
    $t = max(2, (int) round($size * 0.05));
    imagesetthickness($im, $t);
    $mx = $bx + (int) ($s / 2);                                      // an elbowed connector: right from A, then down into B
    imageline($im, $ax + $s, $ay + (int) ($s / 2), $mx, $ay + (int) ($s / 2), $white);
    imageline($im, $mx, $ay + (int) ($s / 2), $mx, $by, $white);
    imagesetthickness($im, 1);
    imagefilledrectangle($im, $ax, $ay, $ax + $s, $ay + $s, $yellow);
    imagefilledrectangle($im, $bx, $by, $bx + $s, $by + $s, $white);
    return $im;
}
foreach (['favicon.png' => 64, 'logo-abbr.png' => 128, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
    $im = mark($size, $file !== 'apple-touch-icon.png');
    if ($file === 'apple-touch-icon.png') {                                   // iOS rounds it itself: an opaque square
        $flat = imagecreatetruecolor($size, $size);
        imagefill($flat, 0, 0, imagecolorallocate($flat, ...$indigo));
        imagecopy($flat, $im, 0, 0, 0, 0, $size, $size);
        $im = $flat;
    }
    imagepng($im, $dir . '/' . $file);
}
// the full logo: the mark and the name
$w = 420; $h = 90;
$im = imagecreatetruecolor($w, $h);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
$m = mark(70);
imagecopy($im, $m, 4, 10, 0, 0, 70, 70);
$dark = imagecolorallocate($im, 33, 37, 41);
imagettftext($im, 21, 0, 86, 55, $dark, $font, 'Boards');
imagepng($im, $dir . '/logo-full.png');
echo "brand images drawn in $dir\n";
