<?php
declare(strict_types=1);
/**
 * Makes slice 5's fixtures (docs/build-specs/pen-files.md "Files"): php tests/fixtures/make_slice5.php — written once and committed; run again
 * only to change them. photo-3000x2000.jpg (GD), anim.gif (ffmpeg's test pattern, two frames), shape.webp (GD, an alpha channel), evil.svg (a
 * script and an onload that must never run), two-pages.pdf (written by hand: two pages of text), notes.docx (the smallest Word package).
 */
$dir = __DIR__;

// a 3000 × 2000 photograph's stand-in: a gradient, a horizon and circles (big enough to need all three thumbnails)
$im = imagecreatetruecolor(3000, 2000);
for ($y = 0; $y < 2000; $y += 4) {
    $c = imagecolorallocate($im, (int) (40 + 120 * $y / 2000), (int) (120 + 80 * $y / 2000), (int) (220 - 100 * $y / 2000));
    imagefilledrectangle($im, 0, $y, 2999, $y + 3, $c);
}
imagefilledrectangle($im, 0, 1300, 2999, 1999, imagecolorallocate($im, 70, 120, 60));
foreach ([[600, 500, 300, [250, 220, 90]], [2200, 700, 500, [240, 120, 60]], [1500, 1600, 400, [60, 60, 160]]] as [$x, $y, $r, $rgb]) {
    imagefilledellipse($im, $x, $y, $r, $r, imagecolorallocate($im, ...$rgb));
}
imagejpeg($im, "$dir/photo-3000x2000.jpg", 70);

// an animated GIF (two frames, 480 × 320)
@unlink("$dir/anim.gif");
exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=size=480x320:rate=2 -t 1 -loop 0 ' . escapeshellarg("$dir/anim.gif"), $o, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "ffmpeg failed: anim.gif not made\n");
}

// a WebP with transparency: a hexagon on nothing, 600 × 400
$im = imagecreatetruecolor(600, 400);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
imagefilledpolygon($im, [150, 40, 450, 40, 580, 200, 450, 360, 150, 360, 20, 200], imagecolorallocatealpha($im, 52, 84, 209, 20));
imagewebp($im, "$dir/shape.webp", 90);

// an SVG that tries to run: a script element and an onload — drawn as an image it is inert; opened directly the door sandboxes it
file_put_contents("$dir/evil.svg", <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300" onload="alert('onload ran'); console.log('evil onload')">
  <script type="text/javascript">alert('script ran'); console.log('evil script');</script>
  <rect x="20" y="20" width="360" height="260" rx="24" fill="#ffd43b" stroke="#1a1a1a" stroke-width="6"/>
  <text x="200" y="165" font-family="sans-serif" font-size="40" text-anchor="middle">Not a script</text>
</svg>
SVG);

// a two-page PDF, written by hand (text on each page; the xref offsets counted)
$objs = [];
$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
$objs[] = '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>';
foreach ([['Page one', 'The first page of two.'], ['Page two', 'The second page.']] as $k => [$h, $t]) {
    $stream = "BT /F1 36 Tf 72 700 Td ($h) Tj ET\nBT /F1 18 Tf 72 650 Td ($t) Tj ET\n0.2 0.33 0.82 rg 72 200 468 300 re f\n";
    $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 7 0 R >> >> /Contents ' . (4 + 2 * $k) . ' 0 R >>';
    $objs[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
}
$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pdf = "%PDF-1.4\n";
$off = [];
foreach ($objs as $i => $o) {
    $off[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
foreach ($off as $o) {
    $pdf .= sprintf("%010d 00000 n \n", $o);
}
$pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
file_put_contents("$dir/two-pages.pdf", $pdf);

// the smallest Word document Word opens
@unlink("$dir/notes.docx");
$z = new ZipArchive();
$z->open("$dir/notes.docx", ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Retro notes: what went well, what did not, what we change.</w:t></w:r></w:p></w:body></w:document>');
$z->close();

foreach (['photo-3000x2000.jpg', 'anim.gif', 'shape.webp', 'evil.svg', 'two-pages.pdf', 'notes.docx'] as $f) {
    printf("%-22s %8d bytes  %s\n", $f, is_file("$dir/$f") ? filesize("$dir/$f") : 0, is_file("$dir/$f") ? (new finfo(FILEINFO_MIME_TYPE))->file("$dir/$f") : 'MISSING');
}
