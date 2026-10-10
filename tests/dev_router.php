<?php
/**
 * Router for `php -S 127.0.0.1:9101 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-boards.conf), so the proofs run
 * without Apache: /sso, /sso/logout, /api/v1/health and the token API (/api/v1/{name}[/{id}[/{do}]] → api/v1/{name}.php with id/do);
 * a board by its uuid — the canvas /boards/{board} → boards/view.php and its pages and doors /boards/{board}/{page} → boards/{page}.php
 * (settings, share, history, outline, comments, ops, state, live-ticket, thumbnail, files; `board` in $_GET), a template's preview
 * /templates/{board} → templates/view.php; a page of a numbered record (/folders/12/edit) and the canonical URLs (/x/new, /x/{id}/edit,
 * /x/{id}, /x → x.php or x/index.php). /live is the relay's (Apache proxies it; never PHP's) and answers 404 here.
 * A rewrite to a file its slice has not built yet answers 404, as Apache does. Anything that is a real file is served as it is.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
if (isset($map[$rel])) { $target = $map[$rel]; }
elseif (preg_match('#^/api/v1/([a-z_]+)/([0-9]+)(?:/([a-z_]+))?$#', $path, $m)) { $set(['id' => $m[2], 'do' => $m[3] ?? '']); $target = '/api/v1/' . $m[1] . '.php'; }
elseif (preg_match('#^/api/v1/([a-z_]+)(?:/([a-z_]+))?$#', $path, $m)) { $target = '/api/v1/' . $m[1] . (isset($m[2]) ? '/' . $m[2] : '') . '.php'; }
elseif (str_starts_with($path, '/api/')) { http_response_code(404); echo 'Not found'; return true; }
elseif (preg_match('#^/boards/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#', $path, $m)) { $set(['board' => $m[1]]); $target = '/boards/view.php'; }
elseif (preg_match('#^/boards/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/([a-z][a-z-]*)$#', $path, $m)) { $set(['board' => $m[1]]); $target = '/boards/' . $m[2] . '.php'; }
elseif (preg_match('#^/templates/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#', $path, $m)) { $set(['board' => $m[1]]); $target = '/templates/view.php'; }
elseif ($path === '/live' || str_starts_with($path, '/live/')) { http_response_code(404); echo 'Not found'; return true; }
elseif (preg_match('#^/([a-z_-]+)/new$#', $path, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)/edit$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/view.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)/([a-z_-]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/' . $m[3] . '.php'; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
