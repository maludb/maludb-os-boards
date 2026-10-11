<?php
declare(strict_types=1);
/**
 * Is the committed canvas bundle the build of the committed sources? (canvas-core.md "client/ — files and build".) The same source_hash
 * client/build.mjs writes: sha256 over every file of client/src/ sorted by path (each as "path NUL content NUL", the path from the
 * repository's root) followed by client/package-lock.json. Exits 1 — saying why — when html/assets/boards/manifest.json's source_hash
 * differs, or the files it names are missing; 0 when the bundle is current. Every slice's proof runs it; Node is a development tool and the
 * installer never builds.   php bin/check_bundle.php
 */
$root = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/client/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $files[] = substr($f->getPathname(), strlen($root) + 1);
    }
}
sort($files, SORT_STRING);
$ctx = hash_init('sha256');
foreach ($files as $rel) {
    hash_update($ctx, $rel . "\0" . file_get_contents($root . '/' . $rel) . "\0");
}
hash_update($ctx, (string) file_get_contents($root . '/client/package-lock.json'));
$hash = hash_final($ctx);
$m = json_decode((string) @file_get_contents($root . '/html/assets/boards/manifest.json'), true);
if (!is_array($m)) {
    fwrite(STDERR, "html/assets/boards/manifest.json is missing: run node client/build.mjs\n");
    exit(1);
}
foreach (['file', 'css'] as $k) {
    if (empty($m[$k]) || !is_file($root . '/html/assets/boards/' . $m[$k])) {
        fwrite(STDERR, "the bundle's $k (" . ($m[$k] ?? 'none') . ") is missing: run node client/build.mjs\n");
        exit(1);
    }
}
if (($m['source_hash'] ?? '') !== $hash) {
    fwrite(STDERR, "the bundle is stale: client/src or the lock changed since it was built (manifest " . substr((string) ($m['source_hash'] ?? ''), 0, 12)
        . ", sources " . substr($hash, 0, 12) . "): run node client/build.mjs and commit html/assets/boards/\n");
    exit(1);
}
echo 'the bundle is current: ' . $m['file'] . ', ' . $m['css'] . ' (sources ' . substr($hash, 0, 12) . ")\n";
