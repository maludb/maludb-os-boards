<?php
declare(strict_types=1);
/** GET /api/v1/health — unauthenticated; the kernel's health check and the installer's monitor read it. */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
api_cors();
api_require_get();
$out = ['ok' => true, 'application' => app_key(), 'version' => (string) (json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['version'] ?? ''), 'database' => 'ok', 'maludb' => 'unconfigured', 'ingest_lag' => null, 'directory' => null];
try {
    $pdo = db();
    $lag = $pdo->query('SELECT (SELECT COALESCE(max(id), 0) FROM activity_log) - (SELECT last_id FROM activity_ingest_state WHERE id = 1)')->fetchColumn();
    $out['ingest_lag'] = (int) $lag;
    $sync = $pdo->query('SELECT last_run_at, last_error, next_cursor FROM directory_sync_state WHERE id = 1')->fetch();
    $out['directory'] = ['last_run_at' => json_ts($sync['last_run_at'] ?? null), 'synced' => ($sync['next_cursor'] ?? null) !== null, 'error' => $sync['last_error'] ?? null];
} catch (Throwable $e) {
    error_log('health: ' . $e->getMessage());
    $out['ok'] = false;
    $out['database'] = 'error';
}
if ((string) env('MALUDB_API_URL', '') !== '' && (string) env('MALUDB_API_TOKEN', '') !== '') {
    $out['maludb'] = 'ok';
}
// the attachment store (design §14: BD_STORAGE_DIR, default storage/ under the application): images, files and thumbnails, written by PHP.
$dir = (string) (env('BD_STORAGE_DIR', '') ?: 'storage');
$dir = rtrim($dir[0] === '/' ? $dir : APP_ROOT . '/' . $dir, '/');
$out['storage'] = is_dir($dir) && is_writable($dir) ? 'ok' : 'missing';
// the live relay (design §8): informational — with the relay down every board polls ops?after= and stays editable, so it never fails health.
$livePort = (int) env('BOARDS_LIVE_PORT', '0');
if ($livePort > 0) {
    $sock = @fsockopen('127.0.0.1', $livePort, $errno, $errstr, 0.3);
    $out['live'] = $sock === false ? 'down' : 'ok';
    if ($sock !== false) {
        fclose($sock);
    }
} else {
    $out['live'] = 'unconfigured';
}
api_json($out, $out['ok'] ? 200 : 503);
