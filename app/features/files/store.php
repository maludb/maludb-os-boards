<?php
declare(strict_types=1);

/**
 * The file store (slice 5; docs/build-specs/pen-files.md "The data and the rules"): what Boards keeps — decided by `finfo` over the bytes,
 * never the name —, where it keeps it (`{store}/attachments/{yyyy}/{mm}/{attachment id}-{sha256 first 12}.{ext}`, thumbnails beside under
 * `{store}/thumbnails/…`), and the one way a file leaves the disk: unlink_paths(), which removes only what resolves inside the store.
 * Every `storage_path` in `attachments` is RELATIVE to the store (save_thumbnail()'s convention), so a moved store moves every file at once.
 */

require_once dirname(__DIR__) . '/canvas/write.php';     // bd_storage_dir()

/** The types Boards keeps: mime (as finfo names it) → [kind, extension]. Office files are cards (decision 3). */
const FILE_TYPES = [
    'image/png'     => ['image', 'png'],
    'image/jpeg'    => ['image', 'jpg'],
    'image/gif'     => ['image', 'gif'],
    'image/webp'    => ['image', 'webp'],
    'image/svg+xml' => ['image', 'svg'],
    'application/pdf' => ['document', 'pdf'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => ['document', 'docx'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => ['document', 'xlsx'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', 'pptx'],
    'application/vnd.oasis.opendocument.text'         => ['document', 'odt'],
    'application/vnd.oasis.opendocument.spreadsheet'  => ['document', 'ods'],
    'application/vnd.oasis.opendocument.presentation' => ['document', 'odp'],
    'text/plain'      => ['document', 'txt'],
    'text/csv'        => ['document', 'csv'],
    'text/markdown'   => ['document', 'md'],
    'application/zip' => ['document', 'zip'],
];

/** Raster images GD thumbnails (the SVG is drawn by the browser, never rasterised here). */
const RASTER_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/** The three thumbnail sizes — the longest side, in pixels (canvas-core "Rendering and performance": level of detail). */
const THUMB_SIZES = [256, 1024, 2048];

/**
 * The type of an uploaded file from its bytes: [mime, kind, ext] or null when Boards does not keep it. finfo's answers are normalised where it
 * names one format several ways: an SVG may come back `image/svg` or as text with an `<svg` root; a CSV or Markdown file is `text/plain` to
 * libmagic, so the client's extension chooses between txt / csv / md — only among text types, never to make text an image; an Office file
 * libmagic reads as a bare zip is told by its name the same way (a zip stays a zip otherwise).
 */
function file_type_of(string $path, string $clientName): ?array
{
    $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));
    $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
    if ($mime === 'image/svg' || (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true) && svg_root($path))) {
        $mime = 'image/svg+xml';
    } elseif ($mime === 'text/plain' || $mime === 'text/x-csv' || $mime === 'application/csv') {
        $mime = ['csv' => 'text/csv', 'md' => 'text/markdown', 'markdown' => 'text/markdown'][$ext] ?? ($mime === 'text/plain' ? 'text/plain' : 'text/csv');
    } elseif ($mime === 'application/zip' || $mime === 'application/octet-stream') {
        $office = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                   'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        if (isset($office[$ext]) && zip_has_office_parts($path)) {
            $mime = $office[$ext];
        }
    }
    return isset(FILE_TYPES[$mime]) ? [$mime, FILE_TYPES[$mime][0], FILE_TYPES[$mime][1]] : null;
}

/** The type's words for a refusal: "Boards keeps images and documents, not {type}". */
function file_type_words(string $path): string
{
    $m = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return $m === '' ? 'this kind of file' : $m;
}

/** An XML document whose root element is <svg> (the first 4 kB, past a declaration, comments and a doctype). */
function svg_root(string $path): bool
{
    $head = (string) file_get_contents($path, false, null, 0, 4096);
    $head = preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head;
    $head = preg_replace('/<\?xml.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>/is', '', $head) ?? $head;
    return (bool) preg_match('/^\s*<svg[\s>]/i', $head);
}

/** A zip holding `[Content_Types].xml` — an Office Open XML package, not any zip renamed. */
function zip_has_office_parts(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return str_contains((string) file_get_contents($path, false, null, 0, 65536), '[Content_Types].xml');
    }
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $ok = $z->locateName('[Content_Types].xml') !== false;
    $z->close();
    return $ok;
}

/** The original's place: [relative path, absolute path] — `attachments/{yyyy}/{mm}/{id}-{sha12}.{ext}`, the directory made. */
function file_original_path(PDO $pdo, int $attachmentId, string $sha256, string $ext, ?DateTimeImmutable $at = null): array
{
    $at ??= new DateTimeImmutable();
    $rel = 'attachments/' . $at->format('Y') . '/' . $at->format('m') . '/' . $attachmentId . '-' . substr($sha256, 0, 12) . '.' . $ext;
    $abs = bd_storage_dir($pdo) . '/' . $rel;
    files_mkdir(dirname($abs));
    return [$rel, $abs];
}

/** The thumbnail beside an original: `thumbnails/{yyyy}/{mm}/{id}-{sha12}-{size}.{ext}` for `attachments/{yyyy}/{mm}/{id}-{sha12}.*`. */
function file_thumb_path(string $originalRel, int $size, string $ext): string
{
    $base = preg_replace('/\.[a-z0-9]+$/', '', $originalRel) ?? $originalRel;
    return preg_replace('#^attachments/#', 'thumbnails/', $base) . '-' . $size . '.' . $ext;
}

function files_mkdir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('The file store cannot be written.');
    }
}

/** A stored path (relative to the store) → its absolute path, or null when it would resolve outside the store. */
function file_abs(PDO $pdo, string $rel): ?string
{
    $store = bd_storage_dir($pdo);
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
        return null;
    }
    $abs = $store . '/' . $rel;
    $real = realpath($abs);
    $root = realpath($store);
    if ($real === false || $root === false || !str_starts_with($real, $root . '/')) {
        return null;
    }
    return $real;
}

/**
 * Unlink files the database answered (bd_purge_deleted_items(), an orphan's row) — ONLY those that resolve inside the store; anything else
 * is logged and left. Relative paths are taken against the store; duplicates (a small image's sizes share one file) count once. Answers how
 * many files were removed. Needs the store; the PDO is only for its place.
 */
function unlink_paths(array $paths, ?PDO $pdo = null): int
{
    $store = $pdo !== null ? bd_storage_dir($pdo) : rtrim((string) env('BD_STORAGE_DIR', APP_ROOT . '/storage'), '/');
    $root = realpath($store);
    if ($root === false) {
        return 0;
    }
    $n = 0;
    foreach (array_unique(array_filter(array_map('strval', $paths), static fn (string $p): bool => $p !== '')) as $p) {
        $abs = $p[0] === '/' ? $p : $store . '/' . ltrim($p, '/');
        $real = realpath($abs);
        if ($real === false) {
            continue;                                        // already gone
        }
        if (!str_starts_with($real, $root . '/') || !is_file($real)) {
            error_log('unlink_paths: left ' . $p . ' — outside the store');
            continue;
        }
        if (@unlink($real)) {
            $n++;
        }
    }
    return $n;
}
