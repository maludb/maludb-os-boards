<?php
declare(strict_types=1);
/**
 * Action `item_update_batch` (log item.update — level edit; agents yes): **board**, **items[]** (ids), text (one item only), color (a sticky
 * colour for stickies, #rrggbb or a colour's name for shapes and text), x, y (one item only; absolute — or relative to `frame` when given),
 * width, height (20–4000), rotation (−359 to 359; never a frame), frame (a frame's id — or none for the canvas; the item keeps its place on
 * the canvas, its coordinates converted), front (yes = after the board's highest, no = before its lowest, the items keeping their order),
 * locked (yes or no). One update op per item, ONE batch through bd_commit_ops(); record_id = the seq. Decision 2: text, x and y need exactly
 * one item.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$ids = array_map('strtolower', request_list('items') ?? []);
if ($ids === []) {
    bd_refuse_fields(['items' => 'Name the items to change, by id.']);
}
if (count($ids) > 500) {
    bd_refuse_fields(['items' => 'At most 500 items in one call.']);
}
$rows = items_require_on_board($pdo, $id, $ids);
foreach (['text', 'x', 'y'] as $one) {
    if (req_has($one) && count($ids) > 1) {
        bd_refuse_fields([$one => 'Give one item to set its text or its place.']);
    }
}
$num = static function (string $name, float $min, float $max, string $label) use (&$errors): ?float {
    $v = req_val($name);
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
        $errors[$name] = $label . ' is a number from ' . $min . ' to ' . $max . '.';
        return null;
    }
    return (float) $v;
};
$x = $num('x', -1000000, 1000000, 'x');
$y = $num('y', -1000000, 1000000, 'y');
if (($x === null) !== ($y === null)) {
    $errors['x'] = 'Give x and y together.';
}
$width = $num('width', 20, 4000, 'The width');
$height = $num('height', 20, 4000, 'The height');
$rotation = $num('rotation', -359, 359, 'The rotation');
$text = req_has('text') ? str_replace("\r\n", "\n", (string) req_val('text')) : null;
if ($text !== null && mb_strlen($text) > 5000) {
    $errors['text'] = 'An item\'s text is up to 5,000 characters.';
}
$color = req_val('color');
$color = $color === '' ? null : $color;
$frameArg = req_val('frame');
$frame = null;
$toCanvas = false;
if ($frameArg !== null && $frameArg !== '') {
    if (strtolower($frameArg) === 'none') {
        $toCanvas = true;
    } else {
        $frame = one_row($pdo, "SELECT id, x, y, width, height FROM board_items WHERE id = CAST(:f AS uuid) AND board_id = CAST(:b AS uuid) AND type = 'frame' AND deleted_at IS NULL",
            ['f' => preg_match('/^[0-9a-f-]{36}$/', strtolower($frameArg)) ? strtolower($frameArg) : '00000000-0000-0000-0000-000000000000', 'b' => $id]);
        if ($frame === null) {
            $errors['frame'] = 'There is no frame ' . $frameArg . ' on this board.';
        }
    }
}
$front = req_has('front') ? bd_yes('front') : null;
$locked = req_has('locked') ? bd_yes('locked') : null;
if ($errors !== []) {
    bd_refuse_fields($errors);
}
if ($text === null && $x === null && $width === null && $height === null && $rotation === null && $color === null && $frameArg === null && $front === null && $locked === null) {
    bd_refuse_fields(['items' => 'Say what to change: text, color, x and y, width, height, rotation, frame, front or locked.']);
}
// z keys for front/back, the items keeping their order among themselves
$zOf = [];
if ($front !== null) {
    $order = q_items_by_z($pdo, $ids);
    if ($front) {
        $k = z_after($pdo, $id);
        foreach ($order as $iid) { $zOf[$iid] = $k; $k = z_next($k); }
    } else {
        $k = z_before($pdo, $id);
        foreach (array_reverse($order) as $iid) { $zOf[$iid] = $k; $k = z_between(null, $k); }
    }
}
$ops = [];
foreach ($ids as $iid) {
    $r = $rows[$iid];
    $f = [];
    if ($color !== null) {
        $patch = item_color((string) $r['type'], $color);
        if ($patch === null) {
            bd_refuse_fields(['color' => $r['type'] === 'sticky_note' ? 'A sticky\'s colour is one of: ' . implode(', ', array_keys(STICKY_COLORS)) . '.' : 'Colour fits stickies, shapes and text: #rrggbb or a colour\'s name.']);
        }
        $f['style'] = $patch;
    }
    if ($text !== null) {
        $f['data'] = ['content' => bd_rich_text_runs($text)];
    }
    if ($width !== null) { $f['width'] = $width; }
    if ($height !== null) { $f['height'] = $height; }
    if ($rotation !== null) { $f['rotation'] = $rotation; }
    if ($locked !== null) { $f['locked'] = $locked; }
    if (isset($zOf[$iid])) { $f['z'] = $zOf[$iid]; }
    // the place: the absolute centre (the box's centre — rotation keeps it), then relative to the frame it ends in
    $absX = ((float) $r['x1'] + (float) $r['x2']) / 2;
    $absY = ((float) $r['y1'] + (float) $r['y2']) / 2;
    $parent = $r['parent_id'];
    if ($frame !== null) { $parent = (string) $frame['id']; }
    if ($toCanvas) { $parent = null; }
    if ($x !== null) {
        // x/y given: relative to the frame named, else absolute on the canvas
        $absX = $frame !== null ? (float) $frame['x'] - (float) $frame['width'] / 2 + $x : $x;
        $absY = $frame !== null ? (float) $frame['y'] - (float) $frame['height'] / 2 + $y : $y;
    }
    if ($x !== null || $frameArg !== null) {
        $tl = [0.0, 0.0];
        if ($parent !== null) {
            $p = $frame !== null && (string) $frame['id'] === (string) $parent ? $frame
                : one_row($pdo, 'SELECT x, y, width, height FROM board_items WHERE id = CAST(:p AS uuid)', ['p' => $parent]);
            $tl = [(float) $p['x'] - (float) $p['width'] / 2, (float) $p['y'] - (float) $p['height'] / 2];
        }
        $f['x'] = round($absX - $tl[0], 2);
        $f['y'] = round($absY - $tl[1], 2);
        if ($parent !== $r['parent_id']) { $f['parent_id'] = $parent; }
    }
    $ops[] = ['op' => 'update', 'id' => $iid, 'fields' => $f];
}
$me = (int) current_member_id();
$a = bd_guard($pdo, static function () use ($pdo, $id, $me, $ops): array {
    $pdo->beginTransaction();
    $a = commit_ops($pdo, $id, $me, items_source(), null, items_batch_id(), $ops, current_agent_run_id(), agent_run_request_id() ?? request_id());
    if (empty($a['duplicate'])) {
        $names = array_values(array_unique(array_merge(...array_map(static fn ($o): array => array_keys($o['fields']), $ops))));
        log_activity($pdo, 'item.update', 'board', $id, ['board_id' => $id, 'after' => batch_log_after('item.update', $a, $ops) + ['fields' => $names]]);
    }
    $pdo->commit();
    return $a;
});
$what = array_values(array_filter([$text !== null ? 'text' : null, $color !== null ? 'colour' : null, $x !== null ? 'place' : null,
    ($width ?? $height) !== null ? 'size' : null, $rotation !== null ? 'rotation' : null, $frameArg !== null ? ($toCanvas ? 'onto the canvas' : 'into the frame') : null,
    $front === true ? 'to the front' : ($front === false ? 'to the back' : null), $locked === true ? 'locked' : ($locked === false ? 'unlocked' : null)]));
bd_done('Changed ' . items_words(count($ids), count(array_unique(array_column($rows, 'type'))) === 1 ? (string) reset($rows)['type'] : 'item') . ': ' . implode(', ', $what),
    (int) $a['seq'], '/boards/' . $id . '?item=' . $ids[0], '', ['items' => $ids, 'duplicate' => (bool) $a['duplicate']]);

/** The ids in their current z order (byte order — the column's collation). */
function q_items_by_z(PDO $pdo, array $ids): array
{
    $st = $pdo->prepare('SELECT id FROM board_items WHERE id = ANY (CAST(:ids AS uuid[])) ORDER BY z, id');
    $st->execute(['ids' => pg_array_literal($ids)]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}
