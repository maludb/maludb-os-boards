<?php
declare(strict_types=1);
/**
 * Action `frame_create` (log item.frame — level edit; agents yes): **title** (≤ 120), around[] (item ids the frame wraps and takes in — a frame
 * among them is refused "Frames do not nest"; items in another frame leave it), x, y (its centre), width, height (default 1200 × 800). With
 * `around` the frame is their union + 40 on every side + 40 more on top for the title; without, at x/y, else in empty space below the board.
 * ONE batch: the frame's create, then each item's `update {parent_id, x, y}` (frame-relative). record_id = the seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/frames.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$title = bd_text('title', null, 120, 'The title', $errors, true);
if ($title === null && !isset($errors['title'])) {
    $errors['title'] = 'The title is required.';            // bd_text() judges an empty value; a missing one is the same refusal
}
$around = array_map('strtolower', request_list('around') ?? []);
if (count($around) > 500) {
    $errors['around'] = 'At most 500 items in one frame call.';
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
$w = $num('width', 100, 20000, 'The width');
$h = $num('height', 100, 20000, 'The height');
if (($x === null) !== ($y === null)) {
    $errors['x'] = 'Give x and y together.';
}
if ($around !== [] && $x !== null) {
    $errors['around'] = 'A frame around items takes its place from them: give around or x and y, not both.';
}
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$box = $x !== null ? [$x, $y, $w ?? FRAME_DEFAULT[0], $h ?? FRAME_DEFAULT[1]] : null;
$me = (int) current_member_id();
[$a, $f] = bd_guard($pdo, static function () use ($pdo, $id, $me, $title, $around, $box): array {
    $pdo->beginTransaction();
    $f = frame_ops($pdo, $id, (string) $title, $around, $box);
    $a = commit_ops($pdo, $id, $me, items_source(), null, items_batch_id(), $f['ops'], current_agent_run_id(), agent_run_request_id() ?? request_id());
    if (!empty($a['duplicate'])) {
        $f['id'] = batch_created_ids($pdo, $id, (int) $a['seq'])[0] ?? $f['id'];
    } else {
        log_activity($pdo, 'item.frame', 'board', $id, ['board_id' => $id, 'after' => ['seq' => (int) $a['seq'], 'frame' => $f['id'], 'title' => $title,
            'adopted' => $f['adopted'], 'box' => $a['summary']['bbox'] ?? null]]);
    }
    $pdo->commit();
    return [$a, $f];
});
bd_done('Made the frame ' . $title . ' ' . $f['where'], (int) $a['seq'], '/boards/' . $id . '?item=' . $f['id'], '', ['frame' => $f['id'], 'adopted' => $f['adopted'],
    'duplicate' => (bool) $a['duplicate']]);
