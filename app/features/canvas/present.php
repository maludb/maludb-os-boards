<?php
declare(strict_types=1);

/**
 * The canvas's presenters (canvas-core.md "The island and the page"): the mount's data-* attributes — everything the island reads, and
 * nothing it decides — the screen's JSON whitelist, and the bundle's file names from html/assets/boards/manifest.json.
 */

require_once dirname(__DIR__) . '/items/write.php';
require_once dirname(__DIR__, 2) . '/live.php';
require_once dirname(__DIR__) . '/boards/queries.php';          // BOARD_COLORS — a frame's fills are the theme's eight
require_once dirname(__DIR__) . '/items/connectors.php';

/** The committed bundle (client/build.mjs): ['js' => '/assets/boards/boards.<hash>.js', 'css' => …] or null when it was never built. */
function canvas_bundle(): ?array
{
    $m = json_decode((string) @file_get_contents(APP_ROOT . '/html/assets/boards/manifest.json'), true);
    if (!is_array($m) || empty($m['file'])) {
        return null;
    }
    return ['js' => '/assets/boards/' . $m['file'], 'css' => empty($m['css']) ? null : '/assets/boards/' . $m['css']];
}

/** A view from the query string (?x=&y=&zoom=) when all three are numbers and the zoom is in the island's range; else null. */
function canvas_query_view(): ?array
{
    $x = $_GET['x'] ?? null;
    $y = $_GET['y'] ?? null;
    $z = $_GET['zoom'] ?? null;
    if (!is_numeric($x) || !is_numeric($y) || !is_numeric($z) || (float) $z < 0.05 || (float) $z > 8) {
        return null;
    }
    return ['x' => (float) $x, 'y' => (float) $y, 'zoom' => (float) $z];
}

/** The mount's attributes (canvas-core's list). $b: board_for_canvas(). $level: the caller's. */
function canvas_mount(array $b, string $level, array $me): array
{
    $id = (string) $b['board_id'];
    $s = one_row(db(), 'SELECT poll_seconds, item_soft_budget, item_hard_cap, batch_max_ops, live_enabled FROM bd_settings WHERE id = 1') ?? [];
    $item = isset($_GET['item']) && preg_match('/^[0-9a-f-]{36}$/', (string) $_GET['item']) ? (string) $_GET['item'] : '';
    $comment = isset($_GET['comment']) && preg_match('/^[0-9a-f-]{36}$/', (string) $_GET['comment']) ? (string) $_GET['comment'] : '';
    $view = canvas_query_view();
    $attrs = [
        'id' => 'board-canvas', 'data-board' => $id, 'data-title' => (string) $b['title'], 'data-level' => $level,
        'data-locked' => $b['is_locked'] ? '1' : '0', 'data-last-seq' => (string) (int) $b['last_seq'],
        'data-member' => (string) (int) $me['id'], 'data-member-name' => (string) $me['display_name'], 'data-client' => uuid_v4(),
        'data-csrf' => csrf_token(), 'data-live-url' => '/live',
        'data-state-url' => '/boards/' . $id . '/state', 'data-ops-url' => '/boards/' . $id . '/ops', 'data-ticket-url' => '/boards/' . $id . '/live-ticket',
        'data-thumbnail-url' => '/boards/' . $id . '/thumbnail', 'data-export-url' => '/boards/' . $id . '/export',
        'data-upload-url' => '/files/upload.php', 'data-file-url' => '/files/', 'data-rail-url' => '/boards/' . $id . '/rail',
        'data-poll-seconds' => (string) (int) ($s['poll_seconds'] ?? 3), 'data-soft-budget' => (string) (int) ($s['item_soft_budget'] ?? 4000),
        'data-hard-cap' => (string) (int) ($s['item_hard_cap'] ?? 10000), 'data-batch-max' => (string) (int) ($s['batch_max_ops'] ?? 500),
        'data-start-view' => $b['start_view'] === null ? '' : json_encode($b['start_view']),
        'data-view' => $view === null ? '' : $view['x'] . ' ' . $view['y'] . ' ' . $view['zoom'],
        'data-focus-item' => $item, 'data-focus-comment' => $comment,
        'data-background' => json_encode($b['background']),
        'data-thumbnail-age' => $b['thumbnail_age'] === null ? '' : (string) $b['thumbnail_age'],
    ];
    // the live relay's 60-second, single-use ticket (live.md) — absent when live updates are off: the island polls from the start
    if (live_enabled(db())) {
        $attrs['data-ticket'] = bd_live_ticket((int) $me['id'], $id, $level);
    }
    // the proofs' test hook: ONLY when tests/dev_router.php defined BD_TEST_HOOKS, and never in production (canvas-core decision 8)
    if (defined('BD_TEST_HOOKS') && !app_is_prod()) {
        $attrs['data-test'] = '1';
    }
    return $attrs;
}

