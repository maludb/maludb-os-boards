<?php
declare(strict_types=1);
/**
 * Action `items_arrange` (log item.move — level edit; agents yes): **board**, **items[]** (item ids, their order is the layout's), **layout** (grid, column, row or cluster), frame (a frame's
 * id: arrange them inside it, the frame grown to hold them), columns (1–20, grid), gap (0–500, default 40). The items are moved, never changed otherwise; no frame and no connector
 * among them, none locked; with no frame they must share a parent. ONE batch through bd_commit_ops() (undo with ops_undo); record_id = the seq.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/canvas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/items/arrange.php';
bd_handler_begin();
$pdo = db();
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'edit');
$id = (string) $b['board_id'];
$errors = [];
$ids = array_map('strtolower', request_list('items') ?? []);
if ($ids === []) {
    $errors['items'] = 'Name the items to arrange, by id.';
} elseif (count($ids) > ARRANGE_MAX) {
    $errors['items'] = 'At most ' . ARRANGE_MAX . ' items in one call.';
}
$layout = bd_choice('layout', null, ARRANGE_LAYOUTS, 'The layout', $errors, false);
if ($layout === null && !isset($errors['layout'])) {
    $errors['layout'] = 'The layout is required: grid, column, row or cluster.';
}
$columns = bd_int('columns', null, 1, 20, 'The columns', $errors, true);
$gapText = req_val('gap');
$gap = ARRANGE_GAP;
if ($gapText !== null && $gapText !== '') {
    if (!is_numeric($gapText) || (float) $gapText < 0 || (float) $gapText > 500) {
        $errors['gap'] = 'The gap is a number from 0 to 500.';
    } else {
        $gap = (float) $gapText;
    }
}
$frame = req_val('frame');
$frame = $frame === null || $frame === '' ? null : $frame;
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$me = (int) current_member_id();
$a = bd_guard($pdo, static function () use ($pdo, $id, $me, $ids, $layout, $frame, $columns, $gap): array {
    $pdo->beginTransaction();
    $a = arrange_items($pdo, $id, $me, $ids, (string) $layout, $frame, $columns, $gap, items_source(), current_agent_run_id());
    if (empty($a['duplicate'])) {
        log_activity($pdo, 'item.move', 'board', $id, ['board_id' => $id, 'after' => ['seq' => (int) ($a['seq'] ?? 0), 'counts' => $a['summary']['counts'] ?? (object) ['update' => $a['arranged']],
            'box' => $a['summary']['bbox'] ?? null, 'layout' => $layout, 'frame' => $frame, 'items' => $a['arranged'], 'grown' => $a['grown']]]);
    }
    $pdo->commit();
    return $a;
});
bd_done('Arranged ' . items_words(count($ids), 'item') . ' as a ' . $layout . ($frame !== null ? ' in the frame' : ''), (int) ($a['seq'] ?? 0), '/boards/' . $id . '?item=' . $ids[0], '',
    ['items' => $ids, 'layout' => $layout, 'grown' => $a['grown'], 'duplicate' => (bool) ($a['duplicate'] ?? false)]);
