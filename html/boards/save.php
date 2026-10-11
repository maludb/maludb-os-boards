<?php
declare(strict_types=1);
/**
 * Actions `board_create` (no `board`; log board.create) and `board_update` (`board` + any of title, description, background, start_view; log
 * board.update — level edit). THE CRUD EXEMPLAR'S HANDLER ORDER: bd_handler_begin() → the right / the board gate (bd_board_or_404 at the
 * action's level) → the fields (a field left out stays as it was; an emptied one is cleared) → bd_guard() around the database's function →
 * log_activity() (field NAMES and short values — a description by its length) → bd_done().
 * create: **title** (≤ 200), description (≤ 2000), folder (one I may change). The right is the database's: bd_board_create refuses a Viewer
 * in its own words (403). update: title, description, background (dots | grid | none; with background_color one of the theme's eight),
 * start_view ("x y zoom" or none — or the form's start_view_x/_y/_zoom, all three blank for none). The lock is board_lock's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/boards/write.php';
bd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$errors = [];

if (!req_has('board') || (string) req_val('board') === '') {
    $title = bd_text('title', null, 200, 'The title', $errors, true);
    if ($title === null && !isset($errors['title'])) {
        $errors['title'] = 'The title is required.';
    }
    $description = bd_text('description', null, 2000, 'The description', $errors);
    $folder = bd_ref($pdo, 'folder', null, 'SELECT 1 FROM mcp_folders WHERE folder_id = :id', 'folder', $errors);
    if ($errors !== []) {
        bd_refuse_fields($errors);
    }
    $id = bd_guard($pdo, static function () use ($pdo, $me, $title, $description, $folder): string {
        $pdo->beginTransaction();
        $id = board_create($pdo, $me, $title, $description, $folder);
        log_activity($pdo, 'board.create', 'board', $id, ['board_id' => $id, 'after' => ['title' => $title, 'description' => $description === null ? null : ['length' => mb_strlen($description)],
            'folder_id' => $folder]]);
        $pdo->commit();
        return $id;
    });
    bd_done('Made the board ' . $title, $id, bd_land('/boards/' . $id), 'boardChanged');
}

$b = bd_board_or_404($pdo, (string) req_val('board'), 'edit');
$id = (string) $b['board_id'];
$cur = find_board($pdo, $id);
$f = [];
$title = bd_text('title', $cur['title'], 200, 'The title', $errors, true);
if ($title !== $cur['title']) {
    $f['title'] = $title;
}
$description = bd_text('description', $cur['description'], 2000, 'The description', $errors);
if ($description !== $cur['description']) {
    $f['description'] = $description ?? '';
}
$bg = $cur['background'] + ['pattern' => 'dots', 'color' => '#ffffff'];
$pattern = bd_choice('background', $bg['pattern'], array_keys(BOARD_PATTERNS), 'The background', $errors, false) ?? $bg['pattern'];
$color = bd_choice('background_color', $bg['color'], array_keys(BOARD_COLORS), 'The background colour', $errors, false) ?? $bg['color'];
if ($pattern !== $bg['pattern'] || $color !== $bg['color']) {
    $f['background'] = ['pattern' => $pattern, 'color' => $color];
}
/** The start view: "x y zoom" (an agent) or the form's three numbers; '' / none clears it. Absolute canvas units, zoom 0.05 to 8 (the island's range). */
$sv = null;
$svGiven = false;
if (req_has('start_view')) {
    $svGiven = true;
    $raw = strtolower(trim((string) req_val('start_view')));
    if ($raw !== '' && $raw !== 'none') {
        $parts = preg_split('/[\s,]+/', $raw);
        $sv = count($parts) === 3 ? $parts : false;
    }
} elseif (req_has('start_view_x') || req_has('start_view_y') || req_has('start_view_zoom')) {
    $svGiven = true;
    $parts = [trim((string) req_val('start_view_x')), trim((string) req_val('start_view_y')), trim((string) req_val('start_view_zoom'))];
    $sv = implode('', $parts) === '' ? null : $parts;
}
if ($svGiven) {
    if ($sv === false || ($sv !== null && (!is_numeric($sv[0]) || !is_numeric($sv[1]) || !is_numeric($sv[2]) || abs((float) $sv[0]) > 1e7 || abs((float) $sv[1]) > 1e7
        || (float) $sv[2] < 0.05 || (float) $sv[2] > 8))) {
        $errors['start_view'] = 'The start view is x, y and a zoom from 0.05 to 8 — or none.';
    } else {
        $new = $sv === null ? null : ['x' => round((float) $sv[0], 2), 'y' => round((float) $sv[1], 2), 'zoom' => round((float) $sv[2], 3)];
        if ($new != $cur['start_view']) {
            $f['start_view'] = $new;
        }
    }
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
if ($f !== []) {
    bd_guard($pdo, static function () use ($pdo, $id, $me, $f, $cur): void {
        $pdo->beginTransaction();
        board_update($pdo, $id, $me, $f);
        $before = [];
        $after = [];
        foreach ($f as $k => $v) {
            $old = $k === 'description' ? $cur['description'] : $cur[$k];
            $before[$k] = $k === 'description' ? ($old === null ? null : ['length' => mb_strlen((string) $old)]) : $old;
            $after[$k] = $k === 'description' ? ($v === '' ? null : ['length' => mb_strlen((string) $v)]) : $v;
        }
        log_activity($pdo, 'board.update', 'board', $id, ['board_id' => $id, 'before' => $before, 'after' => $after]);
        $pdo->commit();
    });
}
bd_done($f === [] ? 'Nothing to change' : 'Saved ' . ($f['title'] ?? $cur['title']), $id, bd_land(return_path('/boards/' . $id . '/settings'), $f === [] ? null : 'saved'), 'boardChanged',
    ['changed' => array_keys($f)]);