/** Attributes as HTML (every value escaped). */
function html_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $k => $v) {
        $out .= ' ' . $k . '="' . e((string) $v) . '"';
    }
    return $out;
}

/** board-view's JSON (a whitelist): the board, my level, where its state and changes are read. */
function present_canvas(array $b): array
{
    $id = (string) $b['board_id'];
    return ['board_id' => $id, 'title' => (string) $b['title'], 'my_level' => (string) $b['my_level'], 'is_locked' => (bool) $b['is_locked'],
            'item_count' => (int) $b['item_count'], 'last_seq' => (int) $b['last_seq'], 'background' => $b['background'], 'start_view' => $b['start_view'],
            'url' => '/boards/' . $id, 'state_url' => '/boards/' . $id . '/state', 'ops_url' => '/boards/' . $id . '/ops', 'outline_url' => '/boards/' . $id . '/outline'];
}

/** What a selection is, for the Properties panel: its types, whether all are locked, the sticky colour / fill / text colour / size they share. */
function rail_summary(array $rows): array
{
    $types = array_values(array_unique(array_map(static fn ($r): string => (string) $r['type'], $rows)));
    $shared = static function (array $rows, callable $f) {
        $vals = array_values(array_unique(array_map(static fn ($r) => (string) ($f($r) ?? ''), $rows)));
        return count($vals) === 1 ? $vals[0] : null;
    };
    $stickies = array_values(array_filter($rows, static fn ($r): bool => $r['type'] === 'sticky_note'));
    $fills = array_values(array_filter($rows, static fn ($r): bool => $r['type'] === 'shape'));
    $texts = array_values(array_filter($rows, static fn ($r): bool => $r['type'] === 'text'));
    $sized = array_values(array_filter($rows, static fn ($r): bool => in_array($r['type'], ITEM_TYPES_DRAWN, true)));
    return [
        'types' => $types,
        'count' => count($rows),
        'all_locked' => $rows !== [] && count(array_filter($rows, static fn ($r): bool => (bool) $r['locked'])) === count($rows),
        'sticky_color' => $stickies === [] ? null : ($shared($stickies, static fn ($r) => $r['style']['fillColor'] ?? 'light_yellow') ?? ''),
        'fill' => $fills === [] ? null : ($shared($fills, static fn ($r) => $r['style']['fillColor'] ?? '#ffffff') ?? ''),
        'text_color' => $texts === [] ? null : ($shared($texts, static fn ($r) => $r['style']['textColor'] ?? '#1a1a1a') ?? ''),
        'font_size' => $sized === [] ? null : ($shared($sized, static fn ($r) => isset($r['style']['fontSize']) ? (string) (int) $r['style']['fontSize'] : '') ?? ''),
        'align' => $sized === [] ? null : ($shared($sized, static fn ($r) => $r['style']['textAlign'] ?? ($r['type'] === 'text' ? 'left' : 'center')) ?? ''),
        'has_text' => $sized !== [],
        'connector' => ($k = array_values(array_filter($rows, static fn ($r): bool => $r['type'] === 'connector'))) === [] ? null : [
            'shape' => $shared($k, static fn ($r) => $r['data']['shape'] ?? 'elbowed'),
            'style' => $shared($k, static fn ($r) => $r['style']['strokeStyle'] ?? 'normal'),
            'width' => $shared($k, static fn ($r) => (string) (int) ($r['style']['strokeWidth'] ?? 2)),
            'color' => $shared($k, static fn ($r) => $r['style']['strokeColor'] ?? '#1a1a1a'),
            'start' => $shared($k, static fn ($r) => $r['style']['startStrokeCap'] ?? 'none'),
            'end' => $shared($k, static fn ($r) => $r['style']['endStrokeCap'] ?? 'arrow'),
        ],
        'frame' => ($f = array_values(array_filter($rows, static fn ($r): bool => $r['type'] === 'frame'))) === [] ? null : [
            'fill' => $shared($f, static fn ($r) => $r['style']['fillColor'] ?? '#ffffff'),
            'title' => count($f) === 1 ? (string) ($f[0]['data']['title'] ?? '') : null,
        ],
    ];
}

/** "Sticky note", "Shape: ellipse", "Text", "Frame" — a type in words. */
function item_type_words(array $r): string
{
    return match ((string) $r['type']) {
        'sticky_note' => 'Sticky note',
        'shape' => 'Shape: ' . str_replace('_', ' ', (string) ($r['data']['shape'] ?? 'rectangle')),
        'text' => 'Text',
        'document' => 'File',
        default => ucfirst((string) $r['type']),
    };
}
