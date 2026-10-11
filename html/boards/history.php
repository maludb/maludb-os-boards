<?php
declare(strict_types=1);
/**
 * /boards/{board}/history?version=&member=&source=&since=&page= — screen `board-history` (history-versions.md): Changes (mcp_board_changes, newest first, paged 50, by day, "Since your last visit"),
 * Versions (mcp_board_versions; Save this moment for an editor, Preview, Restore for an owner) and, to an editor, Visits. `?version=N` opens that version's outline above them. With
 * `?part=changes&page=N` (HTMX) only the next page of changes. Level view to read. Logs screen.view with the board (and the version previewed). JSON: the same, whitelisted.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/history/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/history/present.php';
require_login();
$pdo = db();
$gate = bd_board_or_404($pdo, isset($_GET['board']) ? (string) $_GET['board'] : null, 'view');
$id = (string) $gate['board_id'];
$level = (string) $gate['my_level'];
$member = isset($_GET['member']) && preg_match('/^\d{1,18}$/', (string) $_GET['member']) ? (int) $_GET['member'] : null;
$source = isset($_GET['source']) && in_array((string) $_GET['source'], CHANGE_SOURCES, true) ? (string) $_GET['source'] : null;
$since = isset($_GET['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['since']) && strtotime((string) $_GET['since']) !== false ? (string) $_GET['since'] : null;
$f = ['member' => $member, 'source' => $source, 'since' => $since];
$page = max(1, (int) ($_GET['page'] ?? 1));
$tz = member_timezone();
$r = board_changes($pdo, $id, $f, $page);
$q = static fn (array $over): string => '/boards/' . $id . '/history' . (($p = array_filter($over, static fn ($v) => $v !== null && $v !== '')) ? '?' . http_build_query($p) : '');
if (is_htmx_request() && ($_GET['part'] ?? '') === 'changes' && !wants_json()) {
    echo view('history/partials/change-list.php', ['b' => $gate, 'rows' => $r['rows'], 'last_visit' => $since === null ? $r['last_visit'] : null, 'tz' => $tz, 'page' => $page, 'total' => $r['total'],
        'f' => $f, 'q' => $q, 'keep' => $f]);
    exit;
}
$preview = null;
$no = isset($_GET['version']) && preg_match('/^\d{1,9}$/', (string) $_GET['version']) ? (int) $_GET['version'] : null;
if (isset($_GET['version']) && $no === null) {
    refuse(404, 'There is no such version.');
}
if ($no !== null) {
    $vh = find_version($pdo, $id, $no);
    $md = $vh === null ? null : version_outline($pdo, $id, $no);
    if ($vh === null || $md === null) {
        refuse(404, 'There is no such version.');
    }
    $preview = [$vh, $md];
}
$versions = board_versions($pdo, $id, 200);
$visits = bd_level_at_least($level, 'edit') ? board_trail($pdo, $id, null, 100) : null;
if ($no !== null && (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1')) {        // log_screen_view()'s rule, with the version previewed
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'board-history', 'board_id' => $id, 'after' => ['version' => $no]]);
} elseif ($no === null) {
    log_screen_view($pdo, 'board-history', $id);
}
if (wants_json()) {
    respond_screen(['board_id' => $id, 'title' => (string) $gate['title'], 'level' => $level, 'total' => $r['total'], 'page' => $page, 'last_visit' => json_ts($r['last_visit']),
        'rows' => array_map(static fn (array $c): array => present_change($c, $id), $r['rows']),
        'versions' => array_map('present_version', $versions),
        'preview' => $preview === null ? null : ['version' => present_version($preview[0]), 'outline' => $preview[1]],
        'visits' => $visits === null ? null : array_map('present_visit', $visits)]);
}
$notice = bd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved. Find it under Versions.'], 'restored' => ['success', 'Restored. The board as it was is saved as a version, so nothing is lost.'],
    'nothing' => ['info', 'Nothing to restore: the board already matches that version.']]);
render_screen((string) $gate['title'] . ' · History', view('history/page.php', ['b' => $gate, 'level' => $level, 'rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'f' => $f,
    'last_visit' => $r['last_visit'], 'versions' => $versions, 'preview' => $preview === null ? null : [$preview[0], outline_html($preview[1])], 'visits' => $visits, 'notice' => $notice, 'tz' => $tz]),
    ['activeNav' => 'boards', 'screen' => 'board-history', 'entity' => 'board', 'recordId' => $id]);
