<?php
declare(strict_types=1);
/**
 * Action `item_create_batch` (log item.create — level edit; agents yes): **board**, **type** (sticky_note | shape | text), texts[] (one item
 * per text; none = one empty item; at most 50), frame (a frame's id), layout (grid | column | row — grid by default), columns (1–20), x, y (the
 * first item's centre — frame-relative with frame), color (a sticky colour or a #rrggbb fill), shape (Miro's ten), width, height (20–4000).
 * The server places the block (items_layout(): in the frame below what it holds, the frame growing; at x/y; else below the board) and
 * commits ONE batch through bd_commit_ops() — source agent / command_bar / web, the batch id from the request (a retried call commits once).
 * record_id = the batch's seq (what ops_undo takes back). The exemplar's handler order: begin → gate → fields → guard → log → done.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/write.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$type = bd_choice('type', null, ITEM_TYPES_DRAWN, 'The type', $errors, false);
if ($type === null && !isset($errors['type'])) {
    $errors['type'] = 'Say which type: sticky_note, shape or text.';
}
$raw = $_POST['texts'] ?? [];
$texts = is_array($raw) ? array_map(static fn ($t): string => trim((string) $t), $raw) : preg_split('/\r?\n/', trim((string) $raw));
$texts = array_values(array_filter($texts, static fn (string $t): bool => $t !== ''));
if (count($texts) > 50) {
    $errors['texts'] = 'At most 50 items in one call.';
}
foreach ($texts as $t) {
    if (mb_strlen($t) > 5000) {
        $errors['texts'] = 'An item\'s text is up to 5,000 characters.';
    }
}
$layout = bd_choice('layout', 'grid', ['grid', 'column', 'row'], 'The layout', $errors, false) ?? 'grid';
$columns = bd_int('columns', null, 1, 20, 'The columns', $errors, true);
$frame = req_val('frame');
$frame = $frame === null || $frame === '' || strtolower($frame) === 'none' ? null : strtolower($frame);
if ($frame !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $frame)) {
    $errors['frame'] = 'The frame is named by its id.';
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
    $errors['x'] = 'Give x and y together, or neither.';
}
$width = $num('width', 20, 4000, 'The width');
$height = $num('height', 20, 4000, 'The height');
$color = req_val('color');
if ($color !== null && $color !== '' && $type !== null && item_color($type, $color) === null) {
    $errors['color'] = $type === 'sticky_note' ? 'A sticky\'s colour is one of: ' . implode(', ', array_keys(STICKY_COLORS)) . '.' : 'The colour is #rrggbb or a sticky colour\'s name.';
}
$shape = bd_choice('shape', null, SHAPE_KINDS, 'The shape', $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$spec = ['type' => $type, 'texts' => $texts, 'layout' => $layout, 'frame' => $frame, 'color' => $color, 'shape' => $shape]
    + ($columns !== null ? ['columns' => $columns] : []) + ($x !== null ? ['x' => $x, 'y' => $y] : [])
    + ($width !== null ? ['width' => $width] : []) + ($height !== null ? ['height' => $height] : []);
$me = (int) current_member_id();
[$a, $placed] = bd_guard($pdo, static function () use ($pdo, $id, $me, $spec): array {
    $pdo->beginTransaction();
    $placed = items_layout($pdo, $id, $spec);
    $a = commit_ops($pdo, $id, $me, items_source(), null, items_batch_id(), $placed['ops'], current_agent_run_id(), agent_run_request_id() ?? request_id());
    if (!empty($a['duplicate'])) {
        $placed['ids'] = batch_created_ids($pdo, $id, (int) $a['seq']);
    } else {
        log_activity($pdo, 'item.create', 'board', $id, ['board_id' => $id, 'after' => batch_log_after('item.create', $a, $placed['ops'])
            + ['layout' => $spec['layout'], 'frame' => $spec['frame'], 'grown' => $placed['grown']]]);
    }
    $pdo->commit();
    return [$a, $placed];
});
$n = count($placed['ids']);
$did = 'Added ' . items_words($n, (string) $type) . ($n > 1 ? ' in a ' . ($layout === 'grid' ? 'grid' : $layout) : '') . ' ' . $placed['where']
    . ($placed['grown'] ? ' (the frame grew to hold them)' : '');
bd_done($did, (int) $a['seq'], '/boards/' . $id . '?item=' . $placed['ids'][0], '', ['items' => $placed['ids'], 'duplicate' => (bool) $a['duplicate'],
    'warnings' => $a['warnings'] ?? []]);
